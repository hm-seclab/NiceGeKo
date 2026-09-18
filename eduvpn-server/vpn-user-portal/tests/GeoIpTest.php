<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Tests;

use PHPUnit\Framework\TestCase;
use Vpn\Portal\GeoIp;

/**
 * Test our GeoIp class.
 *
 * We got the test data from https://github.com/maxmind/MaxMind-DB/ which
 * includes tiny databases. The test data, in "test-data" is generated from
 * the "source-data" folder, so you can check the "contents" of the database
 * as JSON.
 *
 * @covers \Vpn\Portal\GeoIp
 *
 * @uses \Vpn\Portal\IpInfo
 * @uses \Vpn\Portal\FileIO
 */
final class GeoIpTest extends TestCase
{
    public function testCity(): void
    {
        if (\extension_loaded('maxminddb')) {
            $geoIp = new GeoIp(__DIR__ . '/data/GeoIP2-City-Test.mmdb');
            $ipInfo = $geoIp->get('2001:218::1');
            static::assertNotNull($ipInfo);
            static::assertSame('JP', $ipInfo->countryCode());
            static::assertSame('geo:35.685360,139.753090', $ipInfo->geoUri());

            return;
        }
        static::markTestSkipped();
    }

    public function testPrivate(): void
    {
        if (\extension_loaded('maxminddb')) {
            $geoIp = new GeoIp(__DIR__ . '/data/GeoIP2-City-Test.mmdb');
            static::assertNull($geoIp->get('192.168.1.1'));

            return;
        }
        static::markTestSkipped();
    }
}
