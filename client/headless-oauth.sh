#!/usr/bin/env bash
# SPDX-License-Identifier: MIT
# =============================================================================
# Headless OAuth2 token grab for the posture-connect demo
# =============================================================================
# Performs the eduVPN OAuth2 authorization-code + PKCE flow against a portal
# LOCAL account WITHOUT a browser, and prints the access token on stdout.
# Intended for the automated demo; the interactive browser flow in the Go CLI
# remains the normal path.
#
# Usage:
#   headless-oauth.sh --server vpn.local --user vpn --pass admin \
#       [--ca /pki/ca-bundle.crt] [--cert device.crt --key device.key]
#
# All progress goes to stderr; ONLY the access token is written to stdout.
# =============================================================================
set -euo pipefail

SERVER="" USER_NAME="" USER_PASS="" CA="" CERT="" KEY=""
while [ $# -gt 0 ]; do
    case "$1" in
        --server) SERVER="$2"; shift 2;;
        --user)   USER_NAME="$2"; shift 2;;
        --pass)   USER_PASS="$2"; shift 2;;
        --ca)     CA="$2"; shift 2;;
        --cert)   CERT="$2"; shift 2;;
        --key)    KEY="$2"; shift 2;;
        *) echo "unknown arg: $1" >&2; exit 2;;
    esac
done
[ -n "$SERVER" ] && [ -n "$USER_NAME" ] && [ -n "$USER_PASS" ] || {
    echo "usage: headless-oauth.sh --server H --user U --pass P [--ca F] [--cert F --key F]" >&2; exit 2; }

log() { echo "[headless-oauth] $*" >&2; }

BASE="https://${SERVER}/vpn-user-portal"
CLIENT_ID="org.eduvpn.app.linux"
REDIRECT="http://127.0.0.1:8000/callback"
SCOPE="config"
JAR="$(mktemp)"
trap 'rm -f "$JAR"' EXIT

# curl options shared by every request (cookie jar, TLS trust, optional mTLS).
CURL=(curl -sS --cookie "$JAR" --cookie-jar "$JAR")
[ -n "$CA" ]   && CURL+=(--cacert "$CA")   || CURL+=(-k)
[ -n "$CERT" ] && [ -n "$KEY" ] && CURL+=(--cert "$CERT" --key "$KEY")
# eduVPN CSRF hardening rejects POSTs without these (400 otherwise).
CSRF=(-H "Origin: https://${SERVER}" -H "Sec-Fetch-Site: same-origin")

# --- PKCE ---------------------------------------------------------------------
b64url() { openssl base64 -A | tr '+/' '-_' | tr -d '='; }
VERIFIER="$(openssl rand 32 | b64url)"
CHALLENGE="$(printf '%s' "$VERIFIER" | openssl dgst -binary -sha256 | b64url)"
STATE="$(openssl rand 16 | b64url)"

# Pull the value of a hidden <input name="FIELD" value="..."> (either attr order).
hidden() { # $1=field  (html on stdin)
    grep -oiE "<input[^>]*name=\"$1\"[^>]*>" | head -1 \
        | grep -oiE 'value="[^"]*"' | head -1 | sed -E 's/^value="(.*)"$/\1/'
}
htmldec() { sed -e 's/&amp;/\&/g' -e 's/&#38;/\&/g'; }
# Resolve a possibly-relative redirect target to an absolute URL.
absurl() { case "$1" in http*://*) printf '%s' "$1";; *) printf 'https://%s%s' "$SERVER" "$1";; esac; }

AUTHZ="${BASE}/oauth/authorize?client_id=${CLIENT_ID}&redirect_uri=$(printf %s "$REDIRECT" | sed 's/:/%3A/g;s#/#%2F#g')&response_type=code&scope=${SCOPE}&state=${STATE}&code_challenge=${CHALLENGE}&code_challenge_method=S256"

log "1/4 GET authorize -> login page"
LOGIN_HTML="$("${CURL[@]}" "$AUTHZ")"
AUTH_REDIRECT="$(printf '%s' "$LOGIN_HTML" | hidden authRedirectTo | htmldec)"
[ -n "$AUTH_REDIRECT" ] || { log "ERROR: no authRedirectTo on login page"; exit 1; }

log "2/4 POST credentials"
"${CURL[@]}" "${CSRF[@]}" -o /dev/null \
    --data-urlencode "userName=${USER_NAME}" \
    --data-urlencode "userPass=${USER_PASS}" \
    --data-urlencode "authRedirectTo=${AUTH_REDIRECT}" \
    "${BASE}/_user_pass_auth/verify"

log "3/4 GET authorize (authenticated) -> authorization code"
AUTH_URL="$(absurl "$AUTH_REDIRECT")"
APPROVE_HTML="$("${CURL[@]}" "$AUTH_URL")"
CODE="$(printf '%s' "$APPROVE_HTML" | hidden code | htmldec)"
if [ -z "$CODE" ]; then
    # Fallback: the portal may 302 to the redirect_uri with ?code=...
    LOC="$("${CURL[@]}" -o /dev/null -D - "$AUTH_URL" | grep -i '^location:' | tr -d '\r' || true)"
    CODE="$(printf '%s' "$LOC" | grep -oE 'code=[^&]+' | head -1 | cut -d= -f2)"
fi
[ -n "$CODE" ] || { log "ERROR: no authorization code obtained"; exit 1; }

log "4/4 POST token exchange"
TOKEN_JSON="$("${CURL[@]}" "${CSRF[@]}" \
    --data-urlencode "grant_type=authorization_code" \
    --data-urlencode "code=${CODE}" \
    --data-urlencode "redirect_uri=${REDIRECT}" \
    --data-urlencode "client_id=${CLIENT_ID}" \
    --data-urlencode "code_verifier=${VERIFIER}" \
    "${BASE}/oauth/token")"
ACCESS_TOKEN="$(printf '%s' "$TOKEN_JSON" | grep -oE '"access_token"[[:space:]]*:[[:space:]]*"[^"]*"' | sed -E 's/.*"access_token"[[:space:]]*:[[:space:]]*"([^"]*)".*/\1/')"
[ -n "$ACCESS_TOKEN" ] || { log "ERROR: token exchange failed: $TOKEN_JSON"; exit 1; }

log "OK — access token obtained"
printf '%s\n' "$ACCESS_TOKEN"
