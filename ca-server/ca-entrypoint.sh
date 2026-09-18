#!/bin/bash
# SPDX-License-Identifier: MIT
# =============================================================================
# step-ca entrypoint for the posture-gated eduVPN PoC
# =============================================================================
# Extends the stock smallstep/step-ca boot with two PoC-specific jobs:
#
#   1. On first boot, initialise the two-tier PKI (root + intermediate) with a
#      known demo password and add the provisioners the PoC relies on:
#        - device-certs (JWK) : clients request their device-identity cert here
#                               (CN = device-<machine-id>), presented over mTLS.
#      (deliberately NO acme provisioner — see the note at the add step below)
#
#   2. On every boot, publish the trust material to the shared /pki volume:
#        - root_ca.crt         : the root certificate
#        - ca-bundle.crt       : intermediate + root, for Apache SSLCACertificateFile
#        - root_ca.fingerprint : SHA-256 fingerprint for `step ca bootstrap`
#
#      This eliminates the manual fingerprint copy-paste the multi-host setup
#      needed — the eduVPN server and client just read these files from /pki.
#
# DEMO SECRET: the CA key-encryption password comes from
# DOCKER_STEPCA_INIT_PASSWORD. It is a committed demo default. CHANGE FOR
# PRODUCTION (and move it out of the compose file into a real secret store).
# =============================================================================
set -eo pipefail

export STEPPATH="${STEPPATH:-/home/step}"
CA_JSON="$STEPPATH/config/ca.json"
PWFILE="$STEPPATH/secrets/password"
PKI_DIR="${PKI_DIR:-/pki}"

CA_NAME="${DOCKER_STEPCA_INIT_NAME:-eduVPN-CA}"
CA_DNS_NAMES="${DOCKER_STEPCA_INIT_DNS_NAMES:-ca-server,localhost}"
CA_PASSWORD="${DOCKER_STEPCA_INIT_PASSWORD:?DOCKER_STEPCA_INIT_PASSWORD must be set}"
# Leaf-certificate lifetime (device certs + the eduVPN server's TLS cert).
# 90 days: long enough that a demo stack does not silently expire between
# sessions, short enough to stay the mitigation for having no CRL/OCSP.
LEAF_DUR="${CA_LEAF_DURATION:-2160h}"

mkdir -p "$STEPPATH/secrets"

INIT_DONE="$STEPPATH/.poc-init-done"

# Guard on OUR sentinel, not on ca.json: `step ca init` writes ca.json before the
# provisioners are added, so a failure in between would otherwise be latched as
# "already initialised" and leave the CA permanently unable to issue device certs.
if [ ! -f "$INIT_DONE" ]; then
    if [ -f "$CA_JSON" ]; then
        echo "[ca-init] FATAL: $CA_JSON exists but initialisation never completed."
        echo "[ca-init] The CA is in a half-initialised state (likely a failure during a"
        echo "[ca-init] previous first boot). Recreate the volume: ./down.sh --clean && ./up.sh"
        exit 1
    fi
    echo "[ca-init] initialising step-ca PKI (name=$CA_NAME dns=$CA_DNS_NAMES)"
    printf '%s' "$CA_PASSWORD" > "$PWFILE"
    chmod 600 "$PWFILE"

    step ca init \
        --name "$CA_NAME" \
        --dns "$CA_DNS_NAMES" \
        --provisioner admin \
        --password-file "$PWFILE" \
        --provisioner-password-file "$PWFILE" \
        --address :9000

    # Add provisioners offline (direct edit of ca.json; picked up at startup).
    #
    # Certificate lifetime: step-ca's default is 24h, which gave the whole demo a
    # 24-hour shelf life — a stack left running overnight came back with an expired
    # device certificate and an expired portal TLS certificate, and neither image
    # ships a renewal timer. `docker restart` does NOT re-provision (the first-boot
    # sentinels live in the container's writable layer), so recovery needed a
    # recreate, from a confusing "certificate has expired" error. Issue longer-lived
    # leaves instead. See the README limitation about revocation.
    step ca provisioner add device-certs --type JWK --create \
        --ca-config "$CA_JSON" --password-file "$PWFILE" \
        --x509-default-dur="$LEAF_DUR" --x509-max-dur="$LEAF_DUR"
    # The admin provisioner is created by `step ca init` above and issues the
    # eduVPN server's TLS certificate, so it needs the same treatment.
    step ca provisioner update admin --ca-config "$CA_JSON" \
        --x509-default-dur="$LEAF_DUR" --x509-max-dur="$LEAF_DUR"
    # NOTE: deliberately NO ACME provisioner. Nothing in this stack uses ACME, and
    # an enabled ACME directory is an extra unauthenticated issuance path on the CA
    # that is the single source of device identity. Add one explicitly if you need
    # it rather than having it on by default.

    # Mark initialisation complete only after the provisioners exist. ca.json is
    # written by `step ca init` above, so keying the "already initialised" guard on
    # it would let a failure between the two steps strand the CA with only the
    # admin provisioner — healthy, but unable to ever issue a device certificate.
    touch "$INIT_DONE"

    echo "[ca-init] PKI ready with provisioners: admin, device-certs"
else
    # Restart against an existing volume: make sure the password file is present.
    if [ ! -f "$PWFILE" ]; then
        printf '%s' "$CA_PASSWORD" > "$PWFILE"
        chmod 600 "$PWFILE"
    elif [ "$(cat "$PWFILE")" != "$CA_PASSWORD" ]; then
        # CA_PASSWORD was changed in .env after the CA was initialised. The CA keys
        # on the persistent volume are still encrypted with the ORIGINAL password,
        # so step-ca must keep using it — but both first-boot scripts read the NEW
        # value from the environment and would fail every `step ca certificate`
        # call, leaving the server up with no gate and the client with no identity.
        # Fail loudly here instead of degrading silently.
        echo "[ca-init] FATAL: CA_PASSWORD does not match the password this CA was initialised with."
        echo "[ca-init] The CA private keys on the 'step-ca-data' volume are encrypted with the"
        echo "[ca-init] original password; changing CA_PASSWORD afterwards cannot re-key them."
        echo "[ca-init] Either restore the previous CA_PASSWORD in .env, or start over with:"
        echo "[ca-init]     ./down.sh --clean && ./up.sh"
        exit 1
    fi
fi

# Publish trust material to the shared volume (idempotent, runs every boot).
if [ -d "$PKI_DIR" ] && [ -w "$PKI_DIR" ]; then
    cp -f "$STEPPATH/certs/root_ca.crt" "$PKI_DIR/root_ca.crt"
    cat "$STEPPATH/certs/intermediate_ca.crt" "$STEPPATH/certs/root_ca.crt" \
        > "$PKI_DIR/ca-bundle.crt"
    step certificate fingerprint "$STEPPATH/certs/root_ca.crt" \
        > "$PKI_DIR/root_ca.fingerprint"
    # These are public trust material (no secrets) — make them readable by any
    # consumer UID regardless of the CA's umask.
    chmod 644 "$PKI_DIR/root_ca.crt" "$PKI_DIR/ca-bundle.crt" "$PKI_DIR/root_ca.fingerprint"
    echo "[ca-init] published root_ca.crt, ca-bundle.crt, root_ca.fingerprint to $PKI_DIR"
else
    echo "[ca-init] WARNING: $PKI_DIR not writable — skipping PKI publish"
fi

echo "[ca-init] starting step-ca"
exec step-ca --password-file "$PWFILE" "$CA_JSON"
