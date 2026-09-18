# eduVPNextension — Zero-Trust device posture gating for stock eduVPN

A proof-of-concept that adds **device-posture gating** to an unmodified-in-spirit
eduVPN v3 server: a VPN configuration is issued from `/v3/connect` **only if** the
connecting device both

1. presents a valid **X.509 device certificate** (`CN = device-<machine-id>`,
   issued by a [step-ca](https://smallstep.com/docs/step-ca/) CA and verified by
   Apache mTLS), **and**
2. has a **Wazuh agent** of the same name reporting **`active`** (optionally meeting
   a minimum SCA score).

The check is **fail-closed** and — importantly — it is a **native integration**,
not a proxy. The posture logic runs *inside* eduVPN's own `vpn-user-portal` request
path, at the top of the `/v3/connect` handler in `src/Http/VpnApiThreeModule.php`,
reading the client-cert CN from Apache's mTLS environment. There is no extra hop in
front of eduVPN.

> **Scope of the gate.** `/v3/connect` is the API endpoint the native eduVPN clients
> use, and it is the path this PoC gates. eduVPN's portal has a *second*, older way
> to obtain a configuration — the browser "download a configuration" form, which
> calls `ConnectionManager::connect()` directly and is **not** posture-gated. The
> shipped stack therefore sets `'maxActiveConfigurations' => 0`, which disables that
> form, so the gate cannot be walked around in the demo. If you port this patch to
> your own server, **you must set that too** — otherwise anyone who can log in to the
> portal can download a working tunnel with no device certificate at all. Gating the
> browser path itself is out of scope here: a browser will not present the device
> certificate to the portal UI, so it needs a different mechanism.

---

## Project status

This is a **research proof-of-concept**, published to demonstrate the approach and
accompany the [universal Device Health interface concept](concept/universal-device-health-interface.md).
It is **not production software**: it ships committed demo secrets, runs privileged
containers, and cuts scope in the places called out under
[Security note](#security-note--demo-secrets) below. Provided **as-is, without warranty**
(see [`LICENSE`](LICENSE)); there is no support commitment. To report a security issue,
see [`SECURITY.md`](SECURITY.md).

---

## One-command quickstart

**Prerequisites**

- Docker Engine + Compose plugin (Docker CE).
- ~**8 GB RAM** free (the Wazuh indexer JVM alone is capped at 1 GB).
- A cgroup-v2 host (the eduVPN server and client run systemd in-container via
  `cgroup: host`).
- The Wazuh indexer (OpenSearch) needs the host sysctl `vm.max_map_count` ≥ 262144,
  or it fails to start:
  ```
  sudo sysctl -w vm.max_map_count=262144        # add to /etc/sysctl.conf to persist
  ```
- **Only to reach the portal from a browser on the host**, map `vpn.local` to
  loopback — `./demo.sh` does **not** need this (it runs inside the client
  container, where `vpn.local` resolves on the Docker network):
  ```
  echo '127.0.0.1  vpn.local' | sudo tee -a /etc/hosts
  ```

**Bring it up and run the demo**

```bash
./up.sh        # generates Wazuh TLS certs (first run), builds + starts everything
               # first boot takes a few minutes (real eduVPN install + Wazuh)
./demo.sh      # drives the posture gate through four scenarios (see below)
./down.sh      # stop (keep data)     |   ./down.sh --clean  (also wipe volumes)
```

`up.sh` is the single command; `make up` / `make down` / `make clean` wrap the same
scripts. `make` on its own prints the available targets.

**Just want to check it holds together?** The unit and lint tiers need only Docker —
no stack, a couple of minutes:

```bash
./tests/run-all.sh fast        # or: make test
```

`./tests/run-all.sh full` additionally runs the live-stack suites (end-to-end
scenarios, performance, scalability, standards conformance), bringing the stack up
first if needed. See [`tests/README.md`](tests/README.md) for what each tier covers
and what is deliberately not covered.

**What you get**

| Endpoint | URL |
|---|---|
| eduVPN portal | `https://vpn.local/` |
| Wazuh dashboard | `https://localhost:8443/` |
| step-ca API | `https://localhost:9000/` |

---

## What the demo proves

`./demo.sh` obtains an OAuth token headlessly and drives the client through
`/v3/connect` for each case (using `--dry-run`, so it stops at the posture decision
rather than bringing up the best-effort in-container tunnel):

| Scenario | Expected result |
|---|---|
| Valid device cert **+** active agent | **200** — WireGuard config issued, *POSTURE CHECK PASSED* |
| No device certificate | **403** — `device certificate required` |
| Valid cert, device not enrolled in Wazuh | **403** — `no Wazuh agent registered for device "…"` |
| Wazuh manager unreachable | **403** — `posture service unavailable` (fail-closed) |

Enrolled-but-inactive agents follow the same fail-closed pattern. The optional
SCA-score threshold (`scaMinScore`) is enforced only once the agent has an SCA scan:
a below-threshold score is rejected, but a **freshly-enrolled device with no scan yet
is allowed** (fail-*open*, so new devices aren't locked out) — a deliberate exception
to the otherwise fail-closed gate.

`demo.sh` is the narrative walkthrough, not the full proof. **`./tests/run-all.sh e2e`
covers eight scenarios** — the four above plus an invalid-CN certificate, an untrusted
self-signed certificate, a garbage OAuth token, and an SCA score below the threshold.
The inactive-agent and no-SCA-scan branches above are proven deterministically in the
PHP unit suite rather than against the live stack.

---

## Architecture

```
                       ┌──────────────────────────── eduvpn-net (docker bridge) ───────────────────────────┐
                       │                                                                                    │
   ┌───────────────┐   │   pki volume        ┌─────────────────────────────┐        ┌──────────────────┐   │
   │  ca-server    │───┼──▶ root_ca.crt  ────▶│  eduvpn-server (vpn.local)  │        │  wazuh.manager   │   │
   │  step-ca :9000│   │   ca-bundle.crt  ┌───│  Apache mTLS  +  php-fpm     │        │  API :55000      │   │
   │  provisioners:│   │   fingerprint    │   │  vpn-user-portal (patched)  │        │  wazuh.indexer   │   │
   │  admin,       │   │  (read-only)     │   │   └ PostureChecker ─────────┼───────▶│  wazuh.dashboard │   │
   │  device-certs,│   │                  │   │      queries agent status   │  API   │   (host :8443)   │   │
   │  acme         │   │                  │   └──────────────▲──────────────┘        └────────▲─────────┘   │
   └───────────────┘   │                  │                  │ mTLS + Bearer                  │ agent 1514  │
                       │                  │ device cert      │ POST /v3/connect               │             │
                       │        ┌─────────┴────────┐         │                                │             │
                       │        │  eduvpn-client   │─────────┘                                │             │
                       │        │  device-<mid>    │──────────── wazuh-agent (device-<mid>) ──┘             │
                       │        │  posture-connect │                                                        │
                       │        └──────────────────┘                                                        │
                       └────────────────────────────────────────────────────────────────────────────────────┘
```

**Flow.** The client presents its device certificate (mTLS) and a Bearer token to
`POST /v3/connect`. Apache exports the cert CN as `SSL_CLIENT_S_DN_CN`; the patched
portal's `PostureChecker` queries the Wazuh Manager API for an agent of that name.
If the agent is `active` (and any SCA threshold is met) eduVPN issues the WireGuard
config; otherwise it returns `403` with a reason. All identity flows from one string:
`device-<machine-id>`, used as **both** the certificate CN and the Wazuh agent name.

---

## Repository layout

| Path | What it is |
|---|---|
| `docker-compose.yml`, `up.sh`, `down.sh`, `Makefile`, `.env` | The single-host stack + one-command lifecycle (`.env` is the committed central config) |
| `ca-server/` | step-ca image + entrypoint that publishes the shared PKI (`pki` volume) and PoC provisioners |
| `eduvpn-server/` | systemd container: real eduVPN `deploy_debian.sh` + the mTLS/posture overlay (`first-boot.sh`); vendored deploy under `deploy/` |
| `eduvpn-server/vpn-user-portal/` | Vendored `vpn-user-portal` fork — the 5 patched files (the native posture integration) |
| `eduvpn-integration/` | Reference write-up of the integration + `config.php.example` |
| `client/` | systemd endpoint: device cert + Wazuh agent + `eduvpn-posture-connect` Go CLI + `headless-oauth.sh` |
| `wazuh-server/` | Wazuh manager/indexer/dashboard config + cert generation — **vendored from `wazuh-docker`, GPL-2.0** (see `VENDORED.md`) |
| `demo.sh` | End-to-end proof matrix (4 scenarios) |
| `tests/` | Automated test suite — 7 tiers (unit-php, unit-go, lint, e2e, perf, scale, conformance); start with `./tests/run-all.sh fast` |
| `concept/` | Design concept: a universal Device Health interface, generalized from this PoC |
| `LICENSE`, `LICENSES/`, `SECURITY.md` | MIT text; third-party licence texts; security-reporting policy and what counts as in scope |

---

## Using it by hand (interactive)

**Inside the client container**, use a token — the container ships no browser, and
the CLI's OAuth callback listener binds to loopback *inside the container's network
namespace*, so the interactive browser flow cannot complete there:

```bash
# inside the client container:
TOKEN=$(headless-oauth.sh --server vpn.local --user vpn --pass admin \
          --ca /pki/ca-bundle.crt \
          --cert /etc/eduvpn-client/device.crt --key /etc/eduvpn-client/device.key)

eduvpn-posture-connect \
  --server vpn.local \
  --ca    /pki/ca-bundle.crt \
  --cert  /etc/eduvpn-client/device.crt \
  --key   /etc/eduvpn-client/device.key \
  --token "$TOKEN" \
  --dry-run                 # stop at the posture decision, don't build a tunnel
```

**The interactive browser login is a host-side flow.** Build the client on a machine
with a browser (see [`eduvpn-integration/README.md`](eduvpn-integration/README.md)
for the build, and [`client/README.md`](client/README.md) for obtaining a device
certificate on a real host), then drop `--token` and the CLI will open a browser for
the OAuth login.

Demo portal account: **`vpn` / `admin`**.

---

## Security note — demo secrets

To keep the PoC reproducible with a single command, **committed demo secrets** are
used throughout and are **not** production-safe. Before any real deployment, change
at least:

- the step-ca key password (`CA_PASSWORD`, default `eduvpn-demo-ca-password`),
- the Wazuh indexer/API passwords (`SecretPassword`, `MyS3cr37P450r.*-`, …),
- the portal demo account (`vpn` / `admin`),

and move them into a real secret store. See `.env` for the tunable values. The full
VPN tunnel and the eduVPN host firewall (nftables) are treated as **best-effort / out
of scope** here — the load-bearing proof is the `/v3/connect` posture decision.

**Other PoC limitations to be aware of (do not copy verbatim into production):**

- **Device identity is only as strong as the shared provisioner password.** Device
  certs are minted by step-ca's `device-certs` JWK provisioner, which uses the (demo)
  CA password. Anyone holding it can issue `device-<anything>` and impersonate any
  device — the mTLS CN is the whole identity. A real deployment needs per-device
  enrollment (e.g. TPM-backed attestation), as discussed in
  [`concept/`](concept/universal-device-health-interface.md).
- **Wazuh agent enrollment is password-less** (`use_password=no`). The manager's
  enrollment/API ports are deliberately **not** published to the host (compose
  `expose`-only), so this is contained to the `eduvpn-net` bridge — but on a shared
  Docker host, treat that bridge as a trust boundary, and enable authenticated
  enrollment for anything real.
- The eduVPN server and client run as **privileged containers** (needed for systemd +
  WireGuard/nftables inside a container).
- The posture check's connection to the Wazuh API ships with **TLS verification disabled**
  (`wazuhCaCert=''`, "internal network") — set a CA path to make that channel verified.
- **Demo credentials on the command line:** `headless-oauth.sh --pass` and the Go
  client's `--token` pass secrets as argv, so they're visible in the process list of
  the machine running the demo. Fine for the demo driver; use a real token source
  otherwise.
- **No certificate revocation:** the mTLS layer has no CRL/OCSP, so a lost device cert
  can't be revoked before it expires. Leaf certificates are issued with a 90-day
  lifetime (`CA_LEAF_DURATION` in `.env`); nothing in the images renews them, so a
  long-lived deployment needs the renewal cron described in
  [`client/README.md`](client/README.md). Shortening the lifetime narrows the
  revocation gap at the cost of needing that renewal sooner.
- **First boot is not hermetic:** the server installs the real eduVPN packages from the
  upstream eduVPN apt repositories at runtime, so a clean build depends on those repos.
- The SCA sub-check fails **open** when a device has no scan yet (see "What the demo
  proves"); consequently an agent that is never configured to run an SCA scan always
  satisfies `scaMinScore`.
- **Posture checking is opt-in, and it fails open if unconfigured.** If the
  `PostureCheck` block is absent or empty in `config.php`, `Config::postureCheckConfig()`
  returns `null`, no `PostureChecker` is constructed, and `/v3/connect` behaves like
  stock eduVPN — **ungated, with no error and no log line**. That is deliberate (it
  keeps the patched portal usable unmodified), but it means a config-injection failure
  degrades to "no security" rather than "no service". `first-boot.sh` therefore
  verifies the block landed and aborts if it did not; a hand integration has no such
  guard. Pinned by a unit test.
- **The decision is point-in-time, not continuous.** Posture is evaluated once, during
  `/v3/connect`. Nothing re-evaluates it afterwards, so a device that fails compliance
  *after* connecting keeps its tunnel until the authorization expires (eduVPN's
  default is 90 days). Continuous re-evaluation is specified as future work in
  [`concept/`](concept/universal-device-health-interface.md).
- **step-ca's API is published on the host** (`9000:9000`) for demo convenience. Its
  `/provisioners` endpoint serves the encrypted provisioner JWKs unauthenticated,
  which turns the demo CA password into an offline brute-force target. Bind it to
  `127.0.0.1` — or drop the port mapping entirely — for anything beyond a laptop demo.

---

## Troubleshooting

- **First boot takes minutes.** `./up.sh` returns once containers are *started*, but
  `eduvpn-server` then runs the real `deploy_debian.sh` (apt installs) and Wazuh needs
  ~1–2 min to go healthy. Watch `docker compose ps` until `eduvpn-server` is `healthy`
  before running `./demo.sh`; the demo/tests already gate on this.
- **`wazuh-indexer` exits or restarts on start.** Almost always `vm.max_map_count` too
  low — set it to 262144 (see Prerequisites).
- **Portal unreachable / `demo.sh` fails at the OAuth step.** The server is likely still
  provisioning; give it longer, then check `docker logs eduvpn-server` for the
  `[first-boot] DONE` line.
- **Browser can't resolve `vpn.local`.** Only needed for host-browser access — add the
  `/etc/hosts` entry from the quickstart. The demo doesn't need it.
- **Disk space.** The Wazuh images plus the eduVPN build need several GB free.

---

## Component references

Each component keeps a deeper write-up: [`ca-server/`](ca-server/README.md),
[`eduvpn-server/`](eduvpn-server/README.md),
[`eduvpn-integration/`](eduvpn-integration/README.md),
[`client/`](client/README.md), [`wazuh-server/`](wazuh-server/README.md). Those
predate this single-host stack and describe the standalone / bare-metal deployment;
where they hard-code example IPs, the Docker stack instead resolves components by name
(`ca-server`, `wazuh.manager`, `vpn.local`).

Also worth reading:

- [`tests/README.md`](tests/README.md) — what each of the seven test tiers covers,
  and an explicit list of what is **not** covered.
- [`concept/universal-device-health-interface.md`](concept/universal-device-health-interface.md)
  — the generalised device-health interface this PoC was built to ground.
- [`SECURITY.md`](SECURITY.md) — what is in scope for a security report, and what is
  a documented PoC limitation rather than a vulnerability.
- [`client/cert-based-enrollment.md`](client/cert-based-enrollment.md) — a design
  proposal for certificate-based Wazuh enrolment. **Not implemented**; the shipped
  client enrols with plain `agent-auth`.

---

## License

This project's own code is licensed under the **MIT License** — see [`LICENSE`](LICENSE).
Every file the project authored carries an `SPDX-License-Identifier: MIT` header.

**The MIT grant does not extend to the three vendored directories below.** They are
third-party material, included under **their own** licences, and this project claims no
copyright over them:

| Path | Upstream | License |
|---|---|---|
| [`eduvpn-server/vpn-user-portal/`](eduvpn-server/vpn-user-portal/VENDORED.md) | eduVPN `vpn-user-portal` | **AGPL-3.0-or-later** |
| [`eduvpn-server/deploy/`](eduvpn-server/deploy/VENDORED.md) | eduVPN `deploy` | **AGPL-3.0-or-later** (per upstream's docs) |
| [`wazuh-server/`](wazuh-server/VENDORED.md) | Wazuh `wazuh-docker` v4.14.3 | **GPL-2.0-only** |

MIT is one-way compatible with the (A)GPL: MIT-licensed code may be combined into an AGPL
work, never the reverse. The posture gate is distributed as part of the AGPL portal and is
governed by the AGPL there.

Three files inside the vendored portal are modified by this project
(`src/Http/VpnApiThreeModule.php`, `src/Cfg/Config.php`, `web/api.php`) and two are added
(`src/PostureChecker.php`, `src/Cfg/PostureCheckConfig.php`). All five are
**AGPL-3.0-or-later**, like the fork they live in — the MIT licence does not apply inside
that directory. Each modified file carries a notice of the change and its date, as
AGPL-3.0 §5(a) requires.

The `wazuh-server/` configuration is derived from `wazuh-docker`, whose license is
GPL-2.0-**only** (no "or later" clause); the full text ships at
[`LICENSES/GPL-2.0-only.txt`](LICENSES/GPL-2.0-only.txt). Upstream's copyright notices have
been restored on each derived file. These files configure separately-distributed official
`wazuh/*` container images; this repository ships no Wazuh source or binaries.

The vendored eduVPN `deploy/` scripts ship no `LICENSE` file of their own. eduVPN states
project-wide that "The VPN server software is licensed under the AGPLv3+"
([docs.eduvpn.org](https://docs.eduvpn.org/server/v2/index.html)), and every other eduVPN
server component carries an explicit AGPL header, so they are treated as
AGPL-3.0-or-later on that basis — see
[`eduvpn-server/deploy/VENDORED.md`](eduvpn-server/deploy/VENDORED.md) for the sourcing and
its caveats.

---

## Funding

This research was funded by the German Federal Ministry of Research, Technology and Space
(BMFTR) under the *Nice Device* project. Project partners: Hochschule München, genua GmbH.
