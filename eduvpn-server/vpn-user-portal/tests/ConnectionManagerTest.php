<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Tests;

use DateInterval;
use DateTimeImmutable;
use fkooman\OAuth\Server\Signer;
use PHPUnit\Framework\TestCase;
use Vpn\Portal\Cfg\Config;
use Vpn\Portal\Http\UserInfo;
use Vpn\Portal\NullLogger;
use Vpn\Portal\OpenVpn\TlsCrypt;
use Vpn\Portal\ProtoSupport;
use Vpn\Portal\ServerInfo;
use Vpn\Portal\Storage;
use Vpn\Portal\VpnDaemon;
use Vpn\Portal\WireGuard\Key;

/**
 * @internal
 *
 * @coversNothing
 */
final class ConnectionManagerTest extends TestCase
{
    private TestHttpClient $httpClient;

    #[\Override]
    protected function setUp(): void
    {
        $this->httpClient = new TestHttpClient();
    }

    /**
     * Testing a protocol selection bug.
     *
     * This tests for a PHP behavior change between PHP 7 and 8. Debian 11 has
     * PHP 7.4, and Debian 12 has PHP 8.2. On Debian 11 this would result in
     * a TCP WireGuard configuration, even when "Prefer TCP" was off.
     * Technically it is not "wrong" to offer a TCP configuration, but the
     * expectation would be to get a UDP configuration when the "Prefer TCP"
     * toggle is off.
     *
     * @see https://codeberg.org/eduVPN/vpn-user-portal/issues/35
     */
    public function testConnectWantWireGuardUdp(): void
    {
        $tmpDir = \sprintf('%s/vpn-user-portal-%s', sys_get_temp_dir(), bin2hex(random_bytes(32)));
        mkdir($tmpDir);
        TestKeys::tlsCrypt($tmpDir, 'default');
        TestKeys::nodePublicKey($tmpDir);
        $dateTime = new DateTimeImmutable();
        $baseDir = \dirname(__DIR__);
        $config = new Config(
            [
                'Db' => [
                    'dbDsn' => 'sqlite::memory:',
                ],
                'WireGuard' => [
                    'enableProxy' => true,
                ],
                'ProfileList' => [
                    [
                        'profileId' => 'default',
                        'displayName' => 'Default',
                        'hostName' => 'vpn.example.org',
                        'dnsServerList' => ['9.9.9.9', '2620:fe::fe'],
                        'preferredProto' => 'wireguard',
                        'wRangeFour' => '10.71.160.0/20',
                        'wRangeSix' => 'fd1c:66f8:6d49:30cc::/64',
                        'oRangeFour' => '10.37.144.0/24',
                        'oRangeSix' => 'fdde:1b48:a504:c58c::/64',
                        'oUdpPortList' => [],
                    ],
                ],
            ]
        );
        $storage = new Storage($config->dbConfig($baseDir));
        $storage->userAdd(new UserInfo('foo', []), $dateTime);
        $vpnDaemon = new VpnDaemon(
            $this->httpClient,
            new NullLogger()
        );

        $ca = new TestCa();
        $serverInfo = new ServerInfo(
            'https://vpn.example.org/vpn-user-portal',
            $tmpDir,
            new TestCa(),
            new TlsCrypt($tmpDir),
            $config->wireGuardConfig(),
            $config->openVpnConfig(),
            Signer::publicKeyFromSecretKey(Signer::generateSecretKey())
        );

        $connectionManager = new TestConnectionManager($config, $vpnDaemon, $storage);
        $clientConfig = $connectionManager->connect(
            $serverInfo,
            $config->profileConfig('default'),
            'foo',
            new ProtoSupport(oUdp: true, oTcp: true, wUdp: true, wTcp: true),
            'Test Config',
            $dateTime->add(new DateInterval('P90D')),
            false,  // "Prefer TCP" is off
            Key::publicKeyFromSecretKey(Key::generate()),
            null
        );
        // we should get a WireGuard UDP profile, *NOT* TCP...
        static::assertSame('application/x-wireguard-profile', $clientConfig->contentType());
    }

    /**
     * Testing a protocol selection bug.
     *
     * This test fails with the 3.7.1 version of ConnectionManager.php and
     * returns a wireguard+tcp config
     *
     * @see https://codeberg.org/eduVPN/vpn-user-portal/issues/35
     */
    public function testConnect(): void
    {
        $tmpDir = \sprintf('%s/vpn-user-portal-%s', sys_get_temp_dir(), bin2hex(random_bytes(32)));
        mkdir($tmpDir);
        TestKeys::tlsCrypt($tmpDir, 'default');
        TestKeys::nodePublicKey($tmpDir);
        $dateTime = new DateTimeImmutable();
        $baseDir = \dirname(__DIR__);
        $config = new Config(
            [
                'Db' => [
                    'dbDsn' => 'sqlite::memory:',
                ],
                'WireGuard' => [
                    'enableProxy' => true,
                ],
                'ProfileList' => [
                    [
                        'profileId' => 'default',
                        'displayName' => 'Default',
                        'hostName' => 'vpn.example.org',
                        'dnsServerList' => ['9.9.9.9', '2620:fe::fe'],
                        'preferredProto' => 'wireguard',
                        'wRangeFour' => '10.71.160.0/20',
                        'wRangeSix' => 'fd1c:66f8:6d49:30cc::/64',
                        'oRangeFour' => '10.37.144.0/24',
                        'oRangeSix' => 'fdde:1b48:a504:c58c::/64',
                        'oUdpPortList' => [],
                    ],
                ],
            ]
        );
        $storage = new Storage($config->dbConfig($baseDir));
        $storage->userAdd(new UserInfo('foo', []), $dateTime);
        $vpnDaemon = new VpnDaemon(
            $this->httpClient,
            new NullLogger()
        );

        $ca = new TestCa();
        $serverInfo = new ServerInfo(
            'https://vpn.example.org/vpn-user-portal',
            $tmpDir,
            new TestCa(),
            new TlsCrypt($tmpDir),
            $config->wireGuardConfig(),
            $config->openVpnConfig(),
            Signer::publicKeyFromSecretKey(Signer::generateSecretKey())
        );

        $connectionManager = new TestConnectionManager($config, $vpnDaemon, $storage);
        $clientConfig = $connectionManager->connect(
            $serverInfo,
            $config->profileConfig('default'),
            'foo',
            new ProtoSupport(oUdp: true, oTcp: true, wUdp: true, wTcp: false),
            'Test Config',
            $dateTime->add(new DateInterval('P90D')),
            true,
            Key::publicKeyFromSecretKey(Key::generate()),
            null
        );
        // we must make sure we get an OpenVPN config as the client does NOT
        // support WireGuard+TCP
        static::assertSame('application/x-openvpn-profile', $clientConfig->contentType());
    }

    public function testSync(): void
    {
        $dateTime = new DateTimeImmutable();
        $config = new Config(
            [
                'Db' => [
                    'dbDsn' => 'sqlite::memory:',
                ],
                'ProfileList' => [
                    [
                        'profileId' => 'default',
                        'displayName' => 'Default (Prefer OpenVPN)',
                        'hostName' => 'vpn.example',
                        'dnsServerList' => ['9.9.9.9', '2620:fe::fe'],
                        'wRangeFour' => '10.43.43.0/24',
                        'wRangeSix' => 'fd43::/64',
                        'oRangeFour' => '10.42.42.0/24',
                        'oRangeSix' => 'fd42::/64',
                    ],
                ],
            ]
        );

        $baseDir = \dirname(__DIR__);
        $storage = new Storage($config->dbConfig($baseDir));
        $storage->userAdd(new UserInfo('user_id', []), $dateTime);
        $storage->wPeerAdd('user_id', 0, 'default', 'My Test', Key::publicKeyFromSecretKey(Key::generate()), '10.43.43.5', 'fd43::5', $dateTime, $dateTime->add($config->sessionExpiry()), null);

        $vpnDaemon = new VpnDaemon(
            $this->httpClient,
            new NullLogger()
        );
        $connectionManager = new TestConnectionManager($config, $vpnDaemon, $storage);
        $connectionManager->sync();

        // this is not yet super useful test, but the basis is there for more
        // extensive tests if we run into trouble
        static::assertCount(4, $this->httpClient->logData());
    }
}
