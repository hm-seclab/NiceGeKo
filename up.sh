#!/usr/bin/env bash
# SPDX-License-Identifier: MIT
# =============================================================================
# One-command bring-up for the posture-gated eduVPN PoC
# =============================================================================
# Brings the whole single-host stack online:
#   CA (step-ca) → Wazuh (indexer/manager/dashboard) → eduVPN server → client
#
# First run also generates the Wazuh inter-node TLS certificates (a one-time
# step the Wazuh images require). Subsequent runs skip it.
#
# Usage:
#   ./up.sh            # generate certs if needed, build + start everything
#   ./up.sh --pull     # also pull newer base images
# =============================================================================
set -euo pipefail
cd "$(dirname "$0")"
# Load the central config (single source of truth for FQDN, secrets, versions).
set -a; . ./.env; set +a

# Optional: `./up.sh --pull` also pulls newer base images.
PULL=()
if [ "${1:-}" = "--pull" ]; then PULL=(--pull always); shift; fi

CERTS_DIR="wazuh-server/config/wazuh_indexer_ssl_certs"

# 1. Generate Wazuh inter-node TLS certs once (idempotent).
if [ ! -f "$CERTS_DIR/root-ca.pem" ]; then
    echo "==> Generating Wazuh TLS certificates (first run)…"
    docker compose -f wazuh-server/generate-certs.yml run --rm generator
    # The generator mounts the whole directory at /certificates/ and chmod -R's
    # everything it finds there, which catches the tracked .gitignore/.gitkeep
    # as collateral (644 -> 755, root-owned). Restore them, or a fresh checkout
    # is dirty the moment it runs its very first documented command.
    # The guard matters: the files are root-owned by then, so a user in the
    # docker group but not root cannot chmod them, and `set -e` would otherwise
    # abort the whole bring-up over two placeholder files.
    chmod 644 "$CERTS_DIR/.gitignore" "$CERTS_DIR/.gitkeep" 2>/dev/null \
        || echo "    (note: could not restore .gitignore/.gitkeep modes — root-owned)"
else
    echo "==> Wazuh TLS certificates already present — skipping generation."
fi

# 2. Build + start the whole stack. depends_on health-gates the ordering.
# ${PULL[@]+"${PULL[@]}"} expands to nothing when PULL is empty without tripping
# `set -u` on older bash (e.g. macOS's stock bash 3.2).
echo "==> Building and starting the stack…"
docker compose up -d --build ${PULL[@]+"${PULL[@]}"} "$@"

cat <<EOF

==> Stack is starting. Watch it settle with:  docker compose ps
    (Wazuh indexer/manager take ~1-2 min to report healthy on first boot.)

    eduVPN portal  : https://${VPN_FQDN}/     (add "127.0.0.1 ${VPN_FQDN}" to /etc/hosts)
    Wazuh dashboard: https://localhost:8443/

    Tear down with:  ./down.sh          (keep data)
                     ./down.sh --clean  (also remove volumes)
EOF
