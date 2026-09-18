# SPDX-License-Identifier: MIT
# =============================================================================
# Posture-gated eduVPN client — systemd container (device cert + Wazuh agent + CLI)
# =============================================================================
# Represents a managed Linux endpoint:
#   * a persistent device identity (device-<machine-id>),
#   * a step-ca-issued device certificate presented over mTLS,
#   * a Wazuh agent enrolled as device-<machine-id> and running as a service,
#   * the eduvpn-posture-connect CLI to drive /v3/connect.
# The Go CLI is built at image-build time (stdlib only) in a builder stage.
# Requires (docker-compose.yml): privileged, cgroup: host, tmpfs /run.
# =============================================================================

# --- builder: compile the zero-dependency Go CLI ----------------------------
FROM golang:1.23 AS builder
WORKDIR /src
COPY eduvpn-posture-connect/ ./
# Static, stdlib-only build — no module downloads needed.
RUN CGO_ENABLED=0 go build -trimpath -o /out/eduvpn-posture-connect .

# --- runtime: systemd endpoint ----------------------------------------------
FROM debian:trixie

ENV container=docker DEBIAN_FRONTEND=noninteractive

# systemd base + prerequisites (wireguard for the best-effort tunnel).
RUN apt-get update && apt-get install -y --no-install-recommends \
        systemd systemd-sysv dbus \
        ca-certificates curl gnupg apt-transport-https \
        wireguard-tools iproute2 procps \
    && rm -rf /var/lib/apt/lists/*

# Mask container-hostile units.
RUN systemctl mask \
        systemd-udevd.service systemd-udevd-kernel.socket \
        systemd-udevd-control.socket systemd-modules-load.service \
        sys-kernel-config.mount sys-kernel-debug.mount sys-kernel-tracing.mount

# Remove any baked machine-id so systemd generates a UNIQUE one per container at
# boot (otherwise every client shares the image's id → colliding device names).
# first-boot.sh then persists it into the client-config volume for stability.
RUN rm -f /etc/machine-id /var/lib/dbus/machine-id

# step CLI (GitHub .deb; see eduvpn-server for the APT-repo caveat).
ARG STEP_VERSION=0.29.0
RUN curl -fsSL "https://github.com/smallstep/cli/releases/download/v${STEP_VERSION}/step-cli_${STEP_VERSION}-1_amd64.deb" \
        -o /tmp/step-cli.deb \
    && dpkg -i /tmp/step-cli.deb \
    && rm -f /tmp/step-cli.deb

# Wazuh agent, pinned to the SAME version as the manager image. WAZUH_VERSION
# comes from the central .env via compose build args (matching the manager's
# image tag, e.g. 4.14.3); the apt package adds the "-1" Debian revision. This
# keeps agent and manager in lockstep from a single source of truth.
# WAZUH_MANAGER is baked into ossec.conf; the agent NAME is set at first boot.
ARG WAZUH_VERSION=4.14.3
RUN curl -s https://packages.wazuh.com/key/GPG-KEY-WAZUH \
      | gpg --no-default-keyring --keyring gnupg-ring:/usr/share/keyrings/wazuh.gpg --import \
    && chmod 644 /usr/share/keyrings/wazuh.gpg \
    && echo "deb [signed-by=/usr/share/keyrings/wazuh.gpg] https://packages.wazuh.com/4.x/apt/ stable main" \
      > /etc/apt/sources.list.d/wazuh.list \
    && apt-get update \
    && WAZUH_MANAGER="wazuh.manager" apt-get install -y "wazuh-agent=${WAZUH_VERSION}-1" \
    && rm -rf /var/lib/apt/lists/* \
    && echo "wazuh-agent hold" | dpkg --set-selections

# The posture-connect CLI + the headless OAuth helper for the demo.
COPY --from=builder /out/eduvpn-posture-connect /usr/local/bin/eduvpn-posture-connect
COPY headless-oauth.sh /usr/local/bin/headless-oauth.sh
RUN chmod +x /usr/local/bin/headless-oauth.sh

# First-boot provisioning (device identity, cert, agent enrollment).
COPY first-boot.sh /usr/local/sbin/eduvpn-client-first-boot.sh
COPY client-first-boot.service /etc/systemd/system/eduvpn-client-first-boot.service
RUN chmod +x /usr/local/sbin/eduvpn-client-first-boot.sh \
    && systemctl enable eduvpn-client-first-boot.service

STOPSIGNAL SIGRTMIN+3
CMD ["/sbin/init"]
