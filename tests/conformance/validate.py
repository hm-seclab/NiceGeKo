#!/usr/bin/env python3
# SPDX-License-Identifier: MIT
"""
Standards conformance / compatibility checks (ties to
concept/universal-device-health-interface.md).

1. Pull LIVE Wazuh /agents + /sca for the real client device, MAP them onto the
   canonical Device Health Record, and validate against the JSON Schema — proving
   the concept's abstraction actually covers real provider output (compatibility).
2. Assert the eduVPN API-v3 DISCOVERY contract that the Go client consumes.

Runs in a python:3.12-slim container joined to the compose network (jsonschema
installed at run time). Env: WAZUH_URL, WAZUH_USER, WAZUH_PASS, DEVICE, VPN_URL,
CA, SCHEMA.
"""
import json
import os
import ssl
import sys
import time
import urllib.error
import urllib.request
from datetime import datetime, timezone

import jsonschema

WAZUH_URL = os.environ.get("WAZUH_URL", "https://wazuh.manager:55000")
WAZUH_USER = os.environ.get("WAZUH_USER", "wazuh-wui")
WAZUH_PASS = os.environ["WAZUH_PASS"]
DEVICE = os.environ["DEVICE"]
VPN_URL = os.environ.get("VPN_URL", "https://vpn.local")
CA = os.environ.get("CA", "/pki/ca-bundle.crt")
SCHEMA = os.environ.get("SCHEMA", "/conf/device-health-record.schema.json")
# Mirrors the portal's PostureCheck.scaMinScore; a signal below this maps to "fail".
SCA_MIN = int(os.environ.get("SCA_MIN_SCORE", "0"))

fail = 0


def ok(cond, msg):
    global fail
    print(("  ✔ " if cond else "  ✗ ") + msg)
    if not cond:
        fail += 1


def now():
    return datetime.now(timezone.utc).isoformat()


def wazuh_get(path, token=None, insecure=True):
    ctx = ssl._create_unverified_context() if insecure else ssl.create_default_context()
    req = urllib.request.Request(WAZUH_URL + path, method="GET")
    if token:
        req.add_header("Authorization", "Bearer " + token)
    else:
        import base64
        cred = base64.b64encode(f"{WAZUH_USER}:{WAZUH_PASS}".encode()).decode()
        req.add_header("Authorization", "Basic " + cred)
    # Wazuh rate-limits /security/user/authenticate; when the whole test suite
    # runs back-to-back (esp. after scale's bulk registrations) a single auth can
    # get HTTP 429. Retry a few times with backoff so conformance isn't flaky.
    for attempt in range(5):
        try:
            with urllib.request.urlopen(req, context=ctx, timeout=10) as r:
                return json.load(r)
        except urllib.error.HTTPError as e:
            if e.code == 429 and attempt < 4:
                time.sleep(3 * (attempt + 1))
                continue
            raise


def map_to_canonical(agent, sca_policies):
    """Reverse-engineered mapping — see concept §12 traceability."""
    status = (agent.get("status") or "").lower()
    signals = [{
        "id": "liveness.agent",
        "namespace": "core.liveness",
        "type": "state",
        "value": status,
        "status": "pass" if status == "active" else "fail",
        "observedAt": now(),
        "failureMode": "closed",
        "source": {"raw": "agents[0].status"},
    }]
    for p in sca_policies:
        score = int(p.get("score", 0))
        signals.append({
            "id": "compliance." + str(p.get("policy_id", "unknown")),
            "namespace": "core.compliance",
            "type": "score",
            "value": score,
            "scale": {"min": 0, "max": 100, "unit": "percent"},
            # Derive the status from the score rather than hardcoding "pass".
            # Hardcoding meant the compliance half of the canonical mapping could
            # never emit a failing signal, so that half of the schema was never
            # actually exercised. SCA_MIN mirrors the portal's scaMinScore.
            "status": "pass" if score >= SCA_MIN else "fail",
            "observedAt": now(),
            "detail": {"policyId": p.get("policy_id"), "name": p.get("name")},
            "failureMode": "open",
            "source": {"raw": "sca.affected_items[]"},
        })
    return {
        "schemaVersion": "1.0",
        "device": {"id": DEVICE, "idScheme": "x509-cn",
                   "aliases": [{"scheme": "wazuh-agent-id", "value": str(agent.get("id"))}]},
        "provider": {"id": "wazuh-manager", "type": "edr", "assessedAt": now()},
        "signals": signals,
        "freshness": {"generatedAt": now()},
    }


print("== 1. Live Wazuh output → canonical Device Health Record → schema ==")
schema = json.load(open(SCHEMA))
token = wazuh_get("/security/user/authenticate")["data"]["token"]
agents = wazuh_get(f"/agents?name={DEVICE}&select=status,id", token)["data"]["affected_items"]
ok(len(agents) >= 1, f"live agent '{DEVICE}' found in Wazuh")
if agents:
    agent = agents[0]
    sca = []
    if agent.get("id"):
        try:
            sca = wazuh_get(f"/sca/{agent['id']}", token)["data"]["affected_items"]
        except Exception:
            sca = []
    record = map_to_canonical(agent, sca)
    try:
        jsonschema.validate(record, schema)
        ok(True, f"canonical record validates against schema ({len(record['signals'])} signal(s), {len(sca)} SCA)")
    except jsonschema.ValidationError as e:
        ok(False, f"schema validation failed: {e.message}")
    print("     sample record:")
    print("       " + json.dumps(record, separators=(",", ":"))[:300] + " …")

print("\n== 2. eduVPN API-v3 discovery contract ==")
ctx = ssl.create_default_context(cafile=CA)
try:
    with urllib.request.urlopen(VPN_URL + "/.well-known/vpn-user-portal", context=ctx, timeout=10) as r:
        disc = json.load(r)
    api = disc.get("api", {}).get("http://eduvpn.org/api#3")
    ok(api is not None, "discovery advertises http://eduvpn.org/api#3")
    if api:
        for k in ("api_endpoint", "authorization_endpoint", "token_endpoint"):
            ok(isinstance(api.get(k), str) and api[k], f"discovery has '{k}'")
except Exception as e:  # noqa: BLE001
    ok(False, f"discovery fetch failed: {e}")

print()
print("conformance: PASS" if fail == 0 else f"conformance: {fail} check(s) failed")
sys.exit(1 if fail else 0)
