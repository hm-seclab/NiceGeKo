#!/usr/bin/env bash
# SPDX-License-Identifier: MIT
# =============================================================================
# End-to-end posture-gate demo
# =============================================================================
# Drives the eduvpn-client container through the /v3/connect posture decision
# for the happy path and the fail-closed rejection paths. Run AFTER ./up.sh,
# once the stack is healthy and both first-boots have finished:
#
#   ./demo.sh
#
# It uses --dry-run: the load-bearing proof is the /v3/connect decision (200 vs
# 403), not the best-effort in-container WireGuard tunnel.
# =============================================================================
set -uo pipefail
cd "$(dirname "$0")"
# Load the central config (FQDN, demo secrets) — single source of truth.
set -a; . ./.env; set +a

SERVER="$VPN_FQDN"
CLIENT=eduvpn-client
CA=/pki/ca-bundle.crt
DEV_CRT=/etc/eduvpn-client/device.crt
DEV_KEY=/etc/eduvpn-client/device.key

red()   { printf '\033[31m%s\033[0m\n' "$*"; }
green() { printf '\033[32m%s\033[0m\n' "$*"; }
bold()  { printf '\033[1m%s\033[0m\n' "$*"; }
rule()  { printf '\n\033[1m════════ %s ════════\033[0m\n' "$*"; }

dex() { docker exec "$CLIENT" "$@"; }

pass=0 fail=0
# check <description> <expected-exit> <expect-substr> -- <cli args...>
check() {
    local desc="$1" want_rc="$2" want_txt="$3"; shift 3; [ "$1" = "--" ] && shift
    rule "$desc"
    local out rc
    out="$(dex eduvpn-posture-connect --server "$SERVER" --ca "$CA" --dry-run --token "$TOKEN" "$@" 2>&1)"
    rc=$?
    echo "$out" | sed 's/^/    /'
    if [ "$rc" = "$want_rc" ] && echo "$out" | grep -qiF "$want_txt"; then
        green "  ✔ PASS  (exit $rc, matched: \"$want_txt\")"; pass=$((pass+1))
    else
        red   "  x FAIL  (exit $rc, wanted exit $want_rc + \"$want_txt\")"; fail=$((fail+1))
    fi
}

bold "Posture-gated eduVPN — end-to-end demo"

# Wait for readiness: the server portal must answer AND both first-boots must be
# done (the client issues its device cert during first-boot). Bounded so a broken
# stack fails clearly instead of erroring at the OAuth step.
echo "Waiting for the stack to be ready (server portal + first-boot provisioning)…"
ready=0
for _ in $(seq 1 180); do
    if docker exec eduvpn-server test -f /var/lib/eduvpn-first-boot.done 2>/dev/null \
       && docker exec "$CLIENT" test -f /var/lib/eduvpn-client-first-boot.done 2>/dev/null \
       && docker exec eduvpn-server sh -c 'curl -sk -o /dev/null https://localhost/' 2>/dev/null; then
        ready=1; break
    fi
    sleep 5
done
[ "$ready" = 1 ] || { red "Stack not ready after ~15 min — check 'docker compose ps' and 'docker logs eduvpn-server'"; exit 1; }
green "Stack ready."

echo "Obtaining an OAuth token (headless) for user 'vpn'…"
TOKEN="$(dex headless-oauth.sh --server "$SERVER" --user "$DEMO_USER" --pass "$DEMO_PASS" --ca "$CA" \
         --cert "$DEV_CRT" --key "$DEV_KEY")" || { red "OAuth failed — is the stack up?"; exit 1; }
green "Token acquired."

# Issue a throwaway device cert whose CN is valid-FORMAT (device- + 32 hex) but
# NOT enrolled in Wazuh, so the posture check reaches the "no agent" branch.
dex sh -c 'printf "%s" "${CA_PASSWORD:-eduvpn-demo-ca-password}" > /tmp/ca.pw;
  step ca certificate device-00000000000000000000000000000000 /tmp/ghost.crt /tmp/ghost.key \
    --provisioner device-certs --provisioner-password-file /tmp/ca.pw --force >/dev/null 2>&1; rm -f /tmp/ca.pw'

# --- Scenario 1: valid device cert + active agent -> 200 PASSED --------------
check "Valid device + active agent  → expect 200 PASSED" \
      0 "POSTURE CHECK PASSED" -- --cert "$DEV_CRT" --key "$DEV_KEY"

# --- Scenario 2: no client certificate -> 403 --------------------------------
check "No device certificate        → expect 403 rejected" \
      2 "device certificate required" -- --cert "" --key ""

# --- Scenario 3: valid cert, unknown device (never enrolled) -> 403 ----------
check "Unknown device (not enrolled) → expect 403 rejected" \
      2 "no Wazuh agent registered" -- --cert /tmp/ghost.crt --key /tmp/ghost.key

# --- Scenario 4: Wazuh manager down -> fail-closed 403 -----------------------
rule "Fail-closed: stop the Wazuh manager"
# An explicit `docker stop` is STICKY: it survives daemon restarts and reboots,
# and `docker compose ps` hides stopped containers by default. Without this trap,
# a Ctrl-C between the stop and the start below leaves the stack's posture backend
# down, and the next demo run fails on the HAPPY path with a confusing
# "posture service unavailable".
restore_wazuh() { docker start wazuh-manager >/dev/null 2>&1 || true; }
trap restore_wazuh EXIT INT TERM
echo "  stopping wazuh-manager…"; docker stop wazuh-manager >/dev/null
check "Wazuh unreachable            → expect 403 fail-closed" \
      2 "posture" -- --cert "$DEV_CRT" --key "$DEV_KEY"
echo "  restarting wazuh-manager…"; restore_wazuh
trap - EXIT INT TERM

# Wait for it to come back BEFORE exiting. The manager takes a minute or two to
# report healthy, and the agent has to reconnect before it is 'active' again.
# Leaving that to chance means whatever runs next (./tests/run-all.sh, a second
# ./demo.sh) races a half-started provider and sees every check fail with
# "posture service unavailable" — a confusing failure with no relation to its
# actual cause.
echo "  waiting for wazuh-manager to be healthy and the agent to re-register…"
# NB: capture into a variable rather than piping to `grep -q`. Under `pipefail`,
# grep -q closes the pipe at the first match and the producer dies of SIGPIPE, so
# the test would never succeed.
for _ in $(seq 1 60); do
    if [ "$(docker inspect wazuh-manager --format '{{.State.Health.Status}}' 2>/dev/null)" = healthy ]; then
        agents="$(docker exec wazuh-manager /var/ossec/bin/agent_control -l 2>/dev/null || true)"
        case "$agents" in *", Active"*) green "  Wazuh recovered."; break ;; esac
    fi
    sleep 5
done

rule "Result"
if [ "$fail" = 0 ]; then green "All $pass scenarios behaved as expected."; else
    red "$fail scenario(s) unexpected, $pass ok."; fi
exit "$fail"
