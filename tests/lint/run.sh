#!/usr/bin/env bash
# SPDX-License-Identifier: MIT
# Static analysis / security lint — all containerized (host has none of these).
# No live stack needed. Gating tools fail the suite; report-only tools never do.
set -uo pipefail
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$REPO"

fail=0
step() { printf '\n=== %s ===\n' "$1"; }
gate() { if [ "$1" -ne 0 ]; then echo "  ✗ GATE FAILED: $2"; fail=$((fail+1)); else echo "  ✓ $2"; fi; }

# --- php -l: syntax of the overlay PHP (gating) -----------------------------
step "php -l (posture overlay)"
docker run --rm -v "$REPO":/w -w /w php:8.4-cli sh -c '
  rc=0
  for f in eduvpn-server/vpn-user-portal/src/PostureChecker.php \
           eduvpn-server/vpn-user-portal/src/Cfg/PostureCheckConfig.php \
           tests/unit-php/*.php; do
    php -l "$f" || rc=1
  done
  exit $rc'
gate $? "php -l"

# --- go vet + gofmt (vet gating, gofmt report-only) -------------------------
step "go vet + gofmt (client)"
docker run --rm -v "$REPO/client/eduvpn-posture-connect":/src -w /src golang:1.23 sh -c '
  go vet ./... && echo "go vet clean"'
gate $? "go vet"
docker run --rm -v "$REPO/client/eduvpn-posture-connect":/src -w /src golang:1.23 sh -c '
  out=$(gofmt -l .); if [ -n "$out" ]; then echo "gofmt would reformat:"; echo "$out"; else echo "gofmt clean"; fi'

# --- shellcheck: shell scripts (gating on errors) ---------------------------
step "shellcheck (scripts)"
# Includes the four provisioning scripts (ca-entrypoint.sh, both first-boot.sh,
# headless-oauth.sh). They were previously unlinted despite carrying the sharpest
# shell in the repo — sed -i splicing, heredocs, set -e interactions — and
# tests/README.md incorrectly claimed they were covered.
docker run --rm -v "$REPO":/w -w /w koalaman/shellcheck:v0.10.0 \
  --severity=error \
  up.sh down.sh demo.sh tests/*/run.sh tests/run-all.sh \
  ca-server/ca-entrypoint.sh eduvpn-server/first-boot.sh \
  client/first-boot.sh client/headless-oauth.sh
gate $? "shellcheck (severity=error)"

# --- hadolint: Dockerfiles (report-only) ------------------------------------
step "hadolint (Dockerfiles, report-only)"
for df in ca-server/Dockerfile client/Dockerfile eduvpn-server/Dockerfile; do
  echo "--- $df"
  docker run --rm -i hadolint/hadolint:v2.12.0 hadolint - < "$REPO/$df" || true
done

# --- gitleaks: secret scan with demo-secret allowlist (gating) --------------
step "gitleaks (secret scan, demo secrets allowlisted)"
docker run --rm -v "$REPO":/repo zricethezav/gitleaks:v8.21.2 \
  detect --source=/repo --no-banner \
  --config=/repo/tests/lint/gitleaks-allowlist.toml
gate $? "gitleaks (no non-allowlisted secrets)"

# --- trivy: filesystem CVE scan (report-only) -------------------------------
step "trivy fs (report-only)"
docker run --rm -v "$REPO":/repo aquasec/trivy:0.55.0 fs --scanners vuln,misconfig \
  --severity HIGH,CRITICAL --exit-code 0 --no-progress /repo 2>/dev/null | tail -40 || true

echo
if [ "$fail" -eq 0 ]; then echo "lint: all gating checks passed"; else echo "lint: $fail gating check(s) failed"; fi
exit "$fail"
