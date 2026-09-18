#!/bin/sh
# SPDX-License-Identifier: MIT
# =============================================================================
# Posture-gated eduVPN client — first-boot provisioning
# =============================================================================
# Runs ONCE (sentinel-guarded) via eduvpn-client-first-boot.service. Establishes
# the device identity used everywhere as device-<machine-id>:
#   1. Persist a stable machine-id in the client-config volume.
#   2. Bootstrap trust against step-ca (installs the root in the trust store).
#   3. Request the device certificate (CN = device-<machine-id>) from the
#      device-certs provisioner — this is presented over mTLS to eduVPN.
#   4. Enroll the Wazuh agent under the SAME name and start it as a service.
#
# The eduVPN server correlates the two: the mTLS cert CN identifies the device,
# and the posture check queries Wazuh for that agent's status.
#
# `set -e`: a failed critical step (machine-id, CA bootstrap, device cert) aborts
# BEFORE the sentinel is written, so systemd retries next boot instead of leaving
# the device with no usable identity/cert. Wazuh enrollment stays best-effort
# (it fails closed at the server, and can be recovered independently).
# =============================================================================
set -eu

SENTINEL=/var/lib/eduvpn-client-first-boot.done
[ -f "$SENTINEL" ] && { echo "[client-first-boot] already provisioned"; exit 0; }

PKI=/pki
CA_URL=https://ca-server:9000
CA_PASSWORD="${CA_PASSWORD:-eduvpn-demo-ca-password}"
FQDN="${VPN_FQDN:-vpn.local}"        # from .env via compose
CONFDIR=/etc/eduvpn-client          # persistent named volume
WAZUH_MANAGER=wazuh.manager

mkdir -p "$CONFDIR"

echo "[client-first-boot] ===== 1. establishing persistent device identity ====="
IDFILE="$CONFDIR/machine-id"
if [ -s "$IDFILE" ]; then
    # Restore the persisted machine-id for a stable identity across container
    # recreation. In some runtimes systemd mounts /etc/machine-id read-only once
    # it is set, so this copy can fail — that is non-fatal: $IDFILE is the
    # authoritative source for the device identity (cert CN + Wazuh agent name).
    cp "$IDFILE" /etc/machine-id 2>/dev/null \
        || echo "[client-first-boot] note: /etc/machine-id not writable; using persisted id from $IDFILE"
else
    [ -s /etc/machine-id ] || systemd-machine-id-setup >/dev/null 2>&1
    cp /etc/machine-id "$IDFILE"
fi
# Always derive the identity from the persisted file, never from a possibly
# non-restored /etc/machine-id — keeps device-<id> stable across recreation.
DEVICE_ID="$(cat "$IDFILE")"
DEVICE_NAME="device-${DEVICE_ID}"
echo "[client-first-boot] device identity: ${DEVICE_NAME}"

echo "[client-first-boot] ===== 2. bootstrapping trust against step-ca ====="
FP="$(cat "$PKI/root_ca.fingerprint")"
step ca bootstrap --ca-url "$CA_URL" --fingerprint "$FP" --install --force

echo "[client-first-boot] ===== 3. requesting device certificate (CN=${DEVICE_NAME}) ====="
printf '%s' "$CA_PASSWORD" > /tmp/ca.pw
step ca certificate "$DEVICE_NAME" \
    "$CONFDIR/device.crt" "$CONFDIR/device.key" \
    --provisioner device-certs --provisioner-password-file /tmp/ca.pw --force
rm -f /tmp/ca.pw
chmod 600 "$CONFDIR/device.key"

echo "[client-first-boot] ===== 4. enrolling + starting the Wazuh agent ====="
# Point the agent at the manager and register under the device name. The manager
# runs with use_password=no, so no enrollment secret is required.
/var/ossec/bin/agent-auth -m "$WAZUH_MANAGER" -A "$DEVICE_NAME" \
    || echo "[client-first-boot] WARNING: agent-auth enrollment failed"
systemctl enable --now wazuh-agent || echo "[client-first-boot] WARNING: could not start wazuh-agent"

touch "$SENTINEL"
cat <<EOF
[client-first-boot] DONE
    device name : ${DEVICE_NAME}
    device cert : ${CONFDIR}/device.crt
    connect with: eduvpn-posture-connect --server ${FQDN} --ca ${PKI}/ca-bundle.crt
EOF
