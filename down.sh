#!/usr/bin/env bash
# SPDX-License-Identifier: MIT
# =============================================================================
# Tear down the posture-gated eduVPN PoC stack
# =============================================================================
#   ./down.sh          stop + remove containers, keep named volumes (data)
#   ./down.sh --clean  also remove named volumes (CA keys, Wazuh data, PKI)
# =============================================================================
set -euo pipefail
cd "$(dirname "$0")"

if [ "${1:-}" = "--clean" ]; then
    echo "==> Removing containers AND volumes…"
    docker compose down --volumes --remove-orphans
else
    echo "==> Removing containers (volumes kept)…"
    docker compose down --remove-orphans
fi
