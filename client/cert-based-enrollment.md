# Design: Certificate-Based Wazuh Agent Enrollment

> **Status: design proposal — NOT implemented in this PoC.** The shipped client enrolls
> the Wazuh agent with plain `agent-auth` (see `client/first-boot.sh`); the
> certificate-based enrollment described below is a future-work sketch, not the current
> behaviour.

## The Problem

Currently the Wazuh agent name and the certificate CN are set independently via a naming convention (`device-<machine-id>`). This works, but it relies on the operator getting the name right in two separate places. If they mismatch, the eduVPN server can't correlate the certificate identity with the Wazuh agent — and the failure is silent.

## The Goal

Make the step-ca certificate the **single source of truth** for device identity. The client gets its certificate first, then uses that same certificate to enroll with Wazuh. The agent name is derived from the certificate CN automatically — no manual naming, no room for mismatch.

## What Changes

### Current flow (client)

```
1. Install Wazuh Agent  →  set name manually via WAZUH_AGENT_NAME
2. Install step CLI     →  bootstrap CA trust
3. Request certificate  →  set CN to device-<machine-id>
```

Name consistency depends on the operator running the same `$(cat /etc/machine-id)` in both steps.

### New flow (client)

```
1. Install step CLI     →  bootstrap CA trust
2. Request certificate  →  CN = device-<machine-id>  (source of truth)
3. Install Wazuh Agent  →  enroll with certificate, name extracted from CN
```

The certificate is obtained first. The Wazuh enrollment uses it for both authentication and identity.

---

## Changes by Component

### 1. Wazuh Manager Config

**File:** `wazuh-server/config/wazuh_cluster/wazuh_manager.conf`
**Section:** `<auth>` (line 240–251)

Add `<ssl_agent_ca>` so the manager verifies agent certificates against the step-ca root:

```xml
<!-- Agent enrollment (authd) -->
<auth>
    <disabled>no</disabled>
    <port>1515</port>
    <use_source_ip>no</use_source_ip>
    <purge>yes</purge>
    <use_password>no</use_password>
    <ciphers>HIGH:!ADH:!EXP:!MD5:!RC4:!3DES:!CAMELLIA:@STRENGTH</ciphers>
    <ssl_verify_host>no</ssl_verify_host>
    <ssl_manager_cert>etc/sslmanager.cert</ssl_manager_cert>
    <ssl_manager_key>etc/sslmanager.key</ssl_manager_key>
    <ssl_auto_negotiate>no</ssl_auto_negotiate>
    <ssl_agent_ca>/etc/ssl/step-ca-root.pem</ssl_agent_ca>       <!-- ADD THIS -->
</auth>
```

`<ssl_agent_ca>` tells authd to require and verify a client certificate during enrollment. Only agents presenting a cert signed by the step-ca root will be accepted.

> **Note:** This does NOT break passwordless enrollment. It adds certificate verification on top. Agents without a valid cert will be rejected at the TLS level before the name is even considered.

---

### 2. Wazuh Manager service definition

**File:** root [`docker-compose.yml`](../docker-compose.yml)
**Section:** `wazuh.manager` volumes

*(This proposal originally targeted a standalone `wazuh-server/docker-compose.yml`.
That file was removed before publication — the root compose is now the only stack.)*

Mount the step-ca root certificate into the manager container:

```yaml
volumes:
  # ... existing volumes ...
  - ./config/wazuh_indexer_ssl_certs/root-ca-manager.pem:/etc/ssl/root-ca.pem
  - ./config/wazuh_indexer_ssl_certs/wazuh.manager.pem:/etc/ssl/filebeat.pem
  - ./config/wazuh_indexer_ssl_certs/wazuh.manager-key.pem:/etc/ssl/filebeat.key
  - ./config/wazuh_cluster/wazuh_manager.conf:/wazuh-config-mount/etc/ossec.conf
  - ./config/step-ca-root.pem:/etc/ssl/step-ca-root.pem:ro   # ADD THIS
```

**New file needed:** `wazuh-server/config/step-ca-root.pem`

This is a copy of the CA root certificate. The Wazuh operator must obtain it from the CA operator:

```bash
# Run on the CA server
docker cp ca-server:/home/step/certs/root_ca.crt ./root_ca.crt

# Copy to the Wazuh server
scp ./root_ca.crt <WAZUH_SERVER>:wazuh-server/config/step-ca-root.pem
```

---

### 3. Root Docker Compose (local testing)

**File:** `docker-compose.yml` (repo root)
**Section:** `wazuh.manager` volumes (line 53–68)

Same mount, different path context:

```yaml
volumes:
  # ... existing volumes ...
  - ./wazuh-server/config/wazuh_cluster/wazuh_manager.conf:/wazuh-config-mount/etc/ossec.conf
  - ./ca-server/root_ca.crt:/etc/ssl/step-ca-root.pem:ro   # ADD THIS
```

In the local test environment, the CA root cert can be extracted from the CA container after first boot. A simpler option: use a shared named volume or an init script that pulls it automatically. For now, a manual `docker cp` after first start is fine:

```bash
docker cp ca-server:/home/step/certs/root_ca.crt ./ca-server/root_ca.crt
docker compose restart wazuh.manager
```

---

### 4. Client — Step Order Change

**File:** `client/README.md`

The current guide does Wazuh first (section 4), then step-ca (sections 5–6). With cert-based enrollment, the order must flip:

| Current order | New order |
|---|---|
| 3. Derive Device ID | 3. Derive Device ID |
| **4. Install Wazuh Agent** | **4. Install step CLI & Bootstrap CA** |
| **5. Install step CLI & Bootstrap CA** | **5. Request Client Certificate** |
| **6. Request Client Certificate** | **6. Install & Register Wazuh Agent** |
| 7. Certificate auto-renewal | 7. Certificate auto-renewal |

The certificate must exist before Wazuh enrollment happens.

---

### 5. Client — Enrollment Command Change

**File:** `client/README.md`, section 4.3 (currently) → section 6 (after reorder)

The current enrollment relies on the `-A` flag for the agent name:

```bash
# CURRENT
WAZUH_AGENT_NAME="device-${DEVICE_ID}" apt-get install -y wazuh-agent
```

With certificate-based enrollment, the agent is installed without a name, then enrolled using the certificate. The agent name is extracted from the CN:

```bash
# NEW — install without pre-setting the name
WAZUH_MANAGER="<WAZUH_SERVER_IP>" apt-get install -y wazuh-agent

# Enroll using the certificate; derive name from the cert CN
DEVICE_ID=$(cat /etc/machine-id)

/var/ossec/bin/agent-auth \
  -m "<WAZUH_SERVER_IP>" \
  -A "device-${DEVICE_ID}" \
  -x /etc/eduvpn-client/device.crt \
  -k /etc/eduvpn-client/device.key
```

The `-x` and `-k` flags present the client certificate during the TLS handshake with authd. The manager verifies it against the step-ca root (`ssl_agent_ca`). If verification fails, enrollment is rejected.

> **Why still pass `-A`?** Wazuh's authd does not extract the CN automatically — the `-A` flag is still needed to set the agent name. But now the name is only accepted if the certificate is also valid. The security guarantee comes from the TLS verification, not from the name string. A wrapper script can automate this:
>
> ```bash
> # Extract CN from the cert and use it as the agent name
> AGENT_NAME=$(step certificate inspect /etc/eduvpn-client/device.crt --format json \
>   | python3 -c "import sys,json; print(json.load(sys.stdin)['subject']['common_name'])")
>
> /var/ossec/bin/agent-auth \
>   -m "<WAZUH_SERVER_IP>" \
>   -A "${AGENT_NAME}" \
>   -x /etc/eduvpn-client/device.crt \
>   -k /etc/eduvpn-client/device.key
> ```
>
> This makes the certificate the only source of identity — the name is derived, never typed.

---

### 6. CA Server — Root Certificate Distribution

**File:** `ca-server/README.md`

No config changes needed on the CA itself. But the README should document that the root certificate must be distributed to the Wazuh server (in addition to the eduVPN server, which already has this step).

Add a note to section 6 or 7 of the CA README:

> The Wazuh Manager also needs the root CA certificate to verify agent certificates during enrollment. Copy `root_ca.crt` to the Wazuh server's `config/step-ca-root.pem`.

---

## Summary of All Touched Files

| File | Change |
|---|---|
| `wazuh-server/config/wazuh_cluster/wazuh_manager.conf` | Add `<ssl_agent_ca>` to `<auth>` section |
| `wazuh-server/config/step-ca-root.pem` | **New file** — copy of CA root certificate |
| `docker-compose.yml` (root) | Add volume mount for step-ca root cert |
| `client/README.md` | Reorder sections; change enrollment to use `-x`/`-k` flags |
| `ca-server/README.md` | Document root cert distribution to Wazuh server |

## What This Does NOT Change

- The certificate CN format (`device-<machine-id>`) stays the same.
- The eduVPN server's Wazuh API lookup logic stays the same — it still queries by agent name.
- The Wazuh Agent ↔ Manager runtime communication (port 1514) is unaffected — this only changes enrollment (port 1515).
- Certificate auto-renewal is unaffected — the cert renews in place, and the agent is already enrolled.
