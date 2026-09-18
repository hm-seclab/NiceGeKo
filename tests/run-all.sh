#!/usr/bin/env bash
# SPDX-License-Identifier: MIT
# Test orchestrator. Dispatches to the per-suite run.sh scripts.
#
#   tests/run-all.sh <target>
#     unit   — PHP + Go unit tests            (no live stack)
#     lint   — static analysis / secret scan  (no live stack)
#     e2e    — functional + security scenarios (needs ./up.sh)
#     perf   — performance benchmark           (needs ./up.sh)
#     scale  — scalability sweep               (needs ./up.sh)
#     conf   — standards conformance           (needs ./up.sh)
#     fast   — unit + lint                     (default)
#     full   — everything (auto-runs ./up.sh if the stack is down)
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
REPO="$(cd "$HERE/.." && pwd)"
target="${1:-fast}"
rc=0

run() { echo; echo "########## $1 ##########"; "$HERE/$2"; local r=$?; rc=$((rc + (r != 0 ? 1 : 0))); }

# Live-stack readiness: both first-boot sentinels present + Wazuh API answering.
# NOTE: no `... | grep -q ...` pipelines in here. Under `set -o pipefail`, grep -q
# exits at the first match and closes the pipe while the producer is still
# writing, so the producer dies of SIGPIPE and the pipeline reports 141 — making
# this function return false forever no matter how healthy the stack is. Capture
# into a variable and pattern-match instead.
stack_ready() {
    docker exec eduvpn-server test -f /var/lib/eduvpn-first-boot.done        2>/dev/null || return 1
    docker exec eduvpn-client test -f /var/lib/eduvpn-client-first-boot.done 2>/dev/null || return 1

    # Wazuh must be HEALTHY, not merely answering. A manager that is still starting
    # (e.g. after demo.sh's fail-closed scenario restarted it) answers on :55000
    # long before it can serve agent queries, and every posture check then fails
    # with "posture service unavailable" — which the live tiers would otherwise
    # report as functional failures unrelated to their actual cause.
    [ "$(docker inspect wazuh-manager --format '{{.State.Health.Status}}' 2>/dev/null)" = healthy ] || return 1

    # The client's agent must also have re-registered as Active, or the very first
    # e2e scenario ("valid device + active agent -> 200") fails through no fault of
    # the code under test.
    local agents portal
    agents="$(docker exec wazuh-manager /var/ossec/bin/agent_control -l 2>/dev/null || true)"
    case "$agents" in *", Active"*) ;; *) return 1 ;; esac

    # The portal is what every live tier actually exercises — check it serves the
    # API, not just that Apache is up. 401 (no token) proves PHP is answering.
    portal="$(docker exec eduvpn-server sh -c 'curl -sk -o /dev/null -w "%{http_code}" https://localhost/vpn-user-portal/api/v3/info' 2>/dev/null || true)"
    case "$portal" in 200|401) ;; *) return 1 ;; esac

    return 0
}
require_stack() {
    if stack_ready; then return 0; fi
    if [ "${1:-}" = "auto" ]; then
        echo ">> stack not ready — running ./up.sh (this takes a few minutes)…"
        (cd "$REPO" && ./up.sh) || { echo "up.sh failed"; return 1; }
        # wait for first-boots (bounded: ~12 min, first boot runs deploy_debian.sh)
        i=0
        until stack_ready; do
            i=$((i+1))
            if [ "$i" -gt 144 ]; then echo ">> TIMEOUT: stack not ready after ~12 min" >&2; return 1; fi
            sleep 5
        done
    else
        # NOT a skip: the caller explicitly asked for a live-stack tier, so not
        # being able to run it is a failure and the exit code says so. (Saying
        # "SKIP" here while exiting 1 was the confusing part.)
        echo ">> CANNOT RUN: live stack not ready. Run ./up.sh first (or use 'full')." >&2
        echo ">> This counts as a failure — the requested tier did not execute." >&2
        return 1
    fi
}

case "$target" in
    unit)  run "unit-php" unit-php/run.sh; run "unit-go" unit-go/run.sh ;;
    lint)  run "lint" lint/run.sh ;;
    fast)  run "unit-php" unit-php/run.sh; run "unit-go" unit-go/run.sh; run "lint" lint/run.sh ;;
    e2e)   require_stack && run "e2e"   e2e/run.sh   || rc=1 ;;
    perf)  require_stack && run "perf"  perf/run.sh  || rc=1 ;;
    scale) require_stack && run "scale" scale/run.sh || rc=1 ;;
    conf)  require_stack && run "conf"  conformance/run.sh || rc=1 ;;
    full)
        run "unit-php" unit-php/run.sh; run "unit-go" unit-go/run.sh; run "lint" lint/run.sh
        require_stack auto || { echo "cannot bring stack up"; exit 1; }
        run "e2e" e2e/run.sh; run "perf" perf/run.sh; run "scale" scale/run.sh; run "conf" conformance/run.sh ;;
    *) echo "unknown target: $target (use unit|lint|fast|e2e|perf|scale|conf|full)"; exit 2 ;;
esac

echo
if [ "$rc" -eq 0 ]; then echo "ALL SUITES PASSED ($target)"; else echo "$rc SUITE(S) FAILED ($target)"; fi
exit "$rc"
