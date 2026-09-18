#!/usr/bin/env bash
# SPDX-License-Identifier: MIT
# Run the Go client unit tests inside golang:1.23 (host has no go; stdlib-only,
# so no module downloads). Tests live next to the sources (package main). No stack.
set -euo pipefail
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
# -race: oauth.go coordinates the callback listener, a timeout and the main
#        goroutine over channels, so data races are a realistic defect class here.
# -cover: reported for information only. There is deliberately NO coverage
#         threshold — see tests/README.md "known coverage gaps" for what is and
#         is not covered, and why some of it is not worth unit-testing.
exec docker run --rm \
    -v "$REPO/client/eduvpn-posture-connect":/src -w /src \
    golang:1.23 go test ./... -v -race -cover
