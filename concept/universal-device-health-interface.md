# Universal Device Health Interface — Design Concept

## Abstract

This document specifies a **universal, vendor-neutral interface for transmitting device-health (device-posture) information** between the systems that *produce* health knowledge (EDR, MDM, and attestation providers) and the systems that *consume* it to make access decisions (VPN gateways, application proxies, resource servers).

---

## 1. Problem & Goal

Device-health gating is increasingly required by Zero-Trust architectures: access should depend not only on who the user is, but on whether the device is healthy. Today each such integration is built point-to-point and vendor-locked. A relying party such as eduVPN ends up wired directly to one specific provider such as Wazuh, using that provider's proprietary REST shape, a hard-coded identifier convention, and bespoke decision logic embedded in the relying party. Swapping Wazuh for Microsoft Intune, CrowdStrike, or osquery, or reusing the same health signal in a second relying party such as an application proxy or a resource server, means re-implementing the integration from scratch.

This work package therefore defines one interface that decouples health producers from health consumers. It comprises a common data model for device-health information that lets a consumer interpret any provider's output, a small set of provider-neutral operations so that a consumer talks to every provider in the same way, protocol bindings and security mechanisms that make the exchange implementable and trustworthy across platforms, and the developer documentation needed to build either a provider adapter or a consumer. The result is a contract at the seam between systems that know device health and systems that act on it, reusable across API areas and platforms.

## 2. Scope & Non-Goals

In scope for this Feinkonzept are the **interface functions** (§6), the **data formats** (§5, §7), the **communication protocols** (§8), and the **security mechanisms** (§9). The document further provides a **reference and actor model** (§4) and **developer integration guidance** (§11).

Out of scope, first, is the **policy and enforcement engine**. *How* a consumer turns health into an allow or deny decision (thresholds, risk scoring, step-up) is consumer policy, not the interface. The interface *delivers* the signals and optionally a provider verdict, but it does not mandate the decision. Second, **health collection** is out of scope: *how* a provider computes a signal (how an EDR runs a CIS benchmark, or how a TPM measures boot) is provider-internal. Third, **remediation and response actuation** (for example OpenC2) is out of scope, because the interface reports health and does not command devices. The same applies, fourth, to **user interfaces and dashboards**.

## 3. Reference Scenario

To keep the specification grounded and implementable, this concept is developed against a concrete reference scenario: a device-health-gated VPN access system built from eduVPN as the relying party, Wazuh as the health provider, and step-ca as the identity authority. This scenario is the blueprint for the planned reference implementation of the interface and serves as the running example throughout the document.

**Planned data flow:**

```
 Device (client)                   Relying Party (eduVPN server)                      Health Provider (Wazuh)
 ───────────────                   ─────────────────────────────                      ───────────────────────
 device-<machine-id>               Apache (mTLS, TLS 1.3)                             Manager REST API :55000
   • X.509 cert  CN=device-<machine-id>    SSLVerifyClient optional
   • Wazuh agent name=device-<machine-id>  exports SSL_CLIENT_S_DN_CN
        │                                        │                                               │
        │ 1. mTLS cert + OAuth2 Bearer           │                                               │
        │    POST /v3/connect ──────────────────▶│ 2. read cert CN (device id)                   │
        │                                        │ 3a. GET /security/user/authenticate ─────────▶│
        │                                        │ ◀── JWT ──────────────────────────────────────│
        │                                        │ 3b. GET /agents?name=<CN>&select=status,id ──▶│
        │                                        │ ◀── status ───────────────────────────────────│
        │                                        │ 3c. GET /sca/<agentId> (optional) ───────────▶│
        │                                        │ ◀── scores ───────────────────────────────────│
        │  ◀── 200 + WireGuard config ───────────│ 4a. active (+compliant) → allow               │
        │  ◀── 403 + {"error": reason} ──────────│ 4b. else / provider error → deny (fail-closed)│
```

Three design decisions of this scenario carry the generalization developed in the rest of this document. First, **one identifier is the join key**: the string `device-<machine-id>` is simultaneously the certificate CN (proving identity over mTLS) and the Wazuh agent name (indexing health), so identity and health are correlated by *string equality* of this one value. Second, **the provider is queried and the consumer decides**: Wazuh exposes raw state through `/agents` and `/sca`, and the relying party interprets that state and derives an allow or deny decision at the top of the `/v3/connect` handler — ahead of any side effect of the request, so a device that is about to be refused cannot first disturb the user's existing compliant session. Third, **the decision is fail-closed**, with one deliberate exception: freshly enrolled devices with no SCA scan yet are allowed, a nuance the data model must be able to express.

## 4. Reference & Actor Model

### 4.1 Actors

The interface defines four roles. The reference scenario collapses some of them into single products, but the roles are distinct and must be separable.

| Role | Definition | Reference scenario |
|---|---|---|
| **Device / Endpoint** | The subject the health statements are *about*. | The client machine, identity `device-<machine-id>`. |
| **Health Source / Provider** | The authority that produces and exposes health statements about devices. | Wazuh Manager REST API. |
| **Relying Party / Consumer** | A system that requests health to inform an access or trust decision. | The eduVPN portal, gating the `/v3/connect` API. |
| **Identity Authority** | Binds a stable, verifiable identifier to a device, enabling the Consumer to authenticate the device and to correlate it with Provider health. | step-ca (issues the cert CN, which doubles as the Wazuh agent name). |

### 4.2 The identity join-key

This is the single most important generalization. A Consumer decision needs two things bound to the *same* device: an **authenticated identity** (so the Consumer knows *which* device is asking) and a **health record** (so it knows the device's *state*). These come from different systems and must be correlated on a shared identifier.

In the reference scenario, the one string `device-<machine-id>` is used verbatim as the certificate CN and as the Wazuh agent name. The Consumer reads the CN from the mTLS handshake and uses it verbatim to query the Provider.

The generalization is a pluggable **device identity binding**. The interface defines a `device.id` accompanied by an `idScheme` naming *how* that identifier is established and authenticated:

| `idScheme` | Identifier established by | Authenticated to the Consumer via |
|---|---|---|
| `x509-cn` | CA-issued certificate CN (reference scenario) | mTLS client certificate |
| `x509-san` | certificate SAN (URI/DNS) | mTLS client certificate |
| `fido-aaguid` / `tpm-ek` | hardware attestation | WebAuthn / TPM attestation statement |
| `mdm-deviceid` | MDM enrolment | managed-device token / mTLS |

A Provider indexes health by *some* identifier, and a Consumer authenticates *some* identifier. Interoperability requires that both resolve to the same `device.id`, either directly (in the reference scenario they are literally the same string) or via the `ResolveIdentity` operation and `device.aliases[]` (§5, §6). Mapping `idScheme`s to a deployment's real identity infrastructure remains an integration decision.

### 4.3 Trust boundaries & data flow

```
         ┌────────────────┐     issues identity     ┌────────────────────┐
         │    Identity    │────────────────────────▶│       Device       │
         │   Authority    │   (idScheme binding)    │   (idScheme,id)    │
         └────────────────┘                         └─────────┬──────────┘
                                                              │ authenticated request
                                                              │ (carries device identity)
                                                              ▼
   ┌──────────────────┐   GetDeviceHealth(id)   ┌────────────────────────┐
   │  Health Provider │◀────────────────────────│    Relying Party /     │
   │  (health source) │────────────────────────▶│        Consumer        │
   └──────────────────┘   Device Health Record  └──────────┬─────────────┘
                                                           │ allow / deny (consumer policy)
                                                           ▼
                                                  protected resource
```

The interface must make its trust assumptions explicit (they are formalized in §9). The Consumer trusts the Identity Authority to authenticate `device.id`. The Consumer trusts the Provider's health statements, which requires Provider authenticity and transport integrity. And the Provider and the Consumer agree on the identifier that indexes a device.

### 4.4 Deployment topologies

| Topology | Description | Reference scenario? |
|---|---|---|
| **Consumer-pull** | Consumer queries Provider at decision time. | The chosen topology |
| **Provider-push** | Provider streams health changes to subscribed Consumers (continuous evaluation). | Future extension (§6.3) |
| **Broker / aggregator** | A component fans-in multiple Providers and presents one unified record per device. | Enabled by the model, not used |
| **Embedded / self-asserted** | Device presents its own signed health/attestation inline (no separate Provider call). | Enabled via attestation `idScheme`s |

## 5. Canonical Device Health Data Model

The data model is the core of the interface's claim to universality: one record shape that any provider can populate and any consumer can read. This section is normative.

### 5.1 Design principles

The model follows four design principles. It uses a **uniform signal envelope**: every health fact (agent liveness, a CIS score, a disk-encryption flag, a risk score, an attestation result) is represented with the *same* structure, and extensibility is achieved by adding signal *instances*, never new top-level shapes. Signal identity is **namespaced and registry-governed** (`core.*` for standardized signals, `x-<vendor>.*` for extensions), so independent parties can add signals without collision. The model is **provider-agnostic**: no field is Wazuh-, Intune-, or transport-specific. And it is **forward-compatible**: Consumers must ignore unknown fields and unknown signal namespaces (treating unknown signals as `status: "unknown"`) and never fail on them.

### 5.2 Health-attribute taxonomy

The standardized top-level signal categories are the `core.*` namespaces. Each is open-ended.

| Namespace | Meaning | Reference-scenario example |
|---|---|---|
| `core.liveness` | Is the device currently reporting / reachable to its provider? | Wazuh agent `status == "active"` |
| `core.compliance` | Configuration-assessment results against a policy/benchmark. | Wazuh SCA per-policy score |
| `core.threat` | Active threat / risk indicators (malware, IoC, risk score). | *(Wazuh alerts, deliberately excluded)* |
| `core.inventory` | Software/hardware inventory & versions. | *(Wazuh syscollector, not surfaced)* |
| `core.attestation` | Hardware/boot integrity (TPM/measured boot, secure boot). | *(not covered)* |
| `core.vulnerability` | Known-vulnerability exposure. | *(Wazuh vulnerability detection, excluded)* |

### 5.3 The canonical Device Health Record

A record describes **one device**, from **one provider**, at **one point in time**, as a set of timestamped **signals**, with an **optional provider verdict** and mandatory **freshness**.

```jsonc
{
  "schemaVersion": "1.0",
  "recordId": "9b1c…",                       // opaque, unique per emission
  "device": {
    "id": "device-0123456789abcdef0123456789abcdef",   // the join key
    "idScheme": "x509-cn",
    "aliases": [                             // other ids the same device is known by
      { "scheme": "wazuh-agent-id", "value": "008" }
    ]
  },
  "provider": {
    "id": "wazuh-manager",
    "type": "edr",                           // edr | mdm | attestation | posture | broker | …
    "assessedAt": "2026-07-13T10:31:42Z"
  },
  "signals": [ /* see §5.4 */ ],
  "verdict": {                               // optional (§5.5)
    "decision": "pass",                      // pass | fail | indeterminate
    "reason": "ok",
    "evaluatedBy": "provider",               // provider | consumer
    "failedSignals": []
  },
  "freshness": {                             // required (§5.6)
    "generatedAt": "2026-07-13T10:31:42Z",
    "expiresAt":   "2026-07-13T10:46:42Z",
    "maxAgeSeconds": 900
  }
}
```

Field requirements: `schemaVersion`, `device.id`, `device.idScheme`, `provider.id`, `signals`, and `freshness.generatedAt` must be present. `verdict` and `device.aliases` are optional.

### 5.4 Signal envelope

```jsonc
{
  "id": "compliance.cis_debian12",
  "namespace": "core.compliance",
  "type": "score",                           // state | score | boolean | count | measurement
  "value": 82,
  "scale": { "min": 0, "max": 100, "unit": "percent" },   // for type=score
  "status": "pass",                          // pass | fail | unknown | not_applicable
  "observedAt": "2026-07-13T10:30:00Z",
  "detail": { "policyId": "cis_debian12", "pass": 156, "fail": 30, "total": 191 },
  "failureMode": "closed",                   // closed | open  (§9.5)
  "source": { "raw": "sca.affected_items[0]" }   // provenance for traceability
}
```

`value` and `type` carry the datum, and `scale` bounds numeric scores. `status` is the Provider's per-signal assessment. The values `unknown` (data not yet available, for example a device enrolled but not yet scanned) and `not_applicable` are first-class, because they are what lets the model express the reference scenario's deliberate fail-open exception (§5.6, §9.5). `observedAt` timestamps *this signal* and may predate the record's `generatedAt`. `failureMode` declares how a Consumer should treat `unknown` or absence for this signal (§9.5).

### 5.5 Verdict (optional)

The model supports two evaluation modes. In the **consumer-evaluated** mode, the default of the reference scenario, the record carries raw `signals` and the Consumer applies its own policy (for example a minimum compliance score) and derives the decision. In this mode `verdict` may be omitted. In the **provider-evaluated** mode, a Provider that already knows the policy (for example Intune's compliant/noncompliant state, or a CAEP risk event) may include `verdict` with `evaluatedBy: "provider"`.

A Consumer must not treat a provider `verdict` as authoritative over its own policy unless it chooses to. The raw `signals` remain the source of truth. `verdict.reason` carries a reason from the error and status model (§6.5).

### 5.6 Freshness & staleness

A liveness status alone is implicit and coarse. In the reference scenario the agent status must be `active`, but nothing guarantees that a compliance scan is recent. The model therefore makes recency **explicit and first-class**. Every record carries `freshness.generatedAt`, and Providers should set `expiresAt` or `maxAgeSeconds`. Every signal carries `observedAt`. A Consumer must define a maximum acceptable age and must treat a record or signal older than that as `status: "unknown"` for policy purposes, subject to the signal's `failureMode`. Without this rule, a long-idle but nominally active agent, or a stale compliance result, would pass unnoticed.

### 5.7 Extensibility & versioning

`schemaVersion` uses semantic versioning, and minor versions are additive and backwards-compatible. Vendor signals use `x-<vendor>.*` namespaces and must not collide with `core.*`. Consumers must tolerate unknown fields and unknown signal namespaces (§5.1).

## 6. Interface Functions / Operations

The **pull profile** (§6.1, §6.2, §6.5) is normative. The **push profile** (§6.3) is an informative future extension.

### 6.1 Operation catalogue (pull profile)

| Operation | Purpose | Reference-scenario example |
|---|---|---|
| `Discover` / `GetCapabilities` | Learn a provider's supported signal namespaces, operations, auth methods, encodings. | *(no native Wazuh equivalent, supplied by the adapter)* |
| `Authenticate` / `OpenSession` | Establish an authenticated session with the Provider. | `GET /security/user/authenticate` (Basic → JWT) |
| `GetDeviceHealth` | Retrieve the canonical record for one device. | `GET /agents?name=…` **+** `GET /sca/{id}`, combined |
| `QueryDeviceHealth` | Retrieve records for a *population* (filter + pagination). | `/agents` list form |
| `ResolveIdentity` | Map a `device.id`+`idScheme` to the provider's canonical identity + `aliases`. | the `name → numeric agent id` lookup |

### 6.2 `GetDeviceHealth` (normative semantics)

`GetDeviceHealth(deviceId, idScheme, [signalFilter], [maxAge]) → DeviceHealthRecord`

The Provider adapter must hide its internal round-trips. In the reference scenario, the two Wazuh calls (`/agents` for status and the numeric id, then `/sca/{id}`) become one operation returning one record. If the device is unknown to the Provider, the operation must return a distinct `deviceUnknown` error, not an empty healthy record. For a supported namespace with no data yet (for example an agent that is enrolled but has no compliance scan), the record must include the signal with `status: "unknown"` rather than omit it silently. For an unsupported namespace in `signalFilter`, the Provider must report `signalUnsupported` (via `Discover` and/or a per-signal marker), enabling the Consumer to decide rather than silently missing a signal.

### 6.3 Push profile (future extension, informative)

The pull profile decides **once**, at access time, and never re-evaluates. A production Zero-Trust system also wants **continuous evaluation**: access is revisited when a device's health *changes* mid-session (the agent drops, compliance falls). This is specified as a future extension and should bind to the OpenID **Shared Signals Framework (SSF) + CAEP** rather than inventing new event schemas.

| Operation | Purpose |
|---|---|
| `Subscribe` | Consumer registers interest in a device (or population) + namespaces. |
| `HealthEvent` (push) | Provider emits a partial record or verdict change on health change. |
| `Unsubscribe` | Tear down. |

In the pull profile the decision is made at access-request time, while the push profile revisits the decision during the session. The data model (§5) is shared: a `HealthEvent` carries the same signal envelope.

### 6.4 Discovery & capability negotiation

Because Providers differ in which `core.*` namespaces they can produce, a Consumer must be able to learn a Provider's capabilities via `Discover` rather than assume them. This is the mechanism that makes the interface work across heterogeneous platforms (§10). Without it, a consumer would remain hard-wired to one provider's exact API surface, which is precisely the coupling this interface removes.

### 6.5 Error & status model

Operations must distinguish the following conditions:

| Condition | Meaning | Reference-scenario reason |
|---|---|---|
| `deviceUnknown` | No such device at this Provider. | `no Wazuh agent registered for device "%s"` |
| `identityInvalid` | Identifier malformed / unauthenticated. | `device certificate required`, `invalid device certificate CN format` |
| `signalUnsupported` | Provider cannot produce a requested namespace. | *(new with this interface)* |
| `providerUnavailable` | Provider unreachable/errored. | `posture service unavailable` (fail-closed) |
| `unauthorized` | Consumer not permitted. | *(implicit in the Wazuh authentication)* |

The distinction matters: a Consumer treats `providerUnavailable` per fail-closed policy (§9.5), but `deviceUnknown` is a definite negative.

## 7. Data Formats (normative)

The canonical encoding is JSON (UTF-8) with media type `application/health+json`, versioned via a `profile` parameter or the `schemaVersion` field. Timestamps use RFC 3339 / ISO 8601 in UTC (`…Z`). Scores are integers within the declared `scale` (in the reference scenario, compliance is a 0–100 percent score). The enumerations (`status`, `type`, `decision`, `idScheme`, `provider.type`) are registry-governed, and unknown values are treated as `unknown`. Alternative encodings may be defined for constrained or attestation contexts (for example CBOR, or CoSWID for `core.inventory`) without changing the model.

## 8. Protocol Bindings

The primary and normative binding is **HTTPS/REST**. It is designed to fit both the Provider side (for example the Wazuh REST API) and the Consumer side (in the reference scenario, the eduVPN `/v3/connect` gate). Operations map to resources: `GET /devices/{id}/health` implements `GetDeviceHealth`, `GET /devices?…` implements `QueryDeviceHealth`, `GET /.well-known/device-health` implements `Discover`, and `POST /session` implements `Authenticate`. The session token is carried as `Authorization: Bearer` (in the reference scenario, the Wazuh JWT). `QueryDeviceHealth` must paginate, and `GetDeviceHealth` must be idempotent.

## 9. Security Mechanisms

Security is cross-cutting but consolidated in this section, which is normative.

### 9.1 Identity binding & assurance

The Consumer must authenticate `device.id` before trusting a health record bound to it. In the reference scenario, Apache requests a client certificate (`SSLVerifyClient optional`, at virtual-host level because TLS 1.3 clients cannot be relied on to support post-handshake certificate requests) and exports `SSL_CLIENT_VERIFY` and `SSL_CLIENT_S_DN_CN`. The gate rejects the request unless verification succeeded (`SSL_CLIENT_VERIFY == "SUCCESS"`) and a CN is present. In the general model, the `idScheme` (§4.2) names the authentication method, and an optional assurance/LoA indicator may qualify how strongly the identity is bound (certificate CN versus hardware-attested).

### 9.2 Transport security

All interface calls require TLS **1.3** as the minimum protocol version. Consumers must verify the Provider's server certificate chain against a configured trust anchor. For closed internal networks, the reference scenario retains an explicit, discouraged assurance-downgrade knob (an empty `wazuhCaCert` disables Provider TLS verification), and verification must default to on.

### 9.3 Provider & caller authentication

For Consumer-to-Provider authentication, the `Authenticate` operation establishes a session. In the reference scenario this is HTTP Basic exchanged for a JWT, cached just below Wazuh's 15-minute token lifetime. Session tokens must have a bounded lifetime and be refreshable. Device- or user-to-Consumer authentication is orthogonal to this interface but part of the reference scenario, where the Consumer combines OAuth2 Bearer authentication (with PKCE and CSRF protections via `Origin`/`Sec-Fetch-Site` headers) with the device health obtained through this interface.

### 9.4 Freshness, replay & revocation

Records and signals are timestamped (§5.6), and Consumers enforce a maximum age. Push events (§6.3) must carry an issued-at timestamp plus a nonce to prevent replay. Session tokens are short-lived, and long-lived provider credentials should be avoided.

### 9.5 Failure-mode policy (fail-closed by default)

The interface expresses the failure-mode asymmetry as a per-signal declaration. The default is **fail-closed**: absent or failed evidence results in deny. In the reference scenario, any Provider error results in a `posture service unavailable` denial, and an agent that is not `active` is denied. Fail-open is allowed only where explicitly justified: a signal may declare `failureMode: "open"` so that `status: "unknown"` or absence is *permitted*. The reference scenario uses this for exactly one case, a freshly enrolled device with no SCA scan yet, to avoid locking out new devices. Fail-open must be a conscious, per-signal choice, never a global default.

| Situation | `failureMode` | Outcome |
|---|---|---|
| Provider unreachable / errored | (whole record) closed | deny |
| Signal present, `status: fail` | n/a | deny |
| Signal `unknown` (not yet available) | `closed` | deny |
| Signal `unknown` (not yet available) | `open` | allow (reference scenario: no SCA scan yet) |

### 9.6 Privacy & data minimization

Device-health data is sensitive (compliance posture, installed software, risk). In regulated contexts (e.g. BSI IT-Grundschutz, GDPR) the interface should support data minimization: `signalFilter` lets a Consumer request only the namespaces it needs, and Providers should disclose only what a Consumer is authorized to see.

## 10. Extensibility to different API areas & platforms

Universality is achieved by three mechanisms working together: the **uniform signal envelope** (§5.4), **namespaced signal types** (§5.2), and **capability discovery** (§6.4). A new platform joins by shipping a **provider adapter** that maps its native API onto the operations and the record.

| Platform / product | Provider adapter maps… | Signal categories produced |
|---|---|---|
| **Wazuh** (reference scenario) | `/agents`, `/sca` → `GetDeviceHealth` | `core.liveness`, `core.compliance` |
| **Microsoft Intune** | compliance state, DHA measured boot | `core.compliance` (+ `verdict`), `core.attestation` |
| **CrowdStrike / EDR** | sensor status, detections, ZTA score | `core.liveness`, `core.threat` |
| **osquery / custom** | scheduled queries (disk-encryption, patch level) | `core.compliance`, `core.inventory` |
| **FIDO / TPM attestation** | attestation statement (self-asserted) | `core.attestation` (embedded topology) |

The Consumer code is unchanged across these. Only configuration (which Provider, which namespaces) differs.

## 11. Developer Documentation & Integration Guide

**To implement a Provider adapter:**

1. Expose `Discover` advertising your supported `core.*` namespaces and auth methods.
2. Implement `Authenticate` (map it to your provider's session or token scheme).
3. Implement `GetDeviceHealth`: resolve the device by `id`/`idScheme`, gather your native signals, and emit the canonical record (§5). Set `status`, `observedAt`, and `failureMode` per signal, set `freshness`, and return `deviceUnknown` distinctly.
4. Optionally implement `QueryDeviceHealth` and `ResolveIdentity`.

**To implement a Consumer:**

1. Authenticate the device identity (§9.1) and obtain its `device.id` and `idScheme`.
2. Call `GetDeviceHealth` and enforce `freshness` (§5.6).
3. Apply *your* policy to the `signals` (or honour a provider `verdict`), respecting each signal's `failureMode` and the fail-closed default (§9.5).
4. Decide allow or deny, and surface a reason from the error and status model (§6.5).

**Configuration.** The interface keeps two configuration concerns separate: the provider-connection config (endpoint, credentials, trust anchor) and the consumer policy (per-namespace thresholds and rules). Because of this separation, swapping one provider for another means writing a new provider adapter (§10) and pointing the provider-connection config at it. The consumer's gate logic and policy remain unchanged.

## 12. Conformance

A Provider must implement `GetDeviceHealth` returning a schema-valid canonical record, must signal `deviceUnknown` distinctly, must mark not-yet-available signals `unknown`, and should implement `Discover`. A Consumer must enforce freshness, must fail closed by default, and must honour per-signal `failureMode`.
