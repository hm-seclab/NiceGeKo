# SPDX-License-Identifier: MIT
# =============================================================================
# Convenience targets for the posture-gated eduVPN PoC.
# `make` is optional — every target is a thin wrapper over ./up.sh / compose.
# =============================================================================
.PHONY: help up down clean ps logs \
        test test-unit test-lint test-e2e test-perf test-scale test-conf test-full

# Default to help, NOT `up`. A bare `make` previously built and started a
# six-container privileged stack — a surprising thing for a reflexive `make` to do.
.DEFAULT_GOAL := help

## Show this help.
help:
	@echo "eduVPNextension — posture-gated eduVPN proof-of-concept"
	@echo
	@awk '/^## /{doc=substr($$0,4)} \
	      /^[a-z][a-z0-9-]*:/{if(doc!=""){split($$0,a,":"); printf "  \033[36m%-12s\033[0m %s\n", a[1], doc; doc=""}}' \
	     $(MAKEFILE_LIST)
	@echo
	@echo "  Start with 'make test' — unit + lint, a couple of minutes, no stack needed."
	@echo "  'make up' brings up the full demo stack (needs ~8 GB RAM). See README.md."

## Bring the whole stack up (generates Wazuh certs on first run).
up:
	./up.sh

## Stop + remove containers, keep data volumes.
down:
	./down.sh

## Stop + remove containers AND volumes (full reset).
clean:
	./down.sh --clean

## Show container health/status.
ps:
	docker compose ps

## Follow logs for all services (Ctrl-C to stop).
logs:
	docker compose logs -f

# --- Tests (see tests/README.md) --------------------------------------------
## Fast tests: unit (PHP+Go) + static-analysis lint. No live stack needed.
test:
	./tests/run-all.sh fast

## Unit tests only (PHP PostureChecker + Go client), containerized.
test-unit:
	./tests/run-all.sh unit

## Static analysis + secret scan (php -l, go vet, shellcheck, hadolint, gitleaks, trivy).
test-lint:
	./tests/run-all.sh lint

## End-to-end functional + security scenarios (requires ./up.sh).
test-e2e:
	./tests/run-all.sh e2e

## Performance benchmark (requires ./up.sh).
test-perf:
	./tests/run-all.sh perf

## Scalability sweep (requires ./up.sh).
test-scale:
	./tests/run-all.sh scale

## Standards-conformance / compatibility checks (requires ./up.sh).
test-conf:
	./tests/run-all.sh conf

## Everything: unit + lint + all live-stack suites (auto-runs ./up.sh if down).
test-full:
	./tests/run-all.sh full
