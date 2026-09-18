#!/usr/bin/env python3
# SPDX-License-Identifier: MIT
"""
Concurrent load driver for the posture decision.

Runs inside a python:3.12-slim container joined to the compose network, with the
device/ghost cert (client-config volume) and CA bundle (pki volume) mounted.
Presents an mTLS client cert + Bearer token and hammers a target endpoint,
recording per-request latency.

To avoid server-side state mutation, the default mode hits POST /v3/connect with
an UNENROLLED (ghost) cert: the posture check runs in full (Wazuh /agents query)
and returns 403 *before* any WireGuard peer is issued. So the measured latency is
the posture-decision path under load, with no side effects.

Env: SERVER, TOKEN, N (requests), C (concurrency), MODE (connect|info),
     CERT, KEY, CA.
"""
import base64
import http.client
import os
import ssl
import statistics
import sys
import time
import urllib.parse
from concurrent.futures import ThreadPoolExecutor

SERVER = os.environ.get("SERVER", "vpn.local")
TOKEN = os.environ["TOKEN"]
N = int(os.environ.get("N", "200"))
C = int(os.environ.get("C", "8"))
MODE = os.environ.get("MODE", "connect")
CERT = os.environ.get("CERT", "/certs/ghost.crt")
KEY = os.environ.get("KEY", "/certs/ghost.key")
CA = os.environ.get("CA", "/pki/ca-bundle.crt")
# Fraction of requests that must reach the intended path for the run to count.
# Overridable so a deliberately-saturating sweep can assert a lower bar.
THRESHOLD = float(os.environ.get("THRESHOLD", "0.95"))

ctx = ssl.create_default_context(cafile=CA)
ctx.load_cert_chain(CERT, KEY)
ctx.minimum_version = ssl.TLSVersion.TLSv1_3  # match the Go client's pin


def one_request():
    conn = http.client.HTTPSConnection(SERVER, 443, context=ctx, timeout=15)
    t0 = time.perf_counter()
    try:
        if MODE == "info":
            conn.request("GET", "/vpn-user-portal/api/v3/info",
                         headers={"Authorization": "Bearer " + TOKEN})
        else:
            # A VALID-format WireGuard public key (32 bytes → 44 base64 chars) so
            # the request passes body validation and REACHES the posture check.
            # With the unenrolled ghost cert the posture check returns 403 before
            # connect() runs, so no WireGuard peer is ever issued.
            pubkey = base64.b64encode(os.urandom(32)).decode()
            body = urllib.parse.urlencode({"profile_id": "default", "public_key": pubkey})
            conn.request("POST", "/vpn-user-portal/api/v3/connect", body=body,
                         headers={"Authorization": "Bearer " + TOKEN,
                                  "Content-Type": "application/x-www-form-urlencoded",
                                  "Accept": "application/x-wireguard-profile"})
        r = conn.getresponse()
        body = r.read()
        status = r.status
        # A 403 alone is ambiguous: a genuine posture denial and a fail-CLOSED
        # error (e.g. Wazuh rate-limiting us) both return 403. Distinguish them by
        # the reason, or the benchmark cannot tell whether it measured the posture
        # path or the failure path.
        if status == 403 and b"posture service unavailable" in body:
            status = "403-unavailable"
    except Exception as e:  # noqa: BLE001
        status = "ERR:" + type(e).__name__
    finally:
        dt = time.perf_counter() - t0
        conn.close()
    return dt, status


def pct(values, p):
    if not values:
        return 0.0
    s = sorted(values)
    k = min(len(s) - 1, int(round((p / 100.0) * (len(s) - 1))))
    return s[k]


def main():
    # Warm-up single request (cold path — pays the Wazuh /authenticate if the
    # server's in-process JWT cache was just cleared).
    cold_dt, cold_status = one_request()

    lat = []
    statuses = {}
    wall0 = time.perf_counter()
    with ThreadPoolExecutor(max_workers=C) as ex:
        for dt, st in ex.map(lambda _: one_request(), range(N)):
            lat.append(dt)
            statuses[st] = statuses.get(st, 0) + 1
    wall = time.perf_counter() - wall0

    ms = lambda x: f"{x * 1000:.1f}ms"
    print(f"  mode={MODE} requests={N} concurrency={C}")
    print(f"  cold(1st) : {ms(cold_dt)}  (status {cold_status})")
    print(f"  p50 ={ms(pct(lat,50))}  p95 ={ms(pct(lat,95))}  p99 ={ms(pct(lat,99))}  max={ms(max(lat))}")
    print(f"  mean={ms(statistics.fmean(lat))}  throughput={N / wall:.1f} req/s  wall={wall:.2f}s")
    print(f"  status distribution: {statuses}")

    # ---- gates -------------------------------------------------------------
    # These are REAL gates: they exit non-zero. Without them, a run in which every
    # request failed (TLS error, 401, connection refused, Wazuh rate-limiting)
    # still printed a tidy table and exited 0 — so the tier could not fail, and
    # the numbers it published were not known to describe the path being measured.
    failures = 0

    # 1. The status distribution must be dominated by the status this mode expects:
    #    connect -> 403 (the posture decision; the ghost cert is deliberately not
    #    enrolled), info -> 200 (the auth-only baseline).
    # NB: successful requests key `statuses` by the INTEGER status (r.status);
    # failures key it by a string ("ERR:<Type>", "403-unavailable"). Comparing
    # against a string here silently matched nothing and failed every healthy run.
    want = 403 if MODE == "connect" else 200
    good = statuses.get(want, 0)
    if good < THRESHOLD * N:
        unavailable = statuses.get("403-unavailable", 0)
        extra = ""
        if unavailable:
            extra = (
                f" {unavailable} of them were fail-CLOSED errors ('posture service "
                f"unavailable'), not posture decisions — most likely Wazuh rate-limiting "
                f"(see the scalability limitation in tests/README.md)."
            )
        print(
            f"  FAIL: only {good}/{N} requests returned the expected status {want} "
            f"(distribution={statuses}).{extra} The latency numbers above do NOT "
            f"describe the intended path.",
            file=sys.stderr,
        )
        failures += 1

    # 2. p99 must stay under the Wazuh curl timeout ceiling (5s).
    if pct(lat, 99) >= 5.0:
        print(f"  FAIL: p99 {ms(pct(lat, 99))} >= 5s (Wazuh curl timeout ceiling)", file=sys.stderr)
        failures += 1

    if failures:
        sys.exit(1)


if __name__ == "__main__":
    main()
