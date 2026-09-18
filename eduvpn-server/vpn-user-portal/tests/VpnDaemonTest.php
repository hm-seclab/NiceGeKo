<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Tests;

use PHPUnit\Framework\TestCase;
use Vpn\Portal\NullLogger;
use Vpn\Portal\VpnDaemon;

/**
 * @covers \Vpn\Portal\VpnDaemon
 *
 * @uses \Vpn\Portal\HttpClient\HttpClientRequest
 * @uses \Vpn\Portal\HttpClient\HttpClientResponse
 * @uses \Vpn\Portal\Json
 */
final class VpnDaemonTest extends TestCase
{
    private TestHttpClient $httpClient;

    #[\Override]
    protected function setUp(): void
    {
        $this->httpClient = new TestHttpClient();
    }

    public function testNodeInfo(): void
    {
        $vpnDaemon = new VpnDaemon(
            $this->httpClient,
            new NullLogger()
        );
        static::assertSame(
            [
                'rel_load_average' => [
                    24,
                    25,
                    31,
                ],
                'load_average' => [
                    0.48,
                    0.5,
                    0.63,
                ],
                'cpu_count' => 2,
                'node_uptime' => 12345,
                'maintenance_mode' => false,
                'connection_count' => 0,
                'v' => '3.0.0',
            ],
            $vpnDaemon->nodeInfo('http://localhost:41194', false)
        );
    }
}
