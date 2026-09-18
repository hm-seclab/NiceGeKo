# CA Server — step-ca (smallstep)

> ℹ️ **For the one-command single-host Docker PoC, start at the [root README](../README.md).**
> There, `ca-server/`'s custom entrypoint auto-initialises the PKI + provisioners and
> publishes the trust material (root cert, CA bundle, fingerprint) to a shared `pki`
> volume — no manual fingerprint copy-paste. This document covers the standalone CA.

Certificate Authority component for the eduVPN mTLS integration, powered by [step-ca](https://smallstep.com/docs/step-ca/).

step-ca is an **API and CLI driven** Certificate Authority. It runs inside a Docker container, exposes an HTTPS API on port `9000`, and is operated via the [`step` CLI](https://smallstep.com/docs/step-cli/). Unlike GUI-only CAs, step-ca allows full automation of certificate issuance — which is essential for our mTLS and device-identity workflow.

---

## Table of Contents

1. [Role in the Architecture](#1-role-in-the-architecture)
2. [Prepare a Debian 13 Server](#2-prepare-a-debian-13-server)
3. [Deploy step-ca](#3-deploy-step-ca)
4. [First-Run Initialisation](#4-first-run-initialisation)
5. [Configure Provisioners](#5-configure-provisioners)
6. [Issue Certificates for Each Component](#6-issue-certificates-for-each-component)
7. [Bootstrap Other Servers (Clients & eduVPN)](#7-bootstrap-other-servers-clients--eduvpn)
8. [Reference](#8-reference)

---

## 1. Role in the Architecture

step-ca is the **trust anchor** for the entire system. It:

- Generates a **Root CA** and an **Intermediate CA** (two-tier PKI) on first boot.
- Issues **server certificates** for the eduVPN/WireGuard server (with SANs for its IP/hostname).
- Issues **client certificates** for each Linux endpoint, embedding a **Device ID** in the certificate's Common Name so the eduVPN server can extract it and query Wazuh.
- Optionally issues internal TLS certificates for the Wazuh server API.

All other components trust this CA's root certificate and use the `step` CLI to request and renew their certificates.

---

## 2. Prepare a Debian 13 Server

Start from a fresh Debian 13 (Trixie) minimal install. Run all commands as `root` or with `sudo`.

### 2.1 System updates

```bash
apt update && apt upgrade -y
```

### 2.2 Install Docker CE

Use Docker's official convenience script ([docs.docker.com](https://docs.docker.com/engine/install/debian/#install-using-the-convenience-script)):

```bash
curl -fsSL https://get.docker.com -o get-docker.sh
sh get-docker.sh

# Verify
docker run --rm hello-world
```

### 2.3 (Optional) Allow a non-root user to run Docker

```bash
usermod -aG docker <your-username>
# Log out and back in for the group change to take effect
```

### 2.4 Install the `step` CLI on the host

The `step` CLI is needed on the host to interact with the CA (bootstrap, request certs, manage provisioners). The Smallstep apt repository currently ships a broken `Suites:` line, so install the CLI from the GitHub release `.deb` instead — this is exactly what the server and client images do:

```bash
STEP_VERSION=0.29.0
curl -fsSL "https://github.com/smallstep/cli/releases/download/v${STEP_VERSION}/step-cli_${STEP_VERSION}-1_amd64.deb" -o /tmp/step-cli.deb
sudo dpkg -i /tmp/step-cli.deb && rm -f /tmp/step-cli.deb
```

### 2.5 Firewall considerations

Docker published ports bypass `ufw` and `nftables` rules because Docker injects its own `iptables nat` rules. To restrict access to the CA API:

**Option A — Bind to a specific interface** (recommended for internal-only CAs):
Change the port mapping in `docker-compose.yml` to bind to a specific IP rather than
publishing on all interfaces:
```yaml
ports:
  - "192.168.x.x:9000:9000"
```

**Option B — Use the DOCKER-USER chain**:
```bash
# Allow only the eduVPN server and clients to reach the CA
iptables -I DOCKER-USER -p tcp --dport 9000 -s <EDUVPN_SERVER_IP> -j ACCEPT
iptables -I DOCKER-USER -p tcp --dport 9000 -s <CLIENT_SUBNET>  -j ACCEPT
iptables -A DOCKER-USER -p tcp --dport 9000 -j DROP

# Persist across reboots
apt install -y iptables-persistent
netfilter-persistent save
```

---

## 3. Deploy step-ca

### 3.1 Write a compose file

> **Note:** this directory used to ship a standalone `docker-compose.yml` and
> `.env.example` for a dedicated CA host. They were removed before publication:
> they had drifted out of sync with the supported stack (unpinned
> `smallstep/step-ca:latest`, the stock entrypoint, and therefore **no
> `device-certs` provisioner and no `/pki` publishing**), so running them
> produced a CA that could not serve this PoC. Use the root
> [`docker-compose.yml`](../docker-compose.yml) plus
> [`ca-entrypoint.sh`](ca-entrypoint.sh) as the reference — the section below
> documents the equivalent standalone setup.

On the CA host, create a `docker-compose.yml` modelled on the `ca-server` service in
the repository root, and a `.env` beside it. These are **your** standalone deployment's
files — the keys below are the ones the shipped
[`ca-entrypoint.sh`](ca-entrypoint.sh) actually consumes (via the
`DOCKER_STEPCA_INIT_*` variables the compose file maps them onto), and they match the
repo-root [`.env`](../.env):

```dotenv
CA_NAME=eduVPN-CA
CA_DNS_NAMES=localhost,<THIS_SERVER_IP>
CA_PASSWORD=<choose-a-strong-password>
```

> The listen address (`:9000`) and the default provisioner name (`admin`) are **not**
> configurable through `.env` — `ca-entrypoint.sh` fixes both at `step ca init` time. To
> change the published port, change the port *mapping* in your compose file; the CA still
> listens on 9000 inside the container.

> **`CA_DNS_NAMES` is critical.** Include every IP address or hostname that clients will use to reach the CA. For example: `localhost,10.0.0.5`. If you forget an IP here, clients won't be able to bootstrap against the CA via that address.

### 3.3 Start the CA

```bash
docker compose up -d
```

Check the logs to confirm initialisation succeeded:

```bash
docker compose logs -f ca-server
```

You should see output indicating the CA is listening on `:9000`.

---

## 4. First-Run Initialisation

On the very first start, `ca-entrypoint.sh` automatically:

1. Generates a **Root CA** key pair and self-signed certificate.
2. Generates an **Intermediate CA** key pair, signed by the Root CA.
3. Creates the default **JWK provisioner `admin`**.
4. Adds the **JWK provisioner `device-certs`** — the one that issues device identities
   (§5.3). This is the step the stock step-ca image does *not* do, and the reason this
   directory ships a custom entrypoint at all.
5. Encrypts all private keys with `CA_PASSWORD`.
6. Writes a sentinel only once *all* of the above succeeded, so a half-initialised CA is
   detected on the next start instead of being latched as "already initialised".

### 4.1 Retrieve the root certificate fingerprint

This fingerprint is needed by every other component to bootstrap trust:

```bash
docker exec ca-server step certificate fingerprint /home/step/certs/root_ca.crt
```

Output example:
```
e8bdc64b7d29ceab1da72b0a1a0cd1865e1c8b2e75af09c35f2a5b0a1d3e9f12
```

**Save this fingerprint** — you'll use it on every server/client that needs to trust this CA.

### 4.2 Export the root certificate

To copy the root CA certificate to your local machine (for distribution to other components):

```bash
docker cp ca-server:/home/step/certs/root_ca.crt ./root_ca.crt
```

### 4.3 Trust the root certificate on macOS (developer workstation)

To access the eduVPN web portal and other CA-signed services from your browser without certificate warnings, add the root cert to the macOS system trust store.

From your Mac (assumes SSH config alias `ca` for the CA server):

```bash
ssh ca "docker cp ca-server:/home/step/certs/root_ca.crt /tmp/root_ca.crt" \
  && scp ca:/tmp/root_ca.crt . \
  && sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain root_ca.crt
```

> **Firefox note:** Firefox uses its own certificate store. Either import `root_ca.crt` manually in Firefox Settings > Certificates, or set `security.enterprise_roots.enabled` to `true` in `about:config` to make it read the system store.

### 4.4 Retrieve the admin provisioner password

The admin provisioner password is the same as `CA_PASSWORD` from your `.env` — `ca-entrypoint.sh` passes it to `step ca init` as both the key-encryption password and the provisioner password. You'll need it to request certificates and manage provisioners.

> **It cannot be changed after first boot.** It encrypts the CA's private keys, so
> `ca-entrypoint.sh` refuses to start if `CA_PASSWORD` no longer matches the value the CA
> was initialised with. Starting over means destroying the CA volume.

---

## 5. Configure Provisioners

step-ca uses **provisioners** to control who/what can request certificates and with what constraints. We need provisioners tailored to each component in our architecture.

### 5.1 Overview of provisioners

The shipped stack creates **exactly two**, both at first boot (`ca-entrypoint.sh`):

| Provisioner | Type | Purpose | Created by |
|---|---|---|---|
| `admin` | JWK | Default admin provisioner — manage other provisioners, manual cert requests, the eduVPN server's TLS certificate | `step ca init` |
| `device-certs` | JWK | Issue client certificates with a Device ID embedded in the CN | `ca-entrypoint.sh` |

Both share `CA_PASSWORD` as their provisioner password, so the demo needs no second secret.

> **No ACME provisioner.** Earlier drafts of this document added one for automated server
> certificates. It has been **removed**: nothing in the stack used it, and an enabled ACME
> directory is an additional *unauthenticated* issuance path on the CA that is the single
> source of device identity. Adding it is now an explicit opt-in — see §5.4.

### 5.2 Verify the provisioners exist

```bash
docker exec ca-server step ca provisioner list
```

You should see `admin` and `device-certs`. If `device-certs` is missing, the CA is
half-initialised and cannot issue device certificates — recreate the volume rather than
patching around it.

### 5.3 The device-certs provisioner (for client certificates)

`ca-entrypoint.sh` creates it for you, with the equivalent of:

```bash
docker exec -it ca-server \
  step ca provisioner add device-certs --type=JWK --create
```

This is the provisioner that issues certificates whose Common Name carries the Device ID.
Its password is `CA_PASSWORD`; `client/first-boot.sh` uses it non-interactively via
`--provisioner-password-file`.

### 5.4 (Optional) Add an ACME provisioner

If *your* deployment wants automated server-certificate issuance, add it deliberately:

```bash
docker exec -it ca-server \
  step ca provisioner add acme --type=ACME
```

> Weigh the trade-off first: ACME lets anything that can answer a challenge on a name get a
> certificate from this CA, with no provisioner password. On a CA that also mints device
> identities, that is a meaningfully wider attack surface. Scope it with a name policy, or
> use a separate CA for server certificates.

> After adding a provisioner, step-ca reloads its config automatically.

### 5.5 (Optional) Set certificate duration policies

step-ca's own default is 24 hours; `ca-entrypoint.sh` raises both provisioners to
`CA_LEAF_DURATION` (default `2160h` / 90 days) so a demo left running overnight does not
come back with expired certificates — neither image ships a renewal timer. To adjust:

```bash
docker exec -it ca-server \
  step ca provisioner update device-certs \
    --x509-max-dur=24h \
    --x509-default-dur=24h
```

For a real deployment you likely want the opposite of the demo: short-lived client
certificates (e.g. 24h) plus the renewal cron from §7.3, which aligns with Zero Trust and
limits the damage from a key you cannot revoke out-of-band.

---

## 6. Issue Certificates for Each Component

All commands below are run **on the CA server host** (or from any machine that has bootstrapped against the CA — see section 7).

### 6.1 Bootstrap the `step` CLI on the CA host

```bash
step ca bootstrap \
  --ca-url https://localhost:9000 \
  --fingerprint <ROOT_CA_FINGERPRINT> \
  --install
```

The `--install` flag adds the root cert to the system trust store so `curl` and other tools trust the CA.

### 6.2 eduVPN Server certificate

Request a certificate for the eduVPN/WireGuard server. Include all IPs and hostnames the server will be reachable on:

```bash
step ca certificate "eduvpn-server" eduvpn-server.crt eduvpn-server.key \
  --san <EDUVPN_SERVER_IP> \
  --san eduvpn-server \
  --provisioner admin
```

Enter the admin provisioner password when prompted. This produces:
- `eduvpn-server.crt` — the server's certificate (PEM)
- `eduvpn-server.key` — the server's private key (PEM)

Copy these to the eduVPN server.

### 6.3 Client certificate (with Device ID)

Each client device gets a certificate with its unique Device ID as the Common Name. This is how the eduVPN server identifies the device when it later queries Wazuh.

```bash
step ca certificate "device-<DEVICE_ID>" client.crt client.key \
  --provisioner device-certs
```

For example, for a device whose systemd machine-id is `<machine-id>` (32 lowercase hex chars):
```bash
step ca certificate "device-<machine-id>" device.crt device.key \
  --provisioner device-certs
```

The eduVPN server reads the **full CN** (`device-<machine-id>`) from the certificate and uses it verbatim as the Wazuh agent name — it is not shortened. The posture check requires the CN to match `device-` followed by the 32-hex machine-id.

### 6.4 Wazuh Server certificate (optional, for internal TLS)

If you want TLS on the Wazuh API (recommended):

```bash
step ca certificate "wazuh-server" wazuh-server.crt wazuh-server.key \
  --san <WAZUH_SERVER_IP> \
  --san wazuh-server \
  --provisioner admin
```

---

## 7. Bootstrap Other Servers (Clients & eduVPN)

Every machine that needs to trust the CA or request certificates must **bootstrap** first. This only needs to happen once per machine.

### 7.1 Install the `step` CLI on the remote machine

Same as section 2.4 — install the `step-cli` .deb package.

### 7.2 Bootstrap against the CA

```bash
step ca bootstrap \
  --ca-url https://<CA_SERVER_IP>:9000 \
  --fingerprint <ROOT_CA_FINGERPRINT> \
  --install
```

This:
- Downloads and stores the CA's root certificate at `~/.step/certs/root_ca.crt`
- Saves the CA URL in `~/.step/config/defaults.json`
- `--install` adds the root cert to the system trust store

After bootstrapping, the machine can request and renew certificates using `step ca certificate ...`.

### 7.3 Automated certificate renewal

step-ca issues short-lived certificates by design. Use `step ca renew` in a cron job or systemd timer to keep them fresh:

```bash
# Renew a certificate (must run before expiry)
step ca renew server.crt server.key --force
```

Example cron job (renew every 12 hours):
```cron
0 */12 * * * step ca renew /path/to/cert.crt /path/to/key.key --force --exec "systemctl reload your-service"
```

---

## 8. Reference

### Ports

| Port | Protocol | Purpose |
|---|---|---|
| 9000 | HTTPS | step-ca API — certificate requests, provisioner management, health checks |

### Volumes

| Volume | Container Path | Purpose |
|---|---|---|
| `step-ca-data` | `/home/step` | All CA state: keys, certificates, database, configuration |

### Environment Variables

Read by [`ca-entrypoint.sh`](ca-entrypoint.sh); the repo-root `docker-compose.yml` maps
them from the `CA_*` keys in [`.env`](../.env).

| Variable | `.env` key | Default | Purpose |
|---|---|---|---|
| `DOCKER_STEPCA_INIT_NAME` | `CA_NAME` | `eduVPN-CA` | CA issuer name (appears in certificates) — **first run only** |
| `DOCKER_STEPCA_INIT_DNS_NAMES` | `CA_DNS_NAMES` | `ca-server,localhost` | Comma-separated hostnames/IPs the CA serves on — **first run only** |
| `DOCKER_STEPCA_INIT_PASSWORD` | `CA_PASSWORD` | *(required)* | Password encrypting the CA private keys **and** both provisioners. Fixed at init; a later change makes the entrypoint refuse to start. |
| `CA_LEAF_DURATION` | `CA_LEAF_DURATION` | `2160h` | Default **and** max lifetime for leaf certificates from `admin` and `device-certs`. Applied at init. |

The listen address is fixed at `:9000` and the default provisioner name at `admin` by
`ca-entrypoint.sh`; neither is configurable through the environment. The upstream image's
`DOCKER_STEPCA_INIT_PROVISIONER_NAME` and `DOCKER_STEPCA_INIT_ADDRESS` are **not**
consulted.

### Useful commands

```bash
# Check CA health
step ca health --ca-url https://<CA_IP>:9000 --root ~/.step/certs/root_ca.crt

# List provisioners
docker exec ca-server step ca provisioner list

# Inspect a certificate
step certificate inspect cert.pem --short

# View root CA details
step certificate inspect root_ca.crt

# Get the root fingerprint
step certificate fingerprint root_ca.crt
```

### Further reading

- [step-ca documentation](https://smallstep.com/docs/step-ca/)
- [step CLI reference](https://smallstep.com/docs/step-cli/)
- [Provisioner configuration](https://smallstep.com/docs/step-ca/provisioners/)
- [Production hardening](https://smallstep.com/docs/step-ca/certificate-authority-server-production/)
- [Docker deployment tutorial](https://smallstep.com/docs/tutorials/docker-tls-certificate-authority/)
