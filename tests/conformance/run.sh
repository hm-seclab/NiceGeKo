#!/usr/bin/env bash
# SPDX-License-Identifier: MIT
# =============================================================================
# Standards conformance / compatibility (ties to the design-concept doc)
# =============================================================================
# Validates that (1) real live Wazuh output maps onto the canonical Device
# Health Record schema, and (2) the eduVPN v3 discovery contract holds.
# Runs the python validator in python:3.12-slim on the compose network. Live stack.
# =============================================================================
set -uo pipefail
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
set -a; . "$REPO/.env"; set +a
# Derive compose-created names (works regardless of the checkout directory name).
NET=$(docker network ls --format "{{.Name}}" | grep -E "_eduvpn-net$" | head -1)
VOL_PKI=$(docker volume ls --format "{{.Name}}" | grep -E "_pki$" | head -1)

# Use the PERSISTED machine-id, which client/first-boot.sh declares authoritative
# for the device identity (cert CN + Wazuh agent name). /etc/machine-id may be a
# fresh, non-restored id after container recreation — tests/e2e/run.sh was already
# corrected to read this file; conformance was missed.
DEVICE="device-$(docker exec eduvpn-client cat /etc/eduvpn-client/machine-id)"
echo "==> validating conformance for live device: $DEVICE"

exec docker run --rm --network "$NET" \
  -v "$VOL_PKI":/pki:ro -v "$REPO/tests/conformance":/conf:ro \
  -e WAZUH_URL=https://wazuh.manager:55000 -e WAZUH_USER="$WAZUH_API_USER" -e WAZUH_PASS="$WAZUH_API_PASS" \
  -e DEVICE="$DEVICE" -e VPN_URL=https://$VPN_FQDN -e CA=/pki/ca-bundle.crt \
  -e SCHEMA=/conf/device-health-record.schema.json \
  python:3.12-slim sh -c 'pip install --quiet --disable-pip-version-check "jsonschema==4.23.0" && python3 /conf/validate.py'
