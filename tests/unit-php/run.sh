#!/usr/bin/env bash
# SPDX-License-Identifier: MIT
# Run the PHP unit tests inside php:8.4-cli (host has no php). No live stack.
set -euo pipefail
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
exec docker run --rm -v "$REPO":/w -w /w php:8.4-cli \
    php tests/unit-php/run-tests.php
