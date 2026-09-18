<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\WireGuard;

use DateTimeImmutable;
use Vpn\Portal\Cfg\ProfileConfig;
use Vpn\Portal\Cfg\WireGuardConfig;
use Vpn\Portal\ClientConfigInterface;
use Vpn\Portal\Exception\QrCodeException;
use Vpn\Portal\Exception\ServerConfigException;
use Vpn\Portal\Ip;
use Vpn\Portal\IpNetList;
use Vpn\Portal\QrCode;

/**
 * Represent a WireGuard client configuration file.
 */
final class ClientConfig implements ClientConfigInterface
{
    private ?string $privateKey = null;

    public function __construct(private string $portalUrl, private int $nodeNumber, private string $vpnProto, private ProfileConfig $profileConfig, private string $ipFour, private string $ipSix, private string $serverPublicKey, private WireGuardConfig $wgConfig, private DateTimeImmutable $expiresAt) {}

    #[\Override]
    public function contentType(): string
    {
        if ('wireguard+tcp' === $this->vpnProto) {
            return 'application/x-wireguard+tcp-profile';
        }

        return 'application/x-wireguard-profile';
    }

    public function setPrivateKey(string $privateKey): void
    {
        $this->privateKey = $privateKey;
    }

    #[\Override]
    public function get(bool $includeComments): string
    {
        return $this->wgQuick(includeComments: $includeComments, useTcp: 'wireguard+tcp' === $this->vpnProto);
    }

    public function getQr(): ?string
    {
        try {
            return QrCode::generate(
                $this->wgQuick(includeComments: false, useTcp: 'wireguard+tcp' === $this->vpnProto)
            );
        } catch (QrCodeException) {
            return null;
        }
    }

    private function wgQuick(bool $includeComments, bool $useTcp): string
    {
        $ipFour = Ip::fromIp($this->ipFour, $this->profileConfig->wRangeFour($this->nodeNumber)->prefix());
        $ipSix = Ip::fromIp($this->ipSix, $this->profileConfig->wRangeSix($this->nodeNumber)->prefix());

        $routeList = new IpNetList();
        if ($this->profileConfig->defaultGateway()) {
            $routeList->add(Ip::fromIpPrefix('0.0.0.0/0'));
            $routeList->add(Ip::fromIpPrefix('::/0'));
        }
        // add the (additional) prefixes we want
        foreach ($this->profileConfig->routeList() as $routeIpPrefix) {
            $routeList->add(Ip::fromIpPrefix($routeIpPrefix));
        }
        // add our own interface prefix to "AllowedIPs" for clients.
        // Client-to-client won't work with the firewall we deploy by default,
        // but at least this allows for it and we'll get the same behavior as
        // with OpenVPN
        $routeList->add($ipFour->network());
        $routeList->add($ipSix->network());

        // remove the prefixes we don't want
        foreach ($this->profileConfig->excludeRouteList() as $routeIpPrefix) {
            $routeList->remove(Ip::fromIpPrefix($routeIpPrefix));
        }

        $output = [];
        if ($includeComments) {
            $output[] = \sprintf('# Portal: %s', $this->portalUrl);
            $output[] = \sprintf('# Profile: %s (%s)', $this->profileConfig->displayName(), $this->profileConfig->profileId());
            $output[] = \sprintf('# Expires: %s', $this->expiresAt->format(DateTimeImmutable::ATOM));
            $output[] = '';
        }
        $output[] = '[Interface]';
        if (null !== $setMtu = $this->wgConfig->setMtu()) {
            $output[] = 'MTU = ' . (string) $setMtu;
        }
        if (null !== $this->privateKey) {
            $output[] = 'PrivateKey = ' . $this->privateKey;
        }
        $output[] = \sprintf('Address = %s,%s', (string) $ipFour, (string) $ipSix);

        $dnsEntries = self::getDns($this->profileConfig, $ipFour, $ipSix);
        if (0 !== \count($dnsEntries)) {
            $output[] = 'DNS = ' . implode(',', $dnsEntries);
        }
        $output[] = '';
        $output[] = '[Peer]';
        $output[] = 'PublicKey = ' . $this->serverPublicKey;
        if (0 !== \count($routeList->ls())) {
            $output[] = 'AllowedIPs = ' . implode(',', $routeList->ls());
        }

        if ($this->profileConfig->wKeepAlive()) {
            // we tell the client to keep the VPN connection up, even if
            // there's no traffic. This allows for the device to remain
            // reachable when it is located behind a firewall and/or NAT that
            // drops the connection once it hasn't been used for a while
            $output[] = 'PersistentKeepalive = 25';
        }

        if ($useTcp) {
            if ($this->wgConfig->enableProxy()) {
                if ($includeComments) {
                    $output[] = '# ProxyEndpoint is a proprietary eduVPN / Let\'s Connect! extension';
                    $output[] = '# See https://docs.eduvpn.org/server/v3/proxyguard.html#client';
                }
                $output[] = 'Endpoint = 127.0.0.1:51820';
                $output[] = \sprintf(
                    'ProxyEndpoint = %s',
                    self::determineProxyUrl(
                        $this->portalUrl,
                        $this->wgConfig->proxyUrl(),
                        $this->profileConfig->hostName($this->nodeNumber)
                    ),
                );

                return implode("\n", $output);
            }
        }

        $output[] = 'Endpoint = ' . $this->profileConfig->hostName($this->nodeNumber) . ':' . (string) $this->wgConfig->listenPort();

        return implode("\n", $output);
    }

    /**
     * @return array<string>
     */
    private static function getDns(ProfileConfig $profileConfig, Ip $ipFour, Ip $ipSix): array
    {
        $dnsServerList = [];
        foreach ($profileConfig->dnsServerList() as $dnsAddress) {
            if ('@GW4@' === $dnsAddress) {
                $dnsAddress = $ipFour->firstHost();
            }
            if ('@GW6@' === $dnsAddress) {
                $dnsAddress = $ipSix->firstHost();
            }
            $dnsServerList[] = $dnsAddress;
        }
        $dnsEntries = [];

        // push DNS servers when default gateway is set, or there are some
        // search domains specified
        if ($profileConfig->defaultGateway() || 0 !== \count($profileConfig->dnsSearchDomainList())) {
            $dnsEntries = array_merge($dnsEntries, $dnsServerList);
        }

        // provide "search domains" to the VPN client
        $dnsEntries = array_merge($dnsEntries, $profileConfig->dnsSearchDomainList());

        return $dnsEntries;
    }

    private static function determineProxyUrl(string $portalUrl, ?string $proxyUrl, string $nodeHostName): string
    {
        if (null !== $proxyUrl) {
            // trim trailing "/" chars from proxyUrl if any
            return \sprintf('%s/%s', rtrim($proxyUrl, '/'), $nodeHostName);
        }

        // determine based on the portal's own URL
        $hostName = parse_url($portalUrl, \PHP_URL_HOST);
        if (!\is_string($hostName)) {
            throw new ServerConfigException('unable to extract host from URL');
        }

        return \sprintf('https://%s/proxyguard/%s', $hostName, $nodeHostName);
    }
}
