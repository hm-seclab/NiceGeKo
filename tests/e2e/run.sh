#!/usr/bin/env bash
# SPDX-License-Identifier: MIT
# =============================================================================
# End-to-end functional + security scenario suite
# =============================================================================
# Formalizes and EXTENDS demo.sh: drives the eduvpn-client through /v3/connect
# (--dry-run, so we assert the 200-vs-403 decision, not the tunnel) and checks
# each outcome's exit code + reason. Requires a live stack (./up.sh).
#
# Exit-code contract from the CLI: 0 = pass, 2 = posture reject (403), 1 = error.
# =============================================================================
set -uo pipefail
# Central config (FQDN + demo secrets) from the repo-root .env.
set -a; . "$(dirname "$0")/../../.env"; set +a

SERVER="$VPN_FQDN"
CLIENT=eduvpn-client
CA=/pki/ca-bundle.crt
DEV_CRT=/etc/eduvpn-client/device.crt
DEV_KEY=/etc/eduvpn-client/device.key
CAPW="$CA_PASSWORD"

red(){ printf '\033[31m%s\033[0m\n' "$*"; }; grn(){ printf '\033[32m%s\033[0m\n' "$*"; }
rule(){ printf '\n\033[1m──── %s ────\033[0m\n' "$*"; }
dex(){ docker exec "$CLIENT" "$@"; }

pass=0; fail=0; skip=0
# check <desc> <want_rc> <want_substr> -- <cli args...>
check(){ local d="$1" rc="$2" sub="$3"; shift 3; [ "$1" = "--" ] && shift
  rule "$d"
  local out r
  out="$(dex eduvpn-posture-connect --server "$SERVER" --ca "$CA" --dry-run --token "$TOKEN" "$@" 2>&1)"; r=$?
  if [ "$r" = "$rc" ] && printf '%s' "$out" | grep -qiF -- "$sub"; then
    grn "  ✔ PASS (exit $r, matched \"$sub\")"; pass=$((pass+1))
  else
    red "  ✗ FAIL (exit $r, wanted $rc + \"$sub\")"; printf '%s\n' "$out" | sed 's/^/      /' | tail -6; fail=$((fail+1))
  fi
}
skipmsg(){ rule "$1"; printf '  \033[33m● SKIP\033[0m %s\n' "$2"; skip=$((skip+1)); }

echo "==> obtaining OAuth token (headless)…"
TOKEN="$(dex headless-oauth.sh --server "$SERVER" --user "$DEMO_USER" --pass "$DEMO_PASS" --ca "$CA" --cert "$DEV_CRT" --key "$DEV_KEY")" \
  || { red "OAuth failed — is the stack up?"; exit 1; }

echo "==> issuing fixture certificates in the client…"
dex sh -c "printf '%s' '$CAPW' > /tmp/capw
  # valid-FORMAT but unenrolled device (ghost): device- + 32 hex, no Wazuh agent
  step ca certificate device-deadbeefdeadbeefdeadbeefdeadbeef /tmp/ghost.crt /tmp/ghost.key --provisioner device-certs --provisioner-password-file /tmp/capw --force >/dev/null 2>&1
  # valid CA-signed cert whose CN is NOT device-<32hex> (invalid format)
  step ca certificate laptop-x /tmp/inv.crt /tmp/inv.key --provisioner device-certs --provisioner-password-file /tmp/capw --force >/dev/null 2>&1
  # self-signed cert from an untrusted CA (mTLS must reject)
  step certificate create device-selfsigned /tmp/ss.crt /tmp/ss.key --profile self-signed --subtle --no-password --insecure --force >/dev/null 2>&1
  rm -f /tmp/capw"

# ---------------------------------------------------------------------------
# FUNCTIONAL
# ---------------------------------------------------------------------------
check "Valid device + active agent → 200 PASSED" \
      0 "POSTURE CHECK PASSED" -- --cert "$DEV_CRT" --key "$DEV_KEY"

# ---------------------------------------------------------------------------
# SECURITY / negative
# ---------------------------------------------------------------------------
check "No device certificate → 403 device cert required" \
      2 "device certificate required" -- --cert "" --key ""

check "Invalid CN format (laptop-x) → 403" \
      2 "invalid device certificate CN format" -- --cert /tmp/inv.crt --key /tmp/inv.key

check "Unknown/ghost device → 403 no agent registered" \
      2 "no Wazuh agent registered" -- --cert /tmp/ghost.crt --key /tmp/ghost.key

check "Untrusted (self-signed) cert → mTLS reject" \
      2 "device certificate required" -- --cert /tmp/ss.crt --key /tmp/ss.key

# Auth enforcement: a garbage bearer token must be rejected by the API (401).
rule "Garbage OAuth token → 401 (auth enforced)"
out="$(dex eduvpn-posture-connect --server "$SERVER" --ca "$CA" --dry-run \
      --token garbage.token.value --cert "$DEV_CRT" --key "$DEV_KEY" 2>&1)"; r=$?
if [ "$r" = 1 ] && printf '%s' "$out" | grep -qiE '401|invalid_token'; then
  grn "  ✔ PASS (exit $r, 401)"; pass=$((pass+1))
else red "  ✗ FAIL (exit $r)"; printf '%s\n' "$out" | sed 's/^/      /' | tail -4; fail=$((fail+1)); fi

# ---------------------------------------------------------------------------
# SCA threshold (config override, reversible) — only if the agent has SCA data
# ---------------------------------------------------------------------------
# Use the PERSISTED machine-id (authoritative for the device cert CN + agent name),
# not /etc/machine-id which may be a fresh non-restored id after container recreation.
DID="$(dex cat /etc/eduvpn-client/machine-id)"; AGENT="device-$DID"
TOK="$(docker exec wazuh-manager sh -c "curl -sk -u ${WAZUH_API_USER}:'${WAZUH_API_PASS}' -X POST https://localhost:55000/security/user/authenticate?raw=true")"
AID="$(docker exec wazuh-manager sh -c "curl -sk -H 'Authorization: Bearer $TOK' 'https://localhost:55000/agents?name=$AGENT&select=id' " | grep -oE '\"id\": *\"[0-9]+\"' | grep -oE '[0-9]+' | head -1)"
SCA_COUNT=0
if [ -n "${AID:-}" ]; then
  SCA_COUNT="$(docker exec wazuh-manager sh -c "curl -sk -H 'Authorization: Bearer $TOK' 'https://localhost:55000/sca/$AID'" | grep -oE '\"policy_id\"' | wc -l | tr -d ' ')"
fi
if [ "${SCA_COUNT:-0}" -gt 0 ]; then
  echo "==> agent has $SCA_COUNT SCA policy(ies); testing scaMinScore override…"
  # Derive the php-fpm unit name (a 'php*-fpm' glob is NOT expanded by systemctl,
  # and the PHP minor version tracks the base image) — expands host-side here.
  FPM="$(docker exec eduvpn-server sh -c "systemctl list-units --type=service --plain --no-legend | grep -oE 'php[0-9.]+-fpm' | head -1")"
  # Set an impossible threshold so any real score is 'below minimum'; give fpm a
  # moment so the opcache-cleared config is live before we query. A trap restores
  # the config even if the check below exits early, so we never leave the live
  # server pinned at scaMinScore=101.
  restore_sca() { docker exec eduvpn-server sh -c "[ -f /tmp/config.bak ] && mv /tmp/config.bak /etc/vpn-user-portal/config.php && systemctl restart $FPM" >/dev/null 2>&1 || true; }
  trap restore_sca EXIT
  docker exec eduvpn-server sh -c "cp /etc/vpn-user-portal/config.php /tmp/config.bak && sed -i \"s/'scaMinScore' => 0/'scaMinScore' => 101/\" /etc/vpn-user-portal/config.php && systemctl restart $FPM"
  sleep 2
  check "SCA below threshold (min=101) → 403" \
        2 "is below required minimum" -- --cert "$DEV_CRT" --key "$DEV_KEY"
  restore_sca
  trap - EXIT
else
  skipmsg "SCA below threshold → 403" "agent has no SCA scan yet (covered deterministically in unit-php)"
fi

# ---------------------------------------------------------------------------
# FAIL-CLOSED: provider unreachable
# ---------------------------------------------------------------------------
rule "Wazuh manager stopped → 403 fail-closed"
# Sticky stop: guard it, so an interrupt here cannot leave the stack's posture
# backend down for every subsequent tier (and for the next run).
restore_wazuh() { docker start wazuh-manager >/dev/null 2>&1 || true; }
trap restore_wazuh EXIT INT TERM
docker stop wazuh-manager >/dev/null
out="$(dex eduvpn-posture-connect --server "$SERVER" --ca "$CA" --dry-run --token "$TOKEN" --cert "$DEV_CRT" --key "$DEV_KEY" 2>&1)"; r=$?
if [ "$r" = 2 ] && printf '%s' "$out" | grep -qiF "posture service unavailable"; then
  grn "  ✔ PASS (exit $r, fail-closed)"; pass=$((pass+1))
else red "  ✗ FAIL (exit $r)"; printf '%s\n' "$out" | sed 's/^/      /' | tail -4; fail=$((fail+1)); fi
docker start wazuh-manager >/dev/null
trap - EXIT INT TERM
echo "   (waiting for wazuh-manager to be healthy AND the agent to re-register…)"
# Container health alone is not enough: the manager answers on :55000 before the
# agent has reconnected, and perf/scale/conformance run straight after this — they
# would then measure "posture service unavailable" instead of posture decisions.
# NB: capture into a variable rather than piping to `grep -q` — under `pipefail`
# grep -q closes the pipe at the first match, the producer dies of SIGPIPE, and
# the condition can never become true.
wazuh_back() {
    [ "$(docker inspect wazuh-manager --format '{{.State.Health.Status}}' 2>/dev/null)" = healthy ] || return 1
    local agents
    agents="$(docker exec wazuh-manager /var/ossec/bin/agent_control -l 2>/dev/null || true)"
    case "$agents" in *", Active"*) return 0 ;; *) return 1 ;; esac
}
heal=0
until wazuh_back; do
  heal=$((heal+1))
  if [ "$heal" -gt 60 ]; then
    # Count this as a FAILURE. Previously the script printed red text and
    # continued, so e2e exited 0 while leaving wazuh-manager unhealthy — and the
    # perf/scale/conformance tiers then ran against a dead posture provider.
    red "   wazuh-manager did not recover within 5 min — later tiers would run against a dead provider"
    fail=$((fail+1))
    break
  fi
  sleep 5
done

rule "Result"
echo "  passed=$pass failed=$fail skipped=$skip"
[ "$fail" -eq 0 ] && grn "e2e: all scenarios behaved as expected" || red "e2e: $fail scenario(s) unexpected"
exit "$fail"
