# Tests, Validation & Evaluation

Automated test suite for the posture-gated eduVPN PoC. It implements **every test
category that can be authored and executed automatically** in this environment, mapped
to the validation goals below.

> ℹ️ **Out of scope here (require people, not automation):** developer *expert
> interviews*, *usability studies*, and *manual penetration testing*. Everything else —
> functional, security-*assertable*, performance, scalability, and standards-conformance
> testing — is automated below.

## Coverage vs. the validation goals

| Category | Implemented | Suite(s) |
|---|---|---|
| **Functionality** | ✅ | `unit-php`, `unit-go`, `e2e` |
| **Security** (automatable parts) | ✅ | `e2e` (negative/mTLS/fail-closed), `unit-php` (fail-closed branches), `lint` (gitleaks, trivy) |
| **Performance** | ✅ | `perf` |
| **Scalability** | ✅ | `scale` |
| **Standards conformance / compatibility** | ✅ | `conformance` (canonical schema + eduVPN v3 contract) |
| Expert interviews, usability, manual pentest | ❌ (require people) | — |

## How to run

Host needs only Docker; every tool (PHP, Go, linters, python) runs in a container.
The orchestrator `tests/run-all.sh <target>` is the primary entry point — the `make`
targets below are thin wrappers, so **`make` itself is optional**:

```bash
./tests/run-all.sh fast      # unit (PHP+Go) + lint — no live stack, seconds
./tests/run-all.sh full      # everything (auto-runs ./up.sh if the stack is down)
# individual live tiers: ./tests/run-all.sh e2e | perf | scale | conf
```

Equivalent `make` shortcuts (only if `make` is installed):

```bash
make test          # fast tiers: unit (PHP+Go) + lint — no live stack, seconds
make test-unit     # PHP + Go unit tests only
make test-lint     # static analysis + secret scan

./up.sh            # bring up the stack first for the live-stack tiers
make test-e2e      # functional + security scenarios
make test-perf     # performance benchmark
make test-scale    # scalability sweep
make test-conf     # standards conformance / compatibility
make test-full     # everything (auto-runs ./up.sh if the stack is down)
```

Each suite is also a standalone script under `tests/<suite>/run.sh`; `run-all.sh` gates
the live-stack suites on the first-boot sentinels.

> **One stack per host.** The services use fixed `container_name`s, so only one checkout
> of this repo can run its stack at a time; the live tiers derive the compose
> network/volume names by suffix and assume a single match. Tear down one stack before
> bringing up another.

## Running the tests in CI

The **`fast` tier** (`unit-php` + `unit-go` + `lint`) needs nothing but a Docker daemon —
no live stack, no network beyond pulling the tool images — so it is the CI-suitable
target: `./tests/run-all.sh fast`. The live tiers (`e2e`, `perf`, `scale`, `conf`) need a
Docker **host** with the stack up (`./up.sh`) and are therefore a nightly/on-demand job,
not a per-commit one.

There is deliberately **no CI configuration committed** in this repository — the PoC is
run and evaluated manually. Wiring `run-all.sh fast` into a pipeline is a one-liner if a
runner is available.

## Known coverage gaps (not automated)

Beyond the people-only items above, these code paths have **no automated test** and are
exercised only manually / via the live stack:

- `setupWireGuard()` in `wireguard.go` has **no automated test at any tier** — it needs
  root and a real `wg` interface, so it is exercised by real use (`demo.sh`, a manual
  connect) rather than by the suite; the e2e tier deliberately stops at `--dry-run`.
- the client's interactive OAuth browser flow (`oauth.go` `oauthAuthorize`, incl. the
  `state`-mismatch rejection) — only its pure PKCE/helper functions are unit-tested;
- `main.go` is **not unit-tested**, but it *is* driven end-to-end: every one of the eight
  `tests/e2e/run.sh` scenarios invokes the real binary and asserts its exit code and
  output, so its flag handling and exit-code contract are covered — just not by `go test`.
- `PostureChecker::httpRequest()`, the real curl transport (incl. the TLS-verify-off
  branch taken when `wazuhCaCert` is empty) — replaced by a test double in `unit-php`, so
  covered only at `e2e` level, against the live Wazuh API.

The four provisioning scripts (`ca-server/ca-entrypoint.sh`, `eduvpn-server/first-boot.sh`,
`client/first-boot.sh`, `client/headless-oauth.sh`) are **shellchecked** by the `lint`
tier. Their *runtime* behaviour (machine-id persistence, cert re-issue on volume reuse,
sentinel idempotency) is still only exercised by bringing the stack up, not asserted.

## The suites

### `unit-php` — posture decision logic (no live stack)
Plain-PHP assert runner in `php:8.4-cli` (PHPUnit is not vendored). Exercises **every
decision branch** of `PostureChecker::check()` deterministically — invalid CN, no agent,
inactive agent, SCA-below-threshold, SCA-not-yet-scanned (fail-**open**), SCA-passing,
full pass with SCA disabled, and provider-error (fail-**closed**) — plus JWT caching,
stale-token (401) re-authentication, the SCA threshold boundary (`score == min` passes),
a multi-policy corpus, an agent record with no usable id, Wazuh rate-limiting (429 →
bounded retry, then fail-closed), and the `PostureCheckConfig` defaults. **92 assertions.**

> The overlay `PostureChecker` is intentionally non-`final` with a `protected`
> `httpRequest()` so tests can override the Wazuh transport with canned responses.
> The production code path is unchanged (real curl over HTTPS).

### `unit-go` — client CLI (no live stack)
`go test ./... -race -cover` in `golang:1.23` (stdlib-only, offline). Covers the PKCE S256
RFC 7636 vector, verifier/random string length+charset, `ensurePrivateKey`, the
`PostureRejectError` path, `getProfiles`/`connect`/`exchangeCode` via `httptest`, and a
**TLS-1.3-minimum guard** (a TLS-1.2-only server is rejected). **16 tests.**

`-race` is on because `oauth.go` coordinates the callback listener, a timeout and the main
goroutine over channels — a realistic defect class here. Coverage is **reported but not
gated** (currently ≈ 33 % of statements): there is deliberately **no coverage threshold**,
because the uncovered remainder is the root-requiring/browser-requiring code named under
[known coverage gaps](#known-coverage-gaps-not-automated), and a number chased for its own
sake would only reward low-value tests.

### `lint` — static analysis & secret scan (no live stack)
Containerized: `php -l`, `go vet`, `gofmt`, `shellcheck`, `hadolint` (report-only),
`gitleaks` (with `gitleaks-allowlist.toml` for the documented demo secrets, so only
*new* leaks fail), and `trivy` fs scan (report-only).

### `e2e` — functional + security scenarios (live stack)
Formalizes & extends `demo.sh`. Drives the client through `/v3/connect` (`--dry-run`)
and asserts exit code + reason for each scenario:

| Scenario | Expected |
|---|---|
| valid device + active agent | 200 PASSED |
| no client certificate | 403 `device certificate required` |
| invalid CN format (non-`device-*`) | 403 `invalid device certificate CN format` |
| unknown / unenrolled device | 403 `no Wazuh agent registered` |
| untrusted (self-signed) cert | mTLS reject → 403 |
| garbage OAuth token | 401 (auth enforced) |
| SCA below threshold (config override) | 403 `is below required minimum` |
| Wazuh manager stopped | 403 `posture service unavailable` (fail-closed) |

### `perf` — performance (live stack)
Concurrent driver (`python:3.12-slim` on the compose network) measuring the posture
decision via the reject path (ghost cert → 403, **no WireGuard peer created**), against
a `/v3/info` baseline. Reports p50/p95/p99 latency + throughput at concurrency
{1,8,32} plus an ungated saturation probe at 64.

Representative measurements (single-host demo stack):

| What | p50 |
|---|---|
| `/v3/info` baseline (auth only, no posture) | ~6 ms |
| posture decision, concurrency 1 | ~21 ms |
| posture decision, concurrency 8 | ~106 ms |
| posture decision, concurrency 32 | ~359 ms |

So the posture check costs roughly **15 ms** over the auth-only baseline — one Wazuh
`/agents` round-trip.

Two **real gates** — the driver exits non-zero and `run.sh` propagates it, so the tier can
actually fail:

| Gate | Condition |
|---|---|
| **Status distribution** | ≥ 95 % of the *N* requests must return the status the mode expects — `403` for `connect` (the posture decision), `200` for the `/v3/info` baseline. |
| **p99 latency** | p99 must stay below 5 s, the Wazuh curl-timeout ceiling. |

The status gate is the load-bearing one: without it a run in which every request failed
(TLS error, 401, Wazuh rate-limiting) still printed a tidy latency table, so the published
numbers were not known to describe the path being measured. A 403 alone is ambiguous — a
genuine posture denial and a fail-*closed* error both return 403 — so the driver
distinguishes them and reports the latter as `403-unavailable`.

#### Scalability ceiling — the posture gate saturates at ~300 decisions/minute

Wazuh applies a **global** `max_request_per_minute` (default 300) across its entire API.
The posture check spends one `/agents` call per `/v3/connect`, so **the whole system is
limited to roughly 300 posture decisions per minute regardless of concurrency**. Past
that, Wazuh answers HTTP 429, the gate fails *closed*, and compliant devices are denied
with `posture service unavailable`.

This is correct behaviour — denying is the right response to an unverifiable device — but
it is a real capacity limit of the design, so the suite measures it rather than hiding it:

- The gated sweep uses 100 requests per level with a pause between levels, keeping every
  measurement inside the provider's budget so the numbers are comparable.
- A final **saturation probe** (400 requests at concurrency 64) is *reported, not gated*.
  It typically shows ~300 genuine decisions and ~100 `403-unavailable` — i.e. the ceiling.

`PostureChecker` mitigates this with a cross-request JWT cache (`/tmp`, mode 0600), which
removes the *authenticate* call from the per-request path. Before that cache existed, every
`/v3/connect` authenticated afresh and the measured stack collapsed completely: at
concurrency 32, **0 of 200 requests reached the posture check at all**. Removing the
remaining `/agents` call would require caching agent state, which the PoC deliberately does
not do — see the point-in-time limitation in the root README.

### `scale` — scalability (live stack)
Bulk-registers synthetic Wazuh agents and re-measures posture p95 as the corpus grows
({+0, +100, +500}) — stressing Wazuh's `/agents?name=` lookup, the real scaling
variable. Synthetic agents (and only those, selected by id) are purged afterwards.

Representative finding — posture p50 against corpus size:

| Synthetic agents | p50 |
|---|---|
| +0 | ~106 ms |
| +100 | ~107 ms |
| +500 | ~105 ms |

Latency is flat: Wazuh's name lookup is indexed, so the corpus size is not the scaling
variable. The *request rate* is (see the ceiling above).

It reuses the `perf` driver, so **the same two gates apply to every measurement in the
sweep**. Note that bulk agent registration spends the same global rate-limit budget as
the measurements, so the tier pauses for the window to reset around each batch —
without that, the 500-agent registration left nothing for the measurement that followed
and every posture check fail-closed, which earlier versions of this suite reported as a
*latency* result.

### `conformance` — standards / compatibility (live stack)
Ties to `concept/universal-device-health-interface.md`. Runs in `python:3.12-slim` (pinned,
with `jsonschema==4.23.0` as the only dependency). Pulls **live** Wazuh
`/agents` + `/sca`, maps them onto the canonical **Device Health Record**
(`device-health-record.schema.json`), and validates — proving the concept's abstraction
covers real provider output. Also asserts the eduVPN **API-v3 discovery contract** the
Go client consumes.
