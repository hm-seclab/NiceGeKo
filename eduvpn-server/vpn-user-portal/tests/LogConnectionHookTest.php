<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vpn\Portal\Cfg\DbConfig;
use Vpn\Portal\Cfg\LogConfig;
use Vpn\Portal\Http\UserInfo;
use Vpn\Portal\LogConnectionHook;
use Vpn\Portal\NodeInfo;
use Vpn\Portal\Storage;

/**
 * @covers \Vpn\Portal\LogConnectionHook
 *
 * @uses \Vpn\Portal\Base64UrlSafe
 * @uses \Vpn\Portal\Cfg\ConfigTrait
 * @uses \Vpn\Portal\Cfg\DbConfig
 * @uses \Vpn\Portal\Cfg\LogConfig
 * @uses \Vpn\Portal\Http\UserInfo
 * @uses \Vpn\Portal\Json
 * @uses \Vpn\Portal\Migration
 * @uses \Vpn\Portal\NodeInfo
 * @uses \Vpn\Portal\Storage
 */
final class LogConnectionHookTest extends TestCase
{
    public function testConnect(): void
    {
        $storage = new Storage(new DbConfig(['dbDsn' => 'sqlite::memory:', 'baseDir' => \dirname(__DIR__)]));
        $nodeInfo = new NodeInfo(42, 'node_url', 'node_host_name');
        $testLogger = new TestLogger();
        $l = new LogConnectionHook($storage, $testLogger, new LogConfig([]));
        $l->connect($nodeInfo, 'user_id', 'profile_id', 'vpn_proto', 'connection_id', 'ip_four', 'ip_six', null, null);
        static::assertSame(
            [
                '[I] CONNECT user_id (profile_id:connection_id) [* => ip_four,ip_six]',
            ],
            $testLogger->getAll()
        );
    }

    public function testConnectOriginatingIp(): void
    {
        $storage = new Storage(new DbConfig(['dbDsn' => 'sqlite::memory:', 'baseDir' => \dirname(__DIR__)]));
        $nodeInfo = new NodeInfo(42, 'node_url', 'node_host_name');
        $testLogger = new TestLogger();
        $l = new LogConnectionHook($storage, $testLogger, new LogConfig(['originatingIp' => true]));
        $l->connect($nodeInfo, 'user_id', 'profile_id', 'vpn_proto', 'connection_id', 'ip_four', 'ip_six', 'originating_ip', null);
        static::assertSame(
            [
                '[I] CONNECT user_id (profile_id:connection_id) [originating_ip => ip_four,ip_six]',
            ],
            $testLogger->getAll()
        );
    }

    public function testConnectAuthDataYesAuthData(): void
    {
        $storage = new Storage(new DbConfig(['dbDsn' => 'sqlite::memory:', 'baseDir' => \dirname(__DIR__)]));
        $storage->userAdd(new UserInfo('user_id', [], 'auth_data'), new DateTimeImmutable());
        $testLogger = new TestLogger();
        $nodeInfo = new NodeInfo(42, 'node_url', 'node_host_name');
        $l = new LogConnectionHook($storage, $testLogger, new LogConfig(['authData' => true]));
        $l->connect($nodeInfo, 'user_id', 'profile_id', 'vpn_proto', 'connection_id', 'ip_four', 'ip_six', null, null);
        static::assertSame(
            [
                '[I] CONNECT user_id (profile_id:connection_id) [* => ip_four,ip_six] [AUTH_DATA=YXV0aF9kYXRh]',
            ],
            $testLogger->getAll()
        );
    }

    public function testConnectAuthDataNoAuthData(): void
    {
        $storage = new Storage(new DbConfig(['dbDsn' => 'sqlite::memory:', 'baseDir' => \dirname(__DIR__)]));
        $nodeInfo = new NodeInfo(42, 'node_url', 'node_host_name');
        $testLogger = new TestLogger();
        $l = new LogConnectionHook($storage, $testLogger, new LogConfig(['authData' => true]));
        $l->connect($nodeInfo, 'user_id', 'profile_id', 'vpn_proto', 'connection_id', 'ip_four', 'ip_six', null, null);
        static::assertSame(
            [
                '[I] CONNECT user_id (profile_id:connection_id) [* => ip_four,ip_six] [AUTH_DATA=]',
            ],
            $testLogger->getAll()
        );
    }

    public function testDisconnect(): void
    {
        $storage = new Storage(new DbConfig(['dbDsn' => 'sqlite::memory:', 'baseDir' => \dirname(__DIR__)]));
        $nodeInfo = new NodeInfo(42, 'node_url', 'node_host_name');
        $testLogger = new TestLogger();
        $l = new LogConnectionHook($storage, $testLogger, new LogConfig([]));
        $l->disconnect($nodeInfo, 'user_id', 'profile_id', 'vpn_proto', 'connection_id', 'ip_four', 'ip_six', 12345, 54321);
        static::assertSame(
            [
                '[I] DISCONNECT user_id (profile_id:connection_id)',
            ],
            $testLogger->getAll()
        );
    }

    public function testConnectTemplate(): void
    {
        $storage = new Storage(new DbConfig(['dbDsn' => 'sqlite::memory:', 'baseDir' => \dirname(__DIR__)]));
        $storage->userAdd(new UserInfo('user_id', [], 'auth_data'), new DateTimeImmutable());
        $nodeInfo = new NodeInfo(42, 'node_url', 'node_host_name');
        $testLogger = new TestLogger();
        $l = new LogConnectionHook(
            $storage,
            $testLogger,
            new LogConfig(
                [
                    'originatingIp' => true,
                    'connectLogTemplate' => 'C,{{NODE_NUMBER}},{{NODE_URL}},{{NODE_HOST_NAME}},{{USER_ID}},{{PROFILE_ID}},{{VPN_PROTO}},{{CONNECTION_ID}},{{IP_FOUR}},{{IP_SIX}},{{ORIGINATING_IP}},{{AUTH_DATA}}',
                ]
            )
        );
        $l->connect($nodeInfo, 'user_id', 'profile_id', 'vpn_proto', 'connection_id', 'ip_four', 'ip_six', 'originating_ip', null);
        static::assertSame(
            [
                '[I] C,42,node_url,node_host_name,user_id,profile_id,vpn_proto,connection_id,ip_four,ip_six,originating_ip,YXV0aF9kYXRh',
            ],
            $testLogger->getAll()
        );
    }

    public function testDisconnectTemplate(): void
    {
        $storage = new Storage(new DbConfig(['dbDsn' => 'sqlite::memory:', 'baseDir' => \dirname(__DIR__)]));
        $storage->userAdd(new UserInfo('user_id', [], 'auth_data'), new DateTimeImmutable());
        $nodeInfo = new NodeInfo(42, 'node_url', 'node_host_name');
        $testLogger = new TestLogger();
        $l = new LogConnectionHook(
            $storage,
            $testLogger,
            new LogConfig(
                [
                    'originatingIp' => true,
                    'disconnectLogTemplate' => 'D,{{NODE_NUMBER}},{{NODE_URL}},{{NODE_HOST_NAME}},{{USER_ID}},{{PROFILE_ID}},{{VPN_PROTO}},{{CONNECTION_ID}},{{IP_FOUR}},{{IP_SIX}},{{BYTES_IN}},{{BYTES_OUT}},{{AUTH_DATA}}',
                ]
            )
        );
        $l->disconnect($nodeInfo, 'user_id', 'profile_id', 'vpn_proto', 'connection_id', 'ip_four', 'ip_six', 12345, 54321);
        static::assertSame(
            [
                '[I] D,42,node_url,node_host_name,user_id,profile_id,vpn_proto,connection_id,ip_four,ip_six,12345,54321,YXV0aF9kYXRh',
            ],
            $testLogger->getAll()
        );
    }
}
