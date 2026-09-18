<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * eduVPN mTLS Posture Check Extension
 *
 * Queries the Wazuh Manager API to verify that the device identified by
 * the mTLS client certificate CN has an active agent and (optionally)
 * meets the minimum SCA compliance score.
 */

namespace Vpn\Portal;

use Vpn\Portal\Cfg\PostureCheckConfig;

final class PostureResult
{
    public function __construct(
        public readonly bool $passed,
        public readonly string $reason,
    ) {}
}

/**
 * Raised for a failed Wazuh API call. Carries the HTTP status code (0 for a
 * transport-level failure) so callers can react to a 401 (token expired).
 */
final class WazuhApiException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $statusCode = 0)
    {
        parent::__construct($message);
    }
}

/**
 * NOTE: this class is intentionally NOT `final`, and httpRequest() is `protected`
 * (not `private`), purely to enable unit testing: tests/unit-php overrides
 * httpRequest() in a subclass to return canned Wazuh responses, so every
 * decision branch of check() can be asserted deterministically without a live
 * Wazuh. The production code path is unchanged (real curl over HTTPS).
 */
class PostureChecker
{
    /** How many times to retry a Wazuh HTTP 429 before failing closed. */
    protected const RATE_LIMIT_RETRIES = 3;

    /** Base linear backoff between 429 retries, in milliseconds (n * base). */
    protected const RATE_LIMIT_BACKOFF_MS = 200;

    /** JWT reuse window; Wazuh's default token lifetime is 900s. */
    protected const TOKEN_TTL = 840;

    private ?string $cachedToken = null;
    private int $tokenExpiresAt = 0;

    public function __construct(
        private PostureCheckConfig $config,
        private LoggerInterface $logger,
    ) {}

    /**
     * Validate device posture via Wazuh.
     *
     * @param string $deviceCN the SSL_CLIENT_S_DN_CN value (e.g. "device-abc123")
     */
    public function check(string $deviceCN): PostureResult
    {
        // Validate CN format strictly: device-<machine-id>, where machine-id is
        // exactly 32 lowercase hex chars (systemd /etc/machine-id). Anything else
        // (spaces, quotes, newlines, other charsets) is rejected before it can
        // reach the Wazuh query or the log lines below. See client/first-boot.sh.
        //
        // The /D modifier is load-bearing: without it PCRE lets '$' match before a
        // final newline, so "device-<32 hex>\n" would be accepted and the newline
        // would reach the log lines below. Covered by PostureCheckerTest's
        // 'trailing newline after a VALID id' case.
        if (1 !== preg_match('/^device-[0-9a-f]{32}$/D', $deviceCN)) {
            return new PostureResult(false, 'invalid device certificate CN format');
        }

        $deviceId = $deviceCN; // Wazuh agent name matches full CN

        try {
            // Query agent status (with a one-shot re-auth if the cached token was
            // rejected — see authenticatedGet).
            $agentData = $this->authenticatedGet(
                '/agents?' . http_build_query(['name' => $deviceId, 'select' => 'status,id'])
            );

            $agents = $agentData['data']['affected_items'] ?? [];
            if (0 === \count($agents)) {
                $this->logger->error(\sprintf('posture: no Wazuh agent found for %s', $deviceId));

                return new PostureResult(false, \sprintf('no Wazuh agent registered for device "%s"', $deviceId));
            }

            $agentStatus = strtolower($agents[0]['status'] ?? '');
            $agentId = $agents[0]['id'] ?? '';

            if ('active' !== $agentStatus) {
                $this->logger->warning(\sprintf('posture: device %s agent status is "%s"', $deviceId, $agentStatus));

                return new PostureResult(false, \sprintf('Wazuh agent "%s" status is "%s" (expected "active")', $deviceId, $agentStatus));
            }

            // Optional SCA score check. When a minimum score is configured the
            // agent id is REQUIRED to fetch it — an absent or non-numeric id
            // must deny, not silently skip the check and pass. (Previously the
            // emptiness of $agentId was a precondition for running the check at
            // all, so a record without 'id' bypassed the whole SCA gate.)
            $scaMinScore = $this->config->scaMinScore();
            if ($scaMinScore > 0) {
                if (!\is_string($agentId) || !ctype_digit($agentId)) {
                    $this->logger->error(\sprintf('posture: device %s agent record has no usable id; cannot evaluate SCA', $deviceId));

                    return new PostureResult(false, 'posture service unavailable');
                }
                $scaResult = $this->checkSca($agentId, $deviceId, $scaMinScore);
                if (null !== $scaResult) {
                    return $scaResult;
                }
            }

            $this->logger->info(\sprintf('posture: device %s passed all checks', $deviceId));

            return new PostureResult(true, 'ok');
        } catch (\Throwable $e) {
            $this->logger->error(\sprintf('posture: check failed for %s: %s', $deviceId, $e->getMessage()));

            return new PostureResult(false, 'posture service unavailable');
        }
    }

    /**
     * Check SCA compliance score for the agent.
     * Returns null if the check passes, or a PostureResult if it fails.
     */
    private function checkSca(string $agentId, string $deviceId, int $minScore): ?PostureResult
    {
        $scaData = $this->authenticatedGet('/sca/' . $agentId);
        $policies = $scaData['data']['affected_items'] ?? [];

        if (0 === \count($policies)) {
            // No SCA scan completed yet — allow for freshly enrolled devices.
            // NOTE (fail-open): this also means an agent that NEVER runs an SCA
            // scan permanently satisfies scaMinScore. If you require a score,
            // ensure agents are actually configured to run SCA policies.
            $this->logger->info(\sprintf('posture: device %s has no SCA data yet, allowing', $deviceId));

            return null;
        }

        foreach ($policies as $policy) {
            $score = (int) ($policy['score'] ?? 0);
            $policyName = $policy['name'] ?? 'unknown';
            if ($score < $minScore) {
                $this->logger->warning(\sprintf('posture: device %s SCA policy "%s" score %d < %d', $deviceId, $policyName, $score, $minScore));

                return new PostureResult(false, \sprintf('SCA policy "%s" score %d is below required minimum %d', $policyName, $score, $minScore));
            }
        }

        return null;
    }

    /**
     * GET a Wazuh API path using the cached JWT, transparently recovering from
     * the two transient failures this deployment actually hits:
     *
     *  - HTTP 401: the cached token was rejected. Drop it — from BOTH the
     *    in-process copy and the shared on-disk cache — and retry once with a
     *    fresh one. Dropping the file matters most after a Wazuh manager restart,
     *    which regenerates the JWT signing key and so invalidates every token
     *    issued before it.
     *  - HTTP 429: Wazuh rate-limits /security/user/authenticate with a GLOBAL
     *    counter (max_request_per_minute, default 300). Because every
     *    /v3/connect performs a fresh authenticate, a modest burst of
     *    connections trips it — and without this retry a 429 propagates to the
     *    catch-all in check() and denies COMPLIANT devices with "posture
     *    service unavailable". Retry with a short linear backoff.
     *
     * @return array<mixed>
     */
    private function authenticatedGet(string $path): array
    {
        $reauthed = false;
        for ($attempt = 0; ; ++$attempt) {
            try {
                return $this->wazuhGet($path, $this->getToken());
            } catch (WazuhApiException $e) {
                if (401 === $e->statusCode && !$reauthed) {
                    // Cached token rejected — drop it and retry once with a fresh
                    // one. The FILE cache must be dropped too, not just the
                    // in-process copy: Wazuh regenerates its JWT signing key when
                    // the manager restarts, which invalidates every previously
                    // issued token. Leaving the file in place made getToken() read
                    // the same dead token straight back, so the retry could never
                    // succeed and the gate stayed broken for the whole cache TTL.
                    $reauthed = true;
                    $this->invalidateToken();

                    continue;
                }
                if (429 === $e->statusCode && $attempt < self::RATE_LIMIT_RETRIES) {
                    $this->logger->warning(\sprintf('posture: Wazuh rate-limited (429), retry %d of %d', $attempt + 1, self::RATE_LIMIT_RETRIES));
                    // Deliberately do NOT drop the cached token here: a 429 means
                    // "too many requests", not "bad token", and discarding it would
                    // force an extra authenticate — spending more of the very budget
                    // we just exhausted.
                    usleep((int) (self::RATE_LIMIT_BACKOFF_MS * 1000 * ($attempt + 1)));

                    continue;
                }

                throw $e;
            }
        }
    }

    /**
     * Obtain or reuse a cached JWT token from the Wazuh API.
     */
    private function getToken(): string
    {
        if (null !== $this->cachedToken && time() < $this->tokenExpiresAt) {
            return $this->cachedToken;
        }

        // Cross-request cache. web/api.php builds a NEW PostureChecker for every
        // FPM request, so the in-process cache above never survives a request and
        // every /v3/connect would otherwise spend a fresh Wazuh authenticate.
        // Wazuh rate-limits that endpoint with a GLOBAL counter
        // (max_request_per_minute, default 300), so under load the authenticates
        // alone exhaust it and COMPLIANT devices get denied with "posture service
        // unavailable". Measured before this cache existed: at concurrency 32 and
        // 64, 0 of 200 requests reached the posture check at all.
        //
        // The cached value is a ~15-minute bearer token, written 0600. The same
        // process already reads the Wazuh username and password in cleartext from
        // config.php, so this does not widen what an attacker with that uid can
        // reach. Failures to read or write the cache are non-fatal — we simply
        // authenticate again.
        if (null !== $token = $this->readTokenCache()) {
            $this->cachedToken = $token;
            $this->tokenExpiresAt = time() + self::TOKEN_TTL;

            return $token;
        }

        $url = rtrim($this->config->wazuhApiUrl(), '/') . '/security/user/authenticate';
        $credentials = base64_encode($this->config->wazuhUser() . ':' . $this->config->wazuhPass());

        $responseBody = $this->httpRequest('GET', $url, [
            'Authorization: Basic ' . $credentials,
        ]);

        $data = json_decode($responseBody, true, 512, \JSON_THROW_ON_ERROR);
        $this->cachedToken = $data['data']['token'] ?? throw new \RuntimeException('no token in Wazuh auth response');
        // Cache for 14 minutes (Wazuh default token lifetime is 900s = 15min)
        $this->tokenExpiresAt = time() + self::TOKEN_TTL;
        $this->writeTokenCache($this->cachedToken);

        return $this->cachedToken;
    }

    /**
     * Path of the cross-request token cache, or null if caching is disabled.
     * Keyed by API URL + user so two configurations never share a token.
     */
    protected function tokenCachePath(): ?string
    {
        $dir = sys_get_temp_dir();
        if (!is_dir($dir) || !is_writable($dir)) {
            return null;
        }
        $key = hash('sha256', $this->config->wazuhApiUrl() . "\0" . $this->config->wazuhUser());

        return $dir . '/vpn-posture-token-' . $key;
    }

    /** @return string|null the cached token if present and still fresh */
    private function readTokenCache(): ?string
    {
        $path = $this->tokenCachePath();
        if (null === $path || !is_file($path)) {
            return null;
        }
        // Trust our own mtime rather than parsing the JWT: we wrote it, and the
        // 401 retry in authenticatedGet() covers the case where it is stale anyway.
        $age = time() - (int) @filemtime($path);
        if ($age < 0 || $age >= self::TOKEN_TTL) {
            return null;
        }
        $token = @file_get_contents($path);

        return \is_string($token) && '' !== $token ? $token : null;
    }

    /** Drop both the in-process and the on-disk token. */
    private function invalidateToken(): void
    {
        $this->cachedToken = null;
        $this->tokenExpiresAt = 0;
        $path = $this->tokenCachePath();
        if (null !== $path) {
            @unlink($path);
        }
    }

    private function writeTokenCache(string $token): void
    {
        $path = $this->tokenCachePath();
        if (null === $path) {
            return;
        }
        // Write-then-rename so a concurrent reader never sees a partial token,
        // and create the temp file 0600 BEFORE it holds the credential.
        $tmp = $path . '.' . bin2hex(random_bytes(6));
        if (false === @file_put_contents($tmp, $token, \LOCK_EX)) {
            return;
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
        }
    }

    /**
     * @return array<mixed>
     */
    private function wazuhGet(string $path, string $token): array
    {
        $url = rtrim($this->config->wazuhApiUrl(), '/') . $path;
        $responseBody = $this->httpRequest('GET', $url, [
            'Authorization: Bearer ' . $token,
        ]);

        return json_decode($responseBody, true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string> $headers
     *
     * `protected` (not private) so unit tests can override the transport with
     * canned responses — see the class-level note. Production uses real curl.
     */
    protected function httpRequest(string $method, string $url, array $headers): string
    {
        $ch = curl_init();
        if (false === $ch) {
            throw new \RuntimeException('curl_init failed');
        }

        curl_setopt($ch, \CURLOPT_URL, $url);
        curl_setopt($ch, \CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, \CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, \CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, \CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, \CURLOPT_PROTOCOLS, \CURLPROTO_HTTPS);

        if ('GET' !== $method) {
            curl_setopt($ch, \CURLOPT_CUSTOMREQUEST, $method);
        }

        $caCert = $this->config->wazuhCaCert();
        if ('' !== $caCert) {
            curl_setopt($ch, \CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, \CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, \CURLOPT_CAINFO, $caCert);
        } else {
            // Internal network — skip Wazuh TLS verification
            curl_setopt($ch, \CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, \CURLOPT_SSL_VERIFYHOST, 0);
        }

        $responseData = curl_exec($ch);
        if (false === $responseData || !\is_string($responseData)) {
            $error = curl_error($ch);
            curl_close($ch);

            throw new WazuhApiException(\sprintf('Wazuh API request failed: %s', $error));
        }

        $httpCode = (int) curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new WazuhApiException(\sprintf('Wazuh API returned HTTP %d', $httpCode), $httpCode);
        }

        return $responseData;
    }
}
