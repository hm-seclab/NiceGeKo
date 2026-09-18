#!/usr/bin/env bash
# SPDX-License-Identifier: MIT
# =============================================================================
# Scalability sweep — posture latency vs number of registered Wazuh agents
# =============================================================================
# Bulk-registers synthetic agents via the Wazuh API, then measures the
# posture-decision p95 (POST /v3/connect with a ghost cert → 403, no peer) at
# each corpus size. This stresses Wazuh's /agents?name= lookup — the real
# scaling variable for the posture hot path. Requires a live stack.
#
# Synthetic agents are 'never_connected' shells (no live daemon): they scale the
# name LOOKUP, not SCA. They are best-effort deleted at the end.
# =============================================================================
set -uo pipefail
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
set -a; . "$REPO/.env"; set +a
# Derive compose-created names (works regardless of the checkout directory name).
NET=$(docker network ls --format "{{.Name}}" | grep -E "_eduvpn-net$" | head -1)
VOL_CERTS=$(docker volume ls --format "{{.Name}}" | grep -E "_client-config$" | head -1)
VOL_PKI=$(docker volume ls --format "{{.Name}}" | grep -E "_pki$" | head -1)
WUSER="$WAZUH_API_USER"
WPASS="$WAZUH_API_PASS"

echo "==> preparing ghost cert + token…"
docker exec eduvpn-client sh -c '
  printf "%s" "$CA_PASSWORD" > /tmp/capw
  step ca certificate device-11111111111111111111111111111111 /etc/eduvpn-client/ghost.crt /etc/eduvpn-client/ghost.key \
    --provisioner device-certs --provisioner-password-file /tmp/capw --force >/dev/null 2>&1
  rm -f /tmp/capw'
TOKEN="$(docker exec eduvpn-client headless-oauth.sh --server "$VPN_FQDN" --user "$DEMO_USER" --pass "$DEMO_PASS" \
        --ca /pki/ca-bundle.crt --cert /etc/eduvpn-client/device.crt --key /etc/eduvpn-client/device.key)" \
  || { echo "token failed"; exit 1; }

add_range() { # <from> <to> — register synthetic-<from..to> via the Wazuh API
  docker exec wazuh-manager sh -c '
    T=$(curl -sk -u "'"$WUSER"'":"'"$WPASS"'" -X POST "https://localhost:55000/security/user/authenticate?raw=true")
    i='"$1"'; to='"$2"'
    while [ "$i" -le "$to" ]; do
      curl -sk -o /dev/null -H "Authorization: Bearer $T" -H "Content-Type: application/json" \
        -X POST "https://localhost:55000/agents" -d "{\"name\":\"synthetic-$i\",\"ip\":\"any\"}"
      i=$((i+1))
    done'
}

fail=0
measure_p95() { # prints the p95 line from the driver
  # Keep stderr (the driver's gate messages go there) and propagate the exit
  # status through the pipe — previously '2>/dev/null | grep' discarded both the
  # failure output AND the status, so this tier could never fail.
  local out rc
  out="$(docker run --rm --network "$NET" \
    -v "$VOL_CERTS":/certs:ro -v "$VOL_PKI":/pki:ro -v "$REPO/tests/perf":/perf:ro \
    -e SERVER="$VPN_FQDN" -e TOKEN="$TOKEN" -e MODE=connect -e N=60 -e C=8 \
    -e CERT=/certs/ghost.crt -e KEY=/certs/ghost.key -e CA=/pki/ca-bundle.crt \
    python:3.12-slim python3 /perf/driver.py 2>&1)"; rc=$?
  printf '%s\n' "$out" | grep -E 'p50|throughput|FAIL'
  [ "$rc" -eq 0 ] || fail=$((fail+1))
  return 0
}

# Warm up Wazuh + the portal so the sweep measures steady state, not cold-start.
# Wazuh applies a GLOBAL max_request_per_minute (default 300) across its whole
# API. Agent REGISTRATION spends that same budget, so a 500-agent batch leaves
# nothing for the measurement that follows and every posture check fails CLOSED —
# which would be reported as a latency result rather than as an exhausted quota.
# Pause for the window to reset around every batch and before every measurement.
PAUSE="${SCALE_PAUSE:-60}"
settle() { echo "     (pausing ${PAUSE}s for the Wazuh rate-limit window to reset)"; sleep "$PAUSE"; }

echo "==> warming up (discarded)…"; measure_p95 >/dev/null 2>&1; fail=0

printf '\n%-28s %s\n' "synthetic agents registered" "posture latency"
printf '%-28s %s\n' "---------------------------" "---------------"

prev=0
for target in 0 100 500; do
  [ "$target" -gt "$prev" ] && { echo "  (registering synthetic-$((prev+1))..$target…)"; add_range "$((prev+1))" "$target"; prev="$target"; }
  echo "── + $target synthetic agents (plus the 2 real ones)"
  settle
  measure_p95 | sed 's/^/     /'
done

echo
echo "==> best-effort cleanup of synthetic agents…"
# Delete ONLY the agents this script created. The previous form deleted every
# never_connected agent, which would also purge a real device whose enrolment or
# agent start had failed — a state client/first-boot.sh explicitly tolerates.
docker exec wazuh-manager sh -c '
  T=$(curl -sk -u "'"$WUSER"'":"'"$WPASS"'" -X POST "https://localhost:55000/security/user/authenticate?raw=true")
  IDS=$(curl -sk -H "Authorization: Bearer $T" \
        "https://localhost:55000/agents?limit=2000&select=id,name&q=name~synthetic-" \
        | grep -oE "\"id\": *\"[0-9]+\"" | grep -oE "[0-9]+" | paste -sd, -)
  [ -n "$IDS" ] || { echo "  (no synthetic agents to purge)"; exit 0; }
  curl -sk -o /dev/null -H "Authorization: Bearer $T" -X DELETE \
    "https://localhost:55000/agents?agents_list=$IDS&older_than=0s&purge=true"
  echo "  (purged synthetic agents: $IDS)"' || true

echo
if [ "$fail" -eq 0 ]; then
  echo "scale: PASS (compare p95 across corpus sizes)"
else
  echo "scale: FAIL — $fail measurement(s) did not meet the status/latency gates"
fi
exit "$fail"
