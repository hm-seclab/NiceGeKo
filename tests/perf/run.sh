#!/usr/bin/env bash
# SPDX-License-Identifier: MIT
# =============================================================================
# Performance benchmark — posture-decision latency & throughput
# =============================================================================
# Drives POST /v3/connect with an unenrolled (ghost) cert → the posture check
# runs fully (Wazuh /agents query) and 403s before any WireGuard peer is issued,
# so the load has no server-side side effects. Runs the python driver from a
# python:3-slim container on the compose network. Requires a live stack.
# =============================================================================
set -uo pipefail
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
set -a; . "$REPO/.env"; set +a
# Derive compose-created names (works regardless of the checkout directory name).
NET=$(docker network ls --format "{{.Name}}" | grep -E "_eduvpn-net$" | head -1)
VOL_CERTS=$(docker volume ls --format "{{.Name}}" | grep -E "_client-config$" | head -1)
VOL_PKI=$(docker volume ls --format "{{.Name}}" | grep -E "_pki$" | head -1)

echo "==> preparing ghost cert (in the client-config volume) + token…"
docker exec eduvpn-client sh -c '
  printf "%s" "$CA_PASSWORD" > /tmp/capw
  step ca certificate device-22222222222222222222222222222222 /etc/eduvpn-client/ghost.crt /etc/eduvpn-client/ghost.key \
    --provisioner device-certs --provisioner-password-file /tmp/capw --force >/dev/null 2>&1
  rm -f /tmp/capw'
TOKEN="$(docker exec eduvpn-client headless-oauth.sh --server "$VPN_FQDN" --user "$DEMO_USER" --pass "$DEMO_PASS" \
        --ca /pki/ca-bundle.crt --cert /etc/eduvpn-client/device.crt --key /etc/eduvpn-client/device.key)" \
  || { echo "token acquisition failed"; exit 1; }

fail=0
drive() { # <mode> <N> <C>
  # Propagate the driver's exit status: it gates on the status distribution and
  # p99, and without this the tier printed numbers and always exited 0.
  docker run --rm --network "$NET" \
    -v "$VOL_CERTS":/certs:ro -v "$VOL_PKI":/pki:ro -v "$REPO/tests/perf":/perf:ro \
    -e SERVER="$VPN_FQDN" -e TOKEN="$TOKEN" -e MODE="$1" -e N="$2" -e C="$3" \
    -e CERT=/certs/ghost.crt -e KEY=/certs/ghost.key -e CA=/pki/ca-bundle.crt \
    python:3.12-slim python3 /perf/driver.py || fail=$((fail+1))
}

echo; echo "== Baseline: GET /v3/info (auth only, no posture check) =="
drive info 100 8

echo; echo "== Posture decision: POST /v3/connect (ghost → 403, no peer) =="
# JWT-cache cold vs warm: clear the server's in-process token cache first so the
# driver's 'cold(1st)' sample pays the Wazuh /authenticate round-trip. Derive the
# php-fpm unit name (the PHP minor tracks the base image; a 'php*-fpm' glob is NOT
# expanded by systemctl), matching tests/e2e/run.sh.
FPM="$(docker exec eduvpn-server sh -c "systemctl list-units --type=service --plain --no-legend | grep -oE 'php[0-9.]+-fpm' | head -1")"
docker exec eduvpn-server systemctl restart "$FPM" >/dev/null 2>&1 || true
sleep 2
# Gated sweep. Sized to the PROVIDER's budget, not to how fast we can push:
# Wazuh applies a GLOBAL max_request_per_minute (default 300) across the whole
# API, and the posture check spends one /agents call per /v3/connect. So a sweep
# is limited to ~300 posture decisions per minute IN TOTAL, regardless of
# concurrency — exceeding it makes Wazuh answer 429, the gate fails CLOSED, and
# the run measures the rate limiter instead of the posture path. 100 requests per
# level with a window-reset pause between levels keeps every measurement inside
# the budget and therefore comparable.
# Pause before the FIRST level too: the /v3/info baseline above has already
# spent part of the current window.
PAUSE="${PERF_PAUSE:-60}"
for c in 1 8 32; do
  echo "   (pausing ${PAUSE}s for the Wazuh rate-limit window to reset)"; sleep "$PAUSE"
  echo "-- concurrency=$c"
  drive connect 100 "$c"
done

# ---------------------------------------------------------------------------
# Saturation probe — REPORTED, NOT GATED
# ---------------------------------------------------------------------------
# Wazuh's API applies a GLOBAL max_request_per_minute (default 300). The posture
# check spends one /agents call per /v3/connect, so past roughly 5 connects/sec
# the provider — not the gate — becomes the bottleneck, and further requests fail
# CLOSED with "posture service unavailable". That is correct behaviour (deny
# rather than admit an unverified device), and it is a real property of the
# design worth measuring, so this point is reported as data rather than counted
# as a failure. THRESHOLD=0 disables the status gate for this run only.
echo
echo "   (pausing ${PAUSE}s before the saturation probe)"; sleep "$PAUSE"
echo "-- concurrency=64, 400 requests (saturation probe — reported, not gated)"
docker run --rm --network "$NET" \
  -v "$VOL_CERTS":/certs:ro -v "$VOL_PKI":/pki:ro -v "$REPO/tests/perf":/perf:ro \
  -e SERVER="$VPN_FQDN" -e TOKEN="$TOKEN" -e MODE=connect -e N=400 -e C=64 \
  -e THRESHOLD=0 \
  -e CERT=/certs/ghost.crt -e KEY=/certs/ghost.key -e CA=/pki/ca-bundle.crt \
  python:3.12-slim python3 /perf/driver.py || true
echo "   (any '403-unavailable' above is the Wazuh API rate limit, not a gate defect —"
echo "    see 'Scalability ceiling' in tests/README.md)"

echo
if [ "$fail" -eq 0 ]; then
  echo "perf: PASS (compare posture p50/p95 against the /v3/info baseline; note cold vs warm)"
else
  echo "perf: FAIL — $fail driver run(s) did not meet the status/latency gates"
fi
exit "$fail"
