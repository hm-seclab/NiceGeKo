# eduVPN Server — Bare-Metal Deployment on Debian 13

> ℹ️ **This describes the standalone / bare-metal deployment of this component.**
> For the one-command single-host Docker PoC, start at the [root README](../README.md)
> — there, `eduvpn-server/first-boot.sh` automates everything below. Hard-coded example
> IPs (`192.0.2.x`) are historical examples; in the Docker stack components resolve by
> name (`ca-server`, `wazuh.manager`, `vpn.local`).

This component is the core VPN gateway. It runs **bare-metal** on a dedicated Debian 13 (Trixie) server using the official [eduVPN deploy scripts](https://docs.eduvpn.org/server/v3/deploy-debian.html) from GÉANT/SURF.

> **Bare-metal vs. the shipped demo — the honest version.** The eduVPN server stack
> (Apache, PHP-FPM, vpn-user-portal, vpn-server-node, WireGuard, nftables) is designed and
> tested for native Linux deployment, and that is what this document describes. The PoC in
> this repository nevertheless **containerises it**: `eduvpn-server/Dockerfile` boots
> systemd as PID 1 and `first-boot.sh` runs the official `deploy_debian.sh` *inside* the
> container, so a single `docker compose up` reproduces the entire demo on one host.
>
> The trade-off is explicit rather than free. Containerising buys reproducibility, a
> one-command teardown, and a demo that a reviewer can run without dedicating three
> servers. It costs privilege and fidelity: the container runs `privileged` with
> `cgroup: host`, `NET_ADMIN` and `SYS_MODULE`, and `nftables.service` is deliberately
> **masked** — eduVPN's deploy installs a `flush ruleset` + `policy drop` lockdown that
> wipes Docker's in-netns DNS/NAT rules and cuts the container off from the CA and Wazuh.
> So the demo does **not** exercise eduVPN's host firewall, and tunnel routing is
> best-effort.
>
> What is identical in both deployments is the part the PoC is actually about: the posture
> gate, the mTLS handshake, and the overlay files. Follow this guide for a real deployment;
> use the Docker stack to evaluate the gate.

---

## Table of Contents

1. [Role in the Architecture](#1-role-in-the-architecture)
2. [Prerequisites](#2-prerequisites)
3. [Install eduVPN](#3-install-eduvpn)
4. [Post-Install Configuration](#4-post-install-configuration)
5. [Integrate the step-ca Certificate Authority](#5-integrate-the-step-ca-certificate-authority)
6. [Connect a Client](#6-connect-a-client)
7. [Firewall & Network](#7-firewall--network)
8. [Maintenance & Updates](#8-maintenance--updates)
9. [Reference](#9-reference)

---

## 1. Role in the Architecture

```
┌────────────────┐          ┌──────────────────────┐          ┌────────────────┐
│   CA Server    │          │    eduVPN Server      │          │  Wazuh Server  │
│   (step-ca)    │◄────────►│  (this server)        │◄────────►│  (Manager)     │
│   Port 9000    │  certs   │  Ports 443, 51820     │  API     │  Port 55000    │
└────────────────┘          └──────────┬───────────-┘          └────────────────┘
                                       │
                                       │ WireGuard tunnel
                                       │
                                ┌──────┴───────┐
                                │   Clients    │
                                └──────────────┘
```

In the standard installation (this guide), the eduVPN server:

- Runs the **vpn-user-portal** web interface for user management and config downloads.
- Runs the **vpn-server-node** daemon that manages WireGuard interfaces.
- Handles user authentication (local accounts, or LDAP/SAML/OIDC).
- Serves WireGuard configurations to authenticated users.

On top of that baseline, the PoC **extends this server with mTLS device authentication and
Wazuh posture checking** — that integration is implemented and shipped (§8.4, and
[`eduvpn-integration/`](../eduvpn-integration/README.md) for the authoritative write-up).
This guide covers the **baseline installation** first, so you can verify all components
work together before layering the posture gate on top.

---

## 2. Prerequisites

### 2.1 Server requirements

- **OS**: Debian 13 (Trixie) — clean minimal install, fully updated
- **Arch**: `amd64` / `x86_64` (only supported architecture)
- **Network**: Static public IPv4 (and ideally IPv6) address
- **DNS**: A valid DNS A record (and AAAA if using IPv6) pointing to this server
  - Example: `vpn.example.org → 203.0.113.10`
  - **You cannot use `localhost` or a bare IP address as the hostname**

### 2.2 Prepare the server

```bash
# Update the system
apt update && apt upgrade -y

# Set the hostname to match your DNS record
hostnamectl set-hostname vpn.example.org

# Verify
hostname -f
# Should output: vpn.example.org
```

Ensure your `/etc/hosts` file contains:
```
127.0.0.1   localhost
<YOUR_IP>   vpn.example.org
```

### 2.3 Verify network connectivity

The server needs to reach:

| Destination | Port | Purpose |
|---|---|---|
| Internet | TCP 443 | Download packages |
| CA Server | TCP 9000 | step-ca API (certificate requests) |
| Wazuh Server | TCP 55000 | Wazuh API (agent status queries) |

Clients need to reach **this server** on:

| Port | Protocol | Purpose |
|---|---|---|
| TCP 443 | HTTPS | Web portal + WireGuard-over-TCP |
| UDP 51820 | WireGuard | VPN data tunnel |

---

## 3. Install eduVPN

### 3.1 Download the deploy scripts

```bash
sudo apt -y install ca-certificates wget
wget https://codeberg.org/eduVPN/deploy/archive/v3.tar.gz
tar -xzf v3.tar.gz
cd deploy
```

### 3.2 Run the deploy script

```bash
sudo -s
./deploy_debian.sh
```

The script will prompt for three values:

1. **DNS name of the Web Server** — enter your FQDN (e.g. `vpn.example.org`)
2. **External Network Interface** — usually auto-detected (e.g. `eth0` or `ens3`), confirm or override
3. **Enable weekly automatic updates & reboot?** — recommended: `y`

The script will then:
- Install Apache, PHP-FPM, nftables, and other dependencies
- Add the official eduVPN APT repository (`repo.eduvpn.org`)
- Install `vpn-user-portal`, `vpn-server-node`, `vpn-maint-scripts`, `proxyguard-server`, and `openvpn`
- Generate a self-signed TLS certificate (temporary — replaced with a step-ca certificate in section 5)
- Configure Apache with HTTPS virtual hosts
- Configure WireGuard with auto-generated IP ranges
- Set up IP forwarding (`net.ipv4.ip_forward = 1`, `net.ipv6.conf.all.forwarding = 1`)
- Deploy nftables firewall rules
- Start all services

### 3.3 Create the first user

```bash
sudo -u www-data vpn-user-portal-account --add "vpn" --password "admin"
```

Save this password. Make the user an admin:

```bash
vim /etc/vpn-user-portal/config.php
```

Find `adminUserIdList` and add your user:

```php
'adminUserIdList' => ['vpn'],
```

### 3.4 Verify the installation

Open `vpn.example.org` in a browser. You should see the eduVPN user portal. Log in with the user you just created.

From the portal you can:
- Download WireGuard configuration files
- View connected clients (admin)
- Manage user accounts (admin)

---

## 4. Post-Install Configuration

### 4.1 Key configuration files

| File | Purpose |
|---|---|
| `/etc/vpn-user-portal/config.php` | Main portal configuration (profiles, auth, admin users) |
| `/etc/vpn-user-portal/keys/node.0.key` | Shared secret between portal and VPN node |
| `/etc/vpn-server-node/keys/node.key` | Copy of shared secret on the node side |
| `/etc/nftables.conf` | Firewall rules |
| `/etc/sysctl.d/70-vpn.conf` | IP forwarding settings |
| `/etc/apache2/sites-available/vpn.example.org.conf` | Apache virtual host |

### 4.2 VPN profile configuration

The deploy script auto-generates a default VPN profile in `/etc/vpn-user-portal/config.php`. Key settings:

```php
'ProfileList' => [
    [
        'profileId'   => 'default',
        'displayName' => 'Default',
        'hostName'    => 'vpn.example.org',
        'wRangeFour'  => '10.x.x.x/20',    // Auto-generated IPv4 range
        'wRangeSix'   => 'fdxx:xxxx:.../64', // Auto-generated IPv6 range
        'dnsServerList' => ['9.9.9.9', '2620:fe::fe'],
    ],
],
```

### 4.3 Apply configuration changes

After editing any configuration file, always run:

```bash
vpn-maint-apply-changes
```

This restarts WireGuard/OpenVPN interfaces and applies the new configuration.

---

## 5. Integrate the step-ca Certificate Authority

This section connects the eduVPN server to your step-ca instance so that:
- The web portal uses a CA-signed TLS certificate (replacing the self-signed one from the deploy script)
- The server trusts the CA root (required by the mTLS device authentication in §8.4)

### 5.1 Install the `step` CLI

```bash
curl -fsSL "https://github.com/smallstep/cli/releases/download/v0.29.0/step-cli_0.29.0-1_amd64.deb" -o /tmp/step-cli.deb
dpkg -i /tmp/step-cli.deb
rm /tmp/step-cli.deb
```

> **Note:** The Smallstep APT repository has a known issue (`Suites: debes` typo in their docs). Use the manual `.deb` install from GitHub releases instead.

Verify the installation:

```bash
step version
```

### 5.2 Bootstrap trust against the CA

Get the root fingerprint from the CA server (run this on the **CA server**):

```bash
docker exec ca-server step certificate fingerprint /home/step/certs/root_ca.crt
```

Then on **this (eduVPN) server**:

```bash
step ca bootstrap \
  --ca-url https://192.0.2.20:9000 \
  --fingerprint <ROOT_CA_FINGERPRINT>   \
  --install
```

This:
- Downloads the CA root certificate to `~/.step/certs/root_ca.crt`
- Saves the CA URL in `~/.step/config/defaults.json`
- `--install` adds the root cert to the system trust store so all TLS clients (curl, Apache, etc.) trust it

### 5.3 Request a TLS certificate for the web portal

```bash
step ca certificate "vpn.example.org" \
  /etc/ssl/certs/vpn.example.org.crt \
  /etc/ssl/private/vpn.example.org.key \
  --san vpn.example.org \
  --san 192.0.2.22 \
  --provisioner admin
```

Enter the admin provisioner password when prompted.

Apache is already configured to look for certificates at these paths (the deploy script put them there), so no Apache config changes are needed. Restart Apache to pick up the new cert:

```bash
systemctl restart apache2
```

### 5.4 Set up automatic certificate renewal

step-ca issues short-lived certificates by default (24 hours). Set up a cron job to renew before expiry:

```bash
cat << 'CRON' > /etc/cron.d/step-ca-renew
# Renew the eduVPN web portal certificate every 12 hours
0 */12 * * * root step ca renew /etc/ssl/certs/vpn.example.org.crt /etc/ssl/private/vpn.example.org.key --force --exec "systemctl reload apache2" >> /var/log/step-renew.log 2>&1
CRON

chmod 644 /etc/cron.d/step-ca-renew
```

### 5.5 Copy the CA root certificate for mTLS

Store a copy of the root CA cert in a well-known location for the mTLS device-cert
verification (see §8.4 for the implemented posture integration):

```bash
mkdir -p /etc/eduvpn-mtls
cp ~/.step/certs/root_ca.crt /etc/eduvpn-mtls/root_ca.crt
```

Apache uses this root cert to **verify client certificates** during the mTLS handshake (see
`apache-mtls-snippet.conf`).

---

## 6. Connect a Client

### 6.1 Option A: Standard eduVPN client (no mTLS — for initial testing)

For the baseline test, use the standard eduVPN flow:

1. **Install the official eduVPN app** on your client device:
   - Linux: `sudo apt install eduvpn-client` (from [eduvpn.org](https://www.eduvpn.org/client/))
   - Or use any WireGuard client

2. **If using the eduVPN app:**
   - Open the app → "Add server" → enter `https://vpn.example.org/`
   - Authenticate with your portal credentials
   - The app automatically downloads a WireGuard configuration and connects

3. **If using a manual WireGuard client:**
   - Log into the portal at `https://vpn.example.org/`
   - Go to "WireGuard" → Download a configuration file
   - Import it into your WireGuard client:
     ```bash
     sudo wg-quick up ./downloaded-config.conf
     ```

4. **Verify the connection:**
   ```bash
   # Check the WireGuard interface is up
   sudo wg show

   # Verify you can reach the internet through the tunnel
   curl ifconfig.me
   ```

### 6.2 Option B: Client with step-ca trust (preparation for mTLS)

To give a Linux client the identity the mTLS posture gate (§8.4) expects:

1. **Install the `step` CLI** on the client (same as section 5.1).

2. **Bootstrap against the CA:**
   ```bash
   step ca bootstrap \
     --ca-url https://<CA_SERVER_IP>:9000 \
     --fingerprint <ROOT_CA_FINGERPRINT> \
     --install
   ```

3. **Request a client certificate with Device ID:**
   ```bash
   DEVICE_ID=$(cat /etc/machine-id)   # or any unique identifier

   step ca certificate "device-${DEVICE_ID}" \
     /etc/eduvpn-client/device.crt \
     /etc/eduvpn-client/device.key \
     --provisioner device-certs
   ```

   Enter the `device-certs` provisioner password when prompted.

4. **Set up certificate renewal on the client:**
   ```bash
   cat << 'CRON' > /etc/cron.d/step-ca-client-renew
   0 */12 * * * root step ca renew /etc/eduvpn-client/device.crt /etc/eduvpn-client/device.key --force >> /var/log/step-client-renew.log 2>&1
   CRON
   ```

5. **Connect to the VPN** using Option A above. In this bare-metal walkthrough the client certificate isn't yet checked; in the Docker PoC the native posture gate (§8.4) verifies it on every `/v3/connect`.

### 6.3 Verifying the full chain

Once all three servers are running, verify connectivity between them:

```bash
# From the eduVPN server → CA server
step ca health --ca-url https://<CA_SERVER_IP>:9000

# From the eduVPN server → Wazuh server API
curl -k -u wazuh-wui:MyS3cr37P450r.*- https://<WAZUH_SERVER_IP>:55000/

# From a client → eduVPN server (portal)
curl https://vpn.example.org/vpn-user-portal

# From a client → CA server
step ca health --ca-url https://<CA_SERVER_IP>:9000
```

---

## 7. Firewall & Network

### 7.1 Default firewall (deployed by the script)

The deploy script installs nftables rules at `/etc/nftables.conf`. The defaults allow:

| Direction | Port | Protocol | Purpose |
|---|---|---|---|
| Inbound | 22 | TCP | SSH |
| Inbound | 80 | TCP | HTTP → HTTPS redirect |
| Inbound | 443 | TCP | Web portal + WireGuard-over-TCP |
| Inbound | 51820 | UDP | WireGuard |
| Forward | wg0 → ext | * | VPN client traffic to internet |

### 7.2 Additional rules you may need

For the integration with the other components, ensure the eduVPN server can reach:

```bash
# Allow outbound to CA server (if restrictive egress rules exist)
# TCP 9000 → CA server IP

# Allow outbound to Wazuh API
# TCP 55000 → Wazuh server IP
```

These are outbound connections, so typically no firewall changes are needed unless you have strict egress filtering.

### 7.3 Important note about Docker on the same host

If you ever run Docker on this server alongside eduVPN, be aware that Docker's iptables rules bypass nftables. This is a known issue. For the bare-metal setup, this is not a concern since Docker is not used on this server.

---

## 8. Maintenance & Updates

### 8.1 System updates

The eduVPN packages come from the official eduVPN APT repository. Standard Debian updates work:

```bash
apt update && apt upgrade -y
```

Or use the provided maintenance script:

```bash
vpn-maint-update-system
```

If the deploy script was told to enable weekly auto-updates, a cron job at `/etc/cron.weekly/vpn-maint-update-system` handles this automatically.

### 8.2 Applying configuration changes

After editing any configuration:

```bash
vpn-maint-apply-changes
```

### 8.3 Re-running the deploy script

The deploy script is designed to be **idempotent** — you can re-run it safely. It will:
- Skip steps that are already complete
- Update configuration to match the latest template
- Not destroy existing data

This is useful when upgrading to a new version of the deploy scripts.

### 8.4 The mTLS/Wazuh posture integration (as implemented)

> **Note:** an earlier draft of this document proposed adding the posture check as a
> *separate proxy/middleware layer* in a later phase. That is **not** how the PoC was
> built. The posture gate is a **native integration**: a small overlay of patched
> `vpn-user-portal` files running inside eduVPN's own request path. See
> [`eduvpn-integration/`](../eduvpn-integration/README.md) for the authoritative write-up.

The implemented approach:

- The posture check runs **inside** `vpn-user-portal` — `src/Http/VpnApiThreeModule.php`
  gates `POST /v3/connect` at the **top of the handler**, before any side effect of the
  request (session eviction, config issuance), calling a `PostureChecker` that queries the
  Wazuh Manager API. Placing it first matters: otherwise a non-compliant device could tear
  down the user's compliant, connected session and only *then* be refused.
- Apache is configured for `SSLVerifyClient optional` (vhost-level mTLS snippet) so the
  device-cert CN is exported as `SSL_CLIENT_S_DN_CN` for the portal to read.
- The overlay is applied by `first-boot.sh` on top of the packaged files (a handful of
  drop-in class replacements plus a supplementary autoloader), so the change is
  contained and re-applied on provisioning rather than forked wholesale.

---

## 9. Reference

### Installed packages

| Package | Purpose |
|---|---|
| `vpn-user-portal` | Web portal — user management, config downloads, admin interface |
| `vpn-server-node` | VPN daemon — manages WireGuard and OpenVPN interfaces |
| `vpn-maint-scripts` | Maintenance scripts (`vpn-maint-apply-changes`, `vpn-maint-update-system`) |
| `proxyguard-server` | WireGuard-over-TCP proxy for restricted networks |
| `openvpn` | OpenVPN daemon (fallback protocol) |

### Key commands

```bash
# Apply config changes (restarts WireGuard/OpenVPN)
vpn-maint-apply-changes

# Update system packages
vpn-maint-update-system

# Add a user
sudo -u www-data vpn-user-portal-account --add "username" --password "password"

# Check WireGuard status
wg show

# View portal logs
journalctl -u apache2 -f

# View VPN node logs
journalctl -u vpn-daemon -f
```

### Port summary

| Port | Protocol | Direction | Purpose |
|---|---|---|---|
| 22 | TCP | Inbound | SSH management |
| 80 | TCP | Inbound | HTTP redirect |
| 443 | TCP | Inbound | Web portal + WireGuard-over-TCP |
| 51820 | UDP | Inbound | WireGuard VPN tunnel |
| 9000 | TCP | Outbound | step-ca API (certificate ops) |
| 55000 | TCP | Outbound | Wazuh API (posture queries) |

### Further reading

- [eduVPN Server v3 documentation](https://docs.eduvpn.org/server/v3/)
- [Debian deployment guide](https://docs.eduvpn.org/server/v3/deploy-debian.html)
- [eduVPN deploy scripts (Codeberg)](https://codeberg.org/eduVPN/deploy)
- [step-ca documentation](https://smallstep.com/docs/step-ca/)
- [WireGuard documentation](https://www.wireguard.com/)
