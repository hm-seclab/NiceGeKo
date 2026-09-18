# SPDX-License-Identifier: MIT
# =============================================================================
# eduVPN PoC — step-ca (smallstep) with shared-PKI publishing
# =============================================================================
# Extends the official step-ca image with a custom entrypoint that initialises
# the PKI + PoC provisioners on first boot and publishes the trust material
# (root cert, CA bundle, fingerprint) to a shared /pki volume that the eduVPN
# server and client consume. See ca-entrypoint.sh for details.
# =============================================================================
# Base image version comes from the central .env (STEP_CA_VERSION) via compose
# build args; the default keeps a plain `docker build` reproducible.
ARG STEP_CA_VERSION=0.30.2
FROM smallstep/step-ca:${STEP_CA_VERSION}

# Pre-create the shared-PKI mountpoint owned by the unprivileged `step` user so
# the named volume inherits that ownership on first mount and the CA can write
# its published trust material there without running as root.
USER root
RUN mkdir -p /pki && chown step:step /pki
COPY ca-entrypoint.sh /usr/local/bin/ca-entrypoint.sh
RUN chmod +x /usr/local/bin/ca-entrypoint.sh
USER step

ENTRYPOINT ["/usr/local/bin/ca-entrypoint.sh"]
