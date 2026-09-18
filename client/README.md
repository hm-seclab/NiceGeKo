# Client — Bare-Metal Deployment on Debian 13

> ℹ️ **This describes the standalone / bare-metal deployment of this component.**
> For the one-command single-host Docker PoC, start at the [root README](../README.md)
> — there, `client/first-boot.sh` automates the device identity, cert, and agent
> enrollment below. Hard-coded example IPs (`192.0.2.x`) are historical examples; in
> the Docker stack components resolve by name (`ca-server`, `wazuh.manager`, `vpn.local`).

This component represents the end-user Linux device. This document describes running it **natively** on a bare-metal Debian host; in the one-command Docker PoC (see the [root README](../README.md)) the same client runs as a systemd container.

> **Bare-metal vs. the shipped demo — the honest version.** A real managed endpoint is bare
> metal, which is what this document describes: the Wazuh Agent's posture data is only as
> meaningful as its access to the host's process table, file system and network stack, and
> WireGuard is a kernel module. The PoC in this repository nevertheless **containerises the
> client too** (`client/Dockerfile`: systemd as PID 1, a per-container `/etc/machine-id`, a
> step-ca device certificate, an enrolled Wazuh agent, and the `eduvpn-posture-connect`
> CLI, running `privileged` with `cgroup: host` and `NET_ADMIN`).
>
> The trade-off is explicit. The demo needs a *reproducible* endpoint more than a realistic
> one, and it pays for that: the container reports the **container's** posture, so its
> process table and file system are the image's, SCA results are correspondingly thin, and
> the tunnel is best-effort. What the containerised client does reproduce faithfully is
> exactly what the posture gate consumes — a stable `device-<machine-id>` used as both the
> certificate CN and the Wazuh agent name, presented over mTLS on every `/v3/connect`.
>
> Follow this guide when you want a real endpoint; use the Docker stack to evaluate the
> gate.

---

## Table of Contents

1. [Role in the Architecture](#1-role-in-the-architecture)
2. [Prerequisites](#2-prerequisites)
3. [Derive the Device ID](#3-derive-the-device-id)
4. [Install & Register the Wazuh Agent](#4-install--register-the-wazuh-agent)
5. [Install the step CLI & Bootstrap CA Trust](#5-install-the-step-cli--bootstrap-ca-trust)
6. [Request a Client Certificate](#6-request-a-client-certificate)
7. [Set Up Certificate Auto-Renewal](#7-set-up-certificate-auto-renewal)
8. [Install the eduVPN Client](#8-install-the-eduvpn-client)
9. [Connect to the VPN](#9-connect-to-the-vpn)
10. [Verification](#10-verification)
11. [Reference](#11-reference)

---

## 1. Role in the Architecture

```
┌────────────────┐          ┌──────────────────────┐          ┌────────────────┐
│   CA Server    │          │    eduVPN Server      │          │  Wazuh Server  │
│   (step-ca)    │          │                       │◄────────►│  (Manager)     │
│   Port 9000    │          │  Ports 443, 51820     │  API     │  Port 55000    │
└───────┬────────┘          └──────────┬────────────┘          └───────┬────────┘
        │                              │                              │
        │  cert request                │ WireGuard tunnel             │ agent reports
        │                              │                              │
        └──────────────────┬───────────┘                              │
                           │                                          │
                    ┌──────┴──────┐                                   │
                    │   Client    │◄──────────────────────────────────-┘
                    │ (this host) │
                    └─────────────┘
```

This client device:

- Runs the **Wazuh Agent**, which reports system posture to the Wazuh Manager.
- Holds a **client certificate** issued by the CA Server, with a Device ID embedded in the Common Name.
- Establishes a **WireGuard tunnel** to the eduVPN Server via the standard eduVPN client.

The Device ID is the glue between the certificate and Wazuh. The eduVPN server extracts it from the certificate's CN and looks up the matching Wazuh agent by name. If the agent is active, the connection is allowed.

---

## 2. Prerequisites

### 2.1 Server requirements

- **OS**: Debian 13 (Trixie) — clean minimal install, fully updated
- **Arch**: `amd64` / `x86_64`
- **Network**: IP address with connectivity to all three servers

### 2.2 Network connectivity

The client needs to reach:

| Destination | Port | Protocol | Purpose |
|---|---|---|---|
| CA Server | TCP 9000 | HTTPS | Certificate requests and renewal |
| Wazuh Manager | TCP 1514 | TCP | Agent ↔ Manager communication |
| Wazuh Manager | TCP 1515 | TCP | Agent enrollment (first connection) |
| eduVPN Server | TCP 443 | HTTPS | Web portal (config downloads) |
| eduVPN Server | UDP 51820 | WireGuard | VPN data tunnel |

### 2.3 Prepare the system

```bash
apt update && apt upgrade -y
```

### 2.4 Information you need before starting


Collect these values from the other server operators before proceeding:

| Value | Source | Example |
|---|---|---|
| `CA_SERVER_IP` | CA Server operator | `192.0.2.20` |
| `ROOT_CA_FINGERPRINT` | CA Server: `docker exec ca-server step certificate fingerprint /home/step/certs/root_ca.crt` | `<ROOT_CA_FINGERPRINT>` |
| `device-certs` provisioner password | CA Server operator (set during provisioner creation) | `eduvpn-demo-ca-password` |
| `WAZUH_SERVER_IP` | Wazuh Server operator | `192.0.2.23` |
| `EDUVPN_SERVER_HOSTNAME` | eduVPN Server operator | `vpn.example.org` |

---

## 3. Derive the Device ID

Every client device needs a stable, unique identifier that will be used in **both** the Wazuh agent name and the certificate Common Name. This is how the eduVPN server correlates the two.

We use the machine's `/etc/machine-id` (a 32-character hex string that Debian generates at install time):

```bash
DEVICE_ID=$(cat /etc/machine-id)
echo "Device ID: ${DEVICE_ID}"
Device ID: a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6
```

The full identifier used throughout the system will be `device-<DEVICE_ID>`. **This exact string must match in both the Wazuh agent name and the certificate CN.** All subsequent steps reference this convention.

> **Verify `/etc/machine-id` exists and is non-empty.** If it's missing (unlikely on Debian), generate one with `systemd-machine-id-setup`.

---

## 4. Install & Register the Wazuh Agent

The Wazuh Agent runs as a background service and reports system posture (file integrity, vulnerability status, running processes, etc.) to the Wazuh Manager.

### 4.1 Install prerequisites

```bash
apt-get install -y gnupg apt-transport-https
```

### 4.2 Import the Wazuh GPG key and add the repository

```bash
curl -s https://packages.wazuh.com/key/GPG-KEY-WAZUH | \
  gpg --no-default-keyring --keyring gnupg-ring:/usr/share/keyrings/wazuh.gpg --import && \
  chmod 644 /usr/share/keyrings/wazuh.gpg

echo "deb [signed-by=/usr/share/keyrings/wazuh.gpg] https://packages.wazuh.com/4.x/apt/ stable main" | \
  tee -a /etc/apt/sources.list.d/wazuh.list

apt-get update
```

### 4.3 Install the agent

Set the Wazuh Manager IP and the agent name (which **must** follow the `device-<DEVICE_ID>` convention):

```bash
DEVICE_ID=$(cat /etc/machine-id)

WAZUH_MANAGER="192.0.2.23" \
WAZUH_AGENT_NAME="device-${DEVICE_ID}" \
apt-get install -y wazuh-agent
```

The `WAZUH_MANAGER` and `WAZUH_AGENT_NAME` environment variables are read by the package's post-install script and written into `/var/ossec/etc/ossec.conf`.

### 4.4 Verify the agent configuration

Check that the manager IP and agent name were set correctly:

```bash
grep -A1 '<address>' /var/ossec/etc/ossec.conf
# Should show: <address><WAZUH_SERVER_IP></address>
```

If the agent name was not set during installation, register manually:

```bash
DEVICE_ID=$(cat /etc/machine-id)
/var/ossec/bin/agent-auth -m "<WAZUH_SERVER_IP>" -A "device-${DEVICE_ID}"
```

> The Wazuh Manager is configured with `<use_password>no</use_password>`, so no enrollment password is needed.

### 4.5 Enable and start the agent

```bash
systemctl daemon-reload
systemctl enable wazuh-agent
systemctl start wazuh-agent
```

### 4.6 Verify the agent is running

```bash
systemctl status wazuh-agent
```

The service should show `active (running)`. You can also check from the Wazuh Manager side (ask the Wazuh operator to confirm the agent appears as **Active**).

### 4.7 Prevent unintended upgrades

Disable the Wazuh repository to prevent accidental upgrades, and hold the package version:

```bash
sed -i "s/^deb/#deb/" /etc/apt/sources.list.d/wazuh.list
apt-get update

echo "wazuh-agent hold" | dpkg --set-selections
```

---

## 5. Install the step CLI & Bootstrap CA Trust

The `step` CLI is used to request and renew certificates from the CA Server (step-ca).

### 5.1 Install the step CLI

> **Note:** The Smallstep APT repository has a known issue (`Suites: debes` typo in their docs). Use the manual `.deb` install from GitHub releases instead.

Download and install the latest `step-cli` package for `amd64`:

```bash
curl -fsSL "https://github.com/smallstep/cli/releases/download/v0.29.0/step-cli_0.29.0-1_amd64.deb" -o /tmp/step-cli.deb

dpkg -i /tmp/step-cli.deb
rm /tmp/step-cli.deb
```

Verify the installation:

```bash
step version
```

### 5.2 Bootstrap against the CA

This downloads the CA's root certificate and adds it to the system trust store:

```bash
step ca bootstrap \
  --ca-url https://192.0.2.20:9000 \
  --fingerprint <ROOT_CA_FINGERPRINT> \
  --install
```

This:
- Downloads the root CA certificate to `~/.step/certs/root_ca.crt`
- Saves the CA URL in `~/.step/config/defaults.json`
- `--install` adds the root cert to the system trust store so all TLS clients trust it

### 5.3 Verify CA connectivity

```bash
step ca health
```

Should output `ok`.

---

## 6. Request a Client Certificate

The client certificate embeds the Device ID in its Common Name. The eduVPN server will later extract this CN and query Wazuh for an agent with the matching name.

### 6.1 Create the certificate directory

```bash
mkdir -p /etc/eduvpn-client
chmod 700 /etc/eduvpn-client
```

### 6.2 Request the certificate

```bash
DEVICE_ID=$(cat /etc/machine-id)

step ca certificate "device-${DEVICE_ID}" \
  /etc/eduvpn-client/device.crt \
  /etc/eduvpn-client/device.key \
  --provisioner device-certs
```

Enter the `device-certs` provisioner password when prompted (this is the device-identity
provisioner — the same one `client/first-boot.sh` uses).

This produces:
- `/etc/eduvpn-client/device.crt` — the client's certificate (PEM)
- `/etc/eduvpn-client/device.key` — the client's private key (PEM)

### 6.3 Verify the certificate

```bash
step certificate inspect /etc/eduvpn-client/device.crt --short
```

Confirm the **Subject** shows your Device ID, e.g.:

```
X.509v3 TLS Certificate (ECDSA P-256) [Serial: ...]
  Subject:     device-a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6
  Issuer:      eduVPN-CA Intermediate CA
  Valid from:  ...
  Valid to:    ...   (typically 24 hours)
```

### 6.4 Secure the private key

```bash
chmod 600 /etc/eduvpn-client/device.key
```

---

## 7. Set Up Certificate Auto-Renewal

step-ca issues short-lived certificates by default (24 hours). A cron job renews the certificate before it expires:

```bash
cat << 'CRON' > /etc/cron.d/step-ca-client-renew
# Renew the client device certificate every 12 hours
0 */12 * * * root step ca renew /etc/eduvpn-client/device.crt /etc/eduvpn-client/device.key --force >> /var/log/step-client-renew.log 2>&1
CRON

chmod 644 /etc/cron.d/step-ca-client-renew
```

Verify the cron file is valid:

```bash
cat /etc/cron.d/step-ca-client-renew
```

> **Note:** `--force` renews even if the certificate hasn't expired yet. This ensures the certificate is always fresh. If renewal fails, the old cert remains valid until its original expiry.

---

## 8. Install the eduVPN Client

### 8.1 Install WireGuard tools

WireGuard is included in the Linux kernel since 5.6. Install the userspace tools:

```bash
apt-get install -y wireguard-tools
```

### 8.2 Install the eduVPN client application

Use the official eduVPN install script (supports Debian, Ubuntu, Fedora, CentOS):

```bash
curl --proto '=https' --tlsv1.2 https://docs.eduvpn.org/client/linux/install.sh -O
bash ./install.sh
```

> **Source:** [eduVPN Linux client installation docs](https://docs.eduvpn.org/client/linux/installation.html)

---

## 9. Connect to the VPN

### 9.1 Option A: Using the eduVPN app (GUI)

1. Open the eduVPN client application.
2. Click **"Add server"** and enter `https://<EDUVPN_SERVER_HOSTNAME>/`.
3. Authenticate with your portal credentials.
4. The app downloads a WireGuard configuration and connects automatically.

### 9.2 Option B: Manual WireGuard configuration

If you prefer the command line or need to test without the app:

1. Log into the eduVPN portal at `https://<EDUVPN_SERVER_HOSTNAME>/`.
2. Go to **WireGuard** — select **UDP** and copy the displayed configuration.
3. Paste it into a new file and bring up the tunnel:

```bash
sudo vim /etc/wireguard/eduvpn.conf   # paste the config here
chmod 600 /etc/wireguard/eduvpn.conf
wg-quick up eduvpn
```

To bring the tunnel down:

```bash
wg-quick down eduvpn
```

To start the tunnel automatically on boot:

```bash
systemctl enable wg-quick@eduvpn
```

> **Note:** This is the standard eduVPN browser flow. In this bare-metal walkthrough the
> client certificate isn't verified; in the Docker PoC the eduVPN server's **native
> posture gate** checks it (and the Wazuh agent status) on every `/v3/connect` — see
> [`eduvpn-integration/`](../eduvpn-integration/README.md).

### 9.3 Verify the tunnel

```bash
# Check the tunnel is up and a handshake occurred
sudo wg show

# Check you got a VPN IP
ip addr show eduvpn

# Ping the VPN server's tunnel IP
ping 10.62.176.1
```

In `wg show`, look for a **latest handshake** timestamp — if it shows a recent time (seconds/minutes ago), the tunnel is working. If there's no handshake, the connection failed.

---

## 10. Verification

Run these checks to confirm everything is correctly set up.

### 10.1 Wazuh Agent

```bash
# Agent service is running
systemctl is-active wazuh-agent
# Expected: active

# Agent name matches Device ID convention
DEVICE_ID=$(cat /etc/machine-id)
grep "device-${DEVICE_ID}" /var/ossec/etc/ossec.conf && echo "OK: agent name matches" || echo "WARN: check agent name"
```

Ask the Wazuh operator to confirm from the Manager side:

```bash
# Run on the Wazuh server (or any machine with API access)
TOKEN=$(curl -sk -u wazuh-wui:'MyS3cr37P450r.*-' \
  https://localhost:55000/security/user/authenticate \
  | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['token'])")

curl -sk -H "Authorization: Bearer $TOKEN" \
  "https://localhost:55000/agents?name=device-a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6&select=id,name,status"
```

The agent should show `"status": "active"`.

### 10.2 CA Trust and Certificate

```bash
# CA is reachable
step ca health
# Expected: ok

# Certificate is valid and has the correct CN
step certificate inspect /etc/eduvpn-client/device.crt --short

# Certificate chain is trusted
step certificate verify /etc/eduvpn-client/device.crt --roots ~/.step/certs/root_ca.crt
```

### 10.3 VPN Connectivity

```bash
# WireGuard interface is up
wg show

# Traffic is routed through the tunnel
ping 10.62.176.1
```

### 10.4 Cross-component naming consistency

The most critical check — the identifier must be identical in the certificate CN and the Wazuh agent name:

```bash
DEVICE_ID=$(cat /etc/machine-id)
EXPECTED="device-${DEVICE_ID}"

echo "Expected identifier: ${EXPECTED}"
echo ""

# Certificate CN
CERT_CN=$(step certificate inspect /etc/eduvpn-client/device.crt --format json 2>/dev/null \
  | python3 -c "import sys,json; print(json.load(sys.stdin)['subject']['common_name'])" 2>/dev/null)
echo "Certificate CN:     ${CERT_CN:-NOT FOUND}"

# Wazuh agent name (from local config)
AGENT_NAME=$(/var/ossec/bin/manage_agents -l 2>/dev/null | grep -oP 'Name: \K[^,]+' | head -1)
echo "Wazuh agent name:   ${AGENT_NAME:-check manually}"

echo ""
if [ "${CERT_CN}" = "${EXPECTED}" ]; then
  echo "PASS: Certificate CN matches expected identifier"
else
  echo "FAIL: Certificate CN does not match (got '${CERT_CN}', expected '${EXPECTED}')"
fi
```

---

## 11. Reference

### Installed components

| Component | Purpose |
|---|---|
| `wazuh-agent` | Reports system posture to Wazuh Manager |
| `step-cli` | Requests and renews certificates from step-ca |
| `wireguard-tools` | Userspace tools for managing WireGuard tunnels |
| `eduvpn-client` | Official eduVPN client for portal auth and config download |

### Key file paths

| Path | Purpose |
|---|---|
| `/etc/eduvpn-client/device.crt` | Client certificate (PEM) with Device ID in CN |
| `/etc/eduvpn-client/device.key` | Client private key (PEM) |
| `/var/ossec/etc/ossec.conf` | Wazuh Agent configuration |
| `/var/ossec/logs/ossec.log` | Wazuh Agent logs |
| `~/.step/certs/root_ca.crt` | CA root certificate (downloaded during bootstrap) |
| `~/.step/config/defaults.json` | step CLI defaults (CA URL, fingerprint) |
| `/etc/wireguard/eduvpn.conf` | WireGuard tunnel configuration (if using manual setup) |
| `/var/log/step-client-renew.log` | Certificate renewal log |
| `/etc/cron.d/step-ca-client-renew` | Certificate renewal cron job |

### Key commands

```bash
# Check Wazuh Agent status
systemctl status wazuh-agent

# Restart Wazuh Agent
systemctl restart wazuh-agent

# Check CA health
step ca health

# Inspect the client certificate
step certificate inspect /etc/eduvpn-client/device.crt --short

# Manually renew the certificate
step ca renew /etc/eduvpn-client/device.crt /etc/eduvpn-client/device.key --force

# Bring up the VPN tunnel (manual config)
wg-quick up eduvpn

# Check WireGuard status
wg show

# View Wazuh Agent logs
tail -f /var/ossec/logs/ossec.log

# View certificate renewal logs
tail -f /var/log/step-client-renew.log
```

### Port summary (outbound from client)

| Destination | Port | Protocol | Purpose |
|---|---|---|---|
| CA Server | 9000 | TCP/HTTPS | Certificate requests and renewal |
| Wazuh Manager | 1514 | TCP | Agent ↔ Manager communication |
| Wazuh Manager | 1515 | TCP | Agent enrollment (first connection only) |
| eduVPN Server | 443 | TCP/HTTPS | Web portal |
| eduVPN Server | 51820 | UDP | WireGuard VPN tunnel |

### Further reading

- [Wazuh Agent installation on Debian](https://documentation.wazuh.com/current/installation-guide/wazuh-agent/wazuh-agent-package-linux.html)
- [step CLI documentation](https://smallstep.com/docs/step-cli/)
- [eduVPN Linux client](https://www.eduvpn.org/client/)
- [WireGuard quick start](https://www.wireguard.com/quickstart/)
