<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal;

use MaxMind\Db\Reader;

/**
 * Get (location) information about an IPv4 or IPv6 address.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc5870
 */
final class GeoIp
{
    public function __construct(private string $dataFile) {}

    public function get(string $ipAddress): ?IpInfo
    {
        if (!self::checkEnvironment()) {
            return null;
        }

        $r = new Reader($this->dataFile);
        $ipInfo = $r->get($ipAddress);

        // make sure the response we get is of a format we understand, if not
        // simply return `null`, i.e. we are unable to figure out the
        // coordinates for this IP address
        if (!\is_array($ipInfo)) {
            return null;
        }
        if (!\array_key_exists('location', $ipInfo)) {
            return null;
        }
        if (!\is_array($ipInfo['location'])) {
            return null;
        }
        if (!\array_key_exists('latitude', $ipInfo['location'])) {
            return null;
        }
        if (!\is_float($ipInfo['location']['latitude'])) {
            return null;
        }
        if (!\array_key_exists('longitude', $ipInfo['location'])) {
            return null;
        }
        if (!\is_float($ipInfo['location']['longitude'])) {
            return null;
        }
        if (!\array_key_exists('country', $ipInfo)) {
            return null;
        }
        if (!\is_array($ipInfo['country'])) {
            return null;
        }
        if (!\array_key_exists('iso_code', $ipInfo['country'])) {
            return null;
        }
        if (!\is_string($ipInfo['country']['iso_code'])) {
            return null;
        }

        return new IpInfo(
            $ipInfo['country']['iso_code'],
            \sprintf(
                'geo:%F,%F',
                $ipInfo['location']['latitude'],
                $ipInfo['location']['longitude']
            )
        );
    }

    /**
     * Make sure the PHP extension "maxminddb" is loaded, and the "dataFile"
     * is readable.
     */
    private function checkEnvironment(): bool
    {
        if (!\extension_loaded('maxminddb')) {
            return false;
        }

        return FileIO::exists($this->dataFile);
    }
}
