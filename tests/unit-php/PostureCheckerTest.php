<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

/*
 * Unit tests for Vpn\Portal\PostureChecker — every decision branch of check(),
 * driven deterministically by overriding the (now protected) httpRequest()
 * transport with canned Wazuh responses. No live Wazuh, no sockets, no TLS.
 */

use Vpn\Portal\Cfg\PostureCheckConfig;
use Vpn\Portal\NullLogger;
use Vpn\Portal\PostureChecker;
use Vpn\Portal\WazuhApiException;

// A realistic device CN: device- + 32 lowercase hex (systemd machine-id).
const VALID_CN = 'device-0123456789abcdef0123456789abcdef';

/** Test double: returns canned JSON per Wazuh endpoint; counts auth calls. */
final class FakePostureChecker extends PostureChecker
{
    /** @var array<string,string> keyed 'auth'|'agents'|'sca' */
    public array $responses = [];
    public int $authCalls = 0;
    public int $agentsCalls = 0;
    public bool $throwOnAgents = false;
    /** When true, the FIRST /agents call raises a 401 (stale token). */
    public bool $http401OnFirstAgents = false;
    /** Number of leading /agents calls that raise a 429 (rate limited). */
    public int $http429OnFirstAgents = 0;

    /** No real sleeping in unit tests. */
    protected const RATE_LIMIT_BACKOFF_MS = 0;

    /** When set, use this cache path; when null (default), caching is OFF. */
    public ?string $cachePath = null;

    /**
     * Disabled by default so the cross-request token cache cannot leak between
     * test cases and make authCalls unpredictable. The cache itself is covered
     * explicitly in section 16.
     */
    protected function tokenCachePath(): ?string
    {
        return $this->cachePath;
    }

    protected function httpRequest(string $method, string $url, array $headers): string
    {
        if (str_contains($url, '/security/user/authenticate')) {
            $this->authCalls++;

            return $this->responses['auth'] ?? '{"data":{"token":"faketoken"}}';
        }
        if (str_contains($url, '/sca/')) {
            return $this->responses['sca'] ?? '{"data":{"affected_items":[]}}';
        }
        if (str_contains($url, '/agents')) {
            $this->agentsCalls++;
            if ($this->throwOnAgents) {
                throw new WazuhApiException('Wazuh API request failed: simulated outage');
            }
            if ($this->http401OnFirstAgents && 1 === $this->agentsCalls) {
                throw new WazuhApiException('Wazuh API returned HTTP 401', 401);
            }
            if ($this->agentsCalls <= $this->http429OnFirstAgents) {
                throw new WazuhApiException('Wazuh API returned HTTP 429', 429);
            }

            return $this->responses['agents'] ?? '{"data":{"affected_items":[]}}';
        }

        throw new \RuntimeException('unexpected url in test: ' . $url);
    }
}

/** @param array<string,mixed> $overrides */
function makeChecker(array $overrides = []): FakePostureChecker
{
    $cfg = new PostureCheckConfig(array_merge([
        'wazuhApiUrl' => 'https://wazuh.test:55000',
        'wazuhUser' => 'wazuh-wui',
        'wazuhPass' => 'secret',
        'scaMinScore' => 0,
    ], $overrides));

    return new FakePostureChecker($cfg, new NullLogger());
}

function agents(string $status, string $id = '001'): string
{
    return json_encode(['data' => ['affected_items' => [['status' => $status, 'id' => $id]]]]);
}

// --- 1. invalid CN format (pure, no I/O) ------------------------------------
// Strict format is device-<32 lowercase hex>; every other shape is rejected
// before any Wazuh query, closing log-injection / crafted-name concerns.
foreach ([
    'laptop-42'                                  => 'wrong prefix',
    'device-abc'                                 => 'too short',
    'device-0123456789ABCDEF0123456789abcdef'    => 'uppercase hex',
    'device-0123456789abcdef0123456789abcde'     => '31 chars',
    'device-0123456789abcdef0123456789abcdef0'   => '33 chars',
    // The regression the /D modifier guards: a VALID 32-hex id with a trailing
    // newline. Without /D, PCRE's '$' matches before a final newline and this
    // is ACCEPTED — the newline then reaches the Wazuh query and the log lines.
    // (The previous fixture used a 31-hex id, so it was rejected on length and
    // proved nothing about newline handling.)
    VALID_CN . "\n"                              => 'trailing newline after a VALID id',
    "\n" . VALID_CN                              => 'leading newline before a VALID id',
    VALID_CN . ' '                               => 'trailing space',
    ' ' . VALID_CN                               => 'leading space',
    VALID_CN . "\x00"                            => 'trailing NUL byte',
    'device-0123456789abcdef0123456789abcde\u{0661}' => 'non-ASCII digit',
    'device-../../etc/passwd'                    => 'path traversal chars',
    'device-0123456789abcdef0123456789abcdef extra' => 'valid id plus trailing text',
] as $badCn => $why) {
    // Reuse ONE checker per case so we can assert the CN was rejected WITHOUT
    // any Wazuh traffic — the security property the comment above claims.
    $c = makeChecker();
    $r = $c->check($badCn);
    T::eq(false, $r->passed, "invalid CN ($why): passed=false");
    T::eq('invalid device certificate CN format', $r->reason, "invalid CN ($why): reason");
    T::eq(0, $c->agentsCalls, "invalid CN ($why): no Wazuh /agents query");
    T::eq(0, $c->authCalls, "invalid CN ($why): no Wazuh auth call");
}

// --- 2. no agent registered --------------------------------------------------
$c = makeChecker();
$c->responses['agents'] = '{"data":{"affected_items":[]}}';
$r = $c->check(VALID_CN);
T::eq(false, $r->passed, 'no agent: passed=false');
T::eq('no Wazuh agent registered for device "' . VALID_CN . '"', $r->reason, 'no agent: reason');

// --- 3. agent inactive -------------------------------------------------------
$c = makeChecker();
$c->responses['agents'] = agents('disconnected');
$r = $c->check(VALID_CN);
T::eq(false, $r->passed, 'inactive: passed=false');
T::ok(str_contains($r->reason, 'status is "disconnected" (expected "active")'), 'inactive: reason mentions status');

// --- 4. SCA below threshold --------------------------------------------------
$c = makeChecker(['scaMinScore' => 70]);
$c->responses['agents'] = agents('active');
$c->responses['sca'] = json_encode(['data' => ['affected_items' => [['score' => 39, 'name' => 'CIS Debian 12']]]]);
$r = $c->check(VALID_CN);
T::eq(false, $r->passed, 'SCA below: passed=false');
T::eq('SCA policy "CIS Debian 12" score 39 is below required minimum 70', $r->reason, 'SCA below: reason');

// --- 5. SCA enabled but no scan yet → fail-OPEN (allow) ----------------------
$c = makeChecker(['scaMinScore' => 70]);
$c->responses['agents'] = agents('active');
$c->responses['sca'] = '{"data":{"affected_items":[]}}';
$r = $c->check(VALID_CN);
T::eq(true, $r->passed, 'SCA no-data: passed=true (fail-open)');
T::eq('ok', $r->reason, 'SCA no-data: reason ok');

// --- 6. SCA enabled and passing ---------------------------------------------
$c = makeChecker(['scaMinScore' => 70]);
$c->responses['agents'] = agents('active');
$c->responses['sca'] = json_encode(['data' => ['affected_items' => [['score' => 82, 'name' => 'CIS Debian 12']]]]);
$r = $c->check(VALID_CN);
T::eq(true, $r->passed, 'SCA passing: passed=true');

// --- 7. full pass, SCA disabled (scaMinScore=0) ------------------------------
$c = makeChecker(['scaMinScore' => 0]);
$c->responses['agents'] = agents('active');
$r = $c->check(VALID_CN);
T::eq(true, $r->passed, 'full pass: passed=true');
T::eq('ok', $r->reason, 'full pass: reason ok');

// --- 8. provider error → fail-CLOSED ----------------------------------------
$c = makeChecker();
$c->throwOnAgents = true;
$r = $c->check(VALID_CN);
T::eq(false, $r->passed, 'provider error: passed=false (fail-closed)');
T::eq('posture service unavailable', $r->reason, 'provider error: reason');

// --- 9. JWT token is cached across check() calls -----------------------------
$c = makeChecker();
$c->responses['agents'] = agents('active');
$c->check(VALID_CN);
$c->check(VALID_CN);
T::eq(1, $c->authCalls, 'JWT cache: two checks → one /authenticate call');

// --- 10. stale token (HTTP 401) → re-authenticate once and succeed ----------
$c = makeChecker();
$c->responses['agents'] = agents('active');
$c->http401OnFirstAgents = true;
$r = $c->check(VALID_CN);
T::eq(true, $r->passed, '401 retry: passed=true after re-auth');
T::eq(2, $c->authCalls, '401 retry: authenticated exactly twice');

// --- 11. SCA: any policy below the threshold denies --------------------------
// The loop must inspect EVERY policy, not just the first. With only one policy
// in every fixture, a checker that looked at $policies[0] alone would pass.
$c = makeChecker(['scaMinScore' => 70]);
$c->responses['agents'] = agents('active');
$c->responses['sca'] = json_encode(['data' => ['affected_items' => [
    ['score' => 95, 'name' => 'CIS Debian 12'],
    ['score' => 41, 'name' => 'Second Policy'],
]]]);
$r = $c->check(VALID_CN);
T::eq(false, $r->passed, 'SCA multi-policy: a later failing policy denies');
T::eq('SCA policy "Second Policy" score 41 is below required minimum 70', $r->reason, 'SCA multi-policy: names the failing policy');

// --- 12. SCA: the threshold boundary, pinned from both sides -----------------
// Both cases are needed. score == minimum catches a '<' -> '<=' flip; score ==
// minimum-1 catches an off-by-one the other way. With only the first, a mutant
// reading `$score < $minScore - 1` passes the whole suite.
$c = makeChecker(['scaMinScore' => 70]);
$c->responses['agents'] = agents('active');
$c->responses['sca'] = json_encode(['data' => ['affected_items' => [['score' => 70, 'name' => 'CIS Debian 12']]]]);
$r = $c->check(VALID_CN);
T::eq(true, $r->passed, 'SCA boundary: score == minimum passes');

$c = makeChecker(['scaMinScore' => 70]);
$c->responses['agents'] = agents('active');
$c->responses['sca'] = json_encode(['data' => ['affected_items' => [['score' => 69, 'name' => 'CIS Debian 12']]]]);
$r = $c->check(VALID_CN);
T::eq(false, $r->passed, 'SCA boundary: score == minimum-1 denies');
T::eq('SCA policy "CIS Debian 12" score 69 is below required minimum 70', $r->reason, 'SCA boundary: minimum-1 reason');

// --- 13. SCA enabled but the agent record carries no usable id → DENY --------
// Regression guard: the id is required to fetch the SCA result, so an absent or
// non-numeric id must fail closed rather than silently skipping the whole gate.
foreach (['' => 'empty id', 'abc' => 'non-numeric id'] as $badId => $why) {
    $c = makeChecker(['scaMinScore' => 70]);
    $c->responses['agents'] = json_encode(['data' => ['affected_items' => [['status' => 'active', 'id' => $badId]]]]);
    $r = $c->check(VALID_CN);
    T::eq(false, $r->passed, "SCA unusable agent id ($why): passed=false");
    T::eq('posture service unavailable', $r->reason, "SCA unusable agent id ($why): fail-closed reason");
}

// --- 14. an agent record with no usable id still passes when SCA is OFF ------
// scaMinScore=0 is the shipped default; the id is only needed for SCA, so this
// must NOT regress into a spurious denial.
$c = makeChecker(['scaMinScore' => 0]);
$c->responses['agents'] = json_encode(['data' => ['affected_items' => [['status' => 'active']]]]);
$r = $c->check(VALID_CN);
T::eq(true, $r->passed, 'no agent id but SCA disabled: passed=true');

// --- 15. Wazuh rate limiting (HTTP 429) must NOT deny a compliant device -----
// Wazuh's max_request_per_minute is a GLOBAL counter and every /v3/connect
// performs a fresh authenticate, so a burst of connections trips it. Before the
// retry existed, 429 fell through to the catch-all and compliant devices were
// told "posture service unavailable" — on the live demo stack this was ~87% of
// all posture decisions.
$c = makeChecker();
$c->responses['agents'] = agents('active');
$c->http429OnFirstAgents = 2;   // fails twice, succeeds on the third attempt
$r = $c->check(VALID_CN);
T::eq(true, $r->passed, '429 retry: transient rate limit does not deny a compliant device');
T::eq(3, $c->agentsCalls, '429 retry: retried until success');

// A PERSISTENT 429 must still fail closed rather than loop forever.
$c = makeChecker();
$c->responses['agents'] = agents('active');
$c->http429OnFirstAgents = 99;
$r = $c->check(VALID_CN);
T::eq(false, $r->passed, '429 persistent: fails closed');
T::eq('posture service unavailable', $r->reason, '429 persistent: fail-closed reason');
T::eq(4, $c->agentsCalls, '429 persistent: bounded at 1 + RATE_LIMIT_RETRIES attempts');

// --- 16. cross-request JWT cache --------------------------------------------
// web/api.php builds a NEW PostureChecker per FPM request, so without a shared
// cache every /v3/connect spends a Wazuh authenticate. Wazuh rate-limits that
// endpoint with a global counter, and under load the authenticates alone
// exhausted it: measured at concurrency 32, 0 of 200 requests reached the
// posture check — compliant devices were denied "posture service unavailable".
$cacheFile = sys_get_temp_dir() . '/posture-test-token-' . bin2hex(random_bytes(6));
@unlink($cacheFile);

// First checker authenticates and populates the cache.
$c1 = makeChecker();
$c1->cachePath = $cacheFile;
$c1->responses['agents'] = agents('active');
$r = $c1->check(VALID_CN);
T::eq(true, $r->passed, 'token cache: first checker passes');
T::eq(1, $c1->authCalls, 'token cache: first checker authenticates once');
T::ok(is_file($cacheFile), 'token cache: cache file was written');
T::eq('0600', substr(sprintf('%o', fileperms($cacheFile)), -4), 'token cache: file is 0600');

// A SEPARATE checker (i.e. a separate request) reuses it — no new authenticate.
$c2 = makeChecker();
$c2->cachePath = $cacheFile;
$c2->responses['agents'] = agents('active');
$r = $c2->check(VALID_CN);
T::eq(true, $r->passed, 'token cache: second checker passes');
T::eq(0, $c2->authCalls, 'token cache: second checker reuses the cached token (no re-auth)');

// A stale cache file must be ignored rather than used forever.
touch($cacheFile, time() - 3600);
$c3 = makeChecker();
$c3->cachePath = $cacheFile;
$c3->responses['agents'] = agents('active');
$c3->check(VALID_CN);
T::eq(1, $c3->authCalls, 'token cache: expired cache triggers a fresh authenticate');

// Caching disabled (unwritable/absent path) must still work, just without reuse.
$c4 = makeChecker();
$c4->cachePath = null;
$c4->responses['agents'] = agents('active');
T::eq(true, $c4->check(VALID_CN)->passed, 'token cache: disabled cache still works');
T::eq(1, $c4->authCalls, 'token cache: disabled cache authenticates per checker');
@unlink($cacheFile);

// --- 17. a 401 must invalidate the SHARED cache, not just the in-process copy --
// Wazuh regenerates its JWT signing key when the manager restarts, invalidating
// every previously issued token. If the 401 retry only cleared the in-process
// copy, getToken() read the same dead token straight back out of the file, the
// retry could never succeed, and the gate stayed broken for the whole cache TTL —
// which is exactly what a live full-suite run turned up.
$cacheFile = sys_get_temp_dir() . '/posture-test-stale-' . bin2hex(random_bytes(6));
file_put_contents($cacheFile, 'stale-token-from-before-the-restart');

$c = makeChecker();
$c->cachePath = $cacheFile;
$c->responses['agents'] = agents('active');
$c->http401OnFirstAgents = true;   // the stale token is rejected once
$r = $c->check(VALID_CN);
T::eq(true, $r->passed, 'stale shared cache: recovers after re-auth');
T::eq(1, $c->authCalls, 'stale shared cache: authenticated once (the stale token was dropped)');
T::eq('faketoken', file_get_contents($cacheFile), 'stale shared cache: file now holds the FRESH token');
@unlink($cacheFile);
