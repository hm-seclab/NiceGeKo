<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Tests;

use DateTimeImmutable;
use fkooman\OAuth\Server\PdoStorage as OAuthStorage;
use fkooman\OAuth\Server\Scope;
use PHPUnit\Framework\TestCase;
use Vpn\Portal\Cfg\Config;
use Vpn\Portal\FileIO;
use Vpn\Portal\Http\ApiService;
use Vpn\Portal\Http\Request;
use Vpn\Portal\Http\UserInfo;
use Vpn\Portal\NullLogger;
use Vpn\Portal\OpenVpn\TlsCrypt;
use Vpn\Portal\ServerInfo;
use Vpn\Portal\Storage;
use Vpn\Portal\VpnDaemon;
use Vpn\Portal\WireGuard\Key;

/**
 * @covers \Vpn\Portal\Http\VpnApiThreeModule
 *
 * @uses \Vpn\Portal\Base64
 * @uses \Vpn\Portal\Cfg\ApiConfig
 * @uses \Vpn\Portal\Storage
 * @uses \Vpn\Portal\Http\UserInfo
 * @uses \Vpn\Portal\Protocol
 * @uses \Vpn\Portal\Migration
 * @uses \Vpn\Portal\OpenVpn\TlsCrypt
 * @uses \Vpn\Portal\ServerInfo
 * @uses \Vpn\Portal\VpnDaemon
 * @uses \Vpn\Portal\PermissionSourceManager
 * @uses \Vpn\Portal\Http\Request
 * @uses \Vpn\Portal\Http\JsonResponse
 * @uses \Vpn\Portal\FileIO
 * @uses \Vpn\Portal\Cfg\WireGuardConfig
 * @uses \Vpn\Portal\Cfg\ProfileConfig
 * @uses \Vpn\Portal\Cfg\DbConfig
 * @uses \Vpn\Portal\Json
 * @uses \Vpn\Portal\Http\ApiService
 * @uses \Vpn\Portal\Cfg\Config
 * @uses \Vpn\Portal\Validator
 * @uses \Vpn\Portal\Http\Response
 * @uses \Vpn\Portal\ConnectionManager
 * @uses \Vpn\Portal\ConnectionHooks
 * @uses \Vpn\Portal\OpenVpn\ClientConfig
 * @uses \Vpn\Portal\OpenVpn\CA\CertInfo
 * @uses \Vpn\Portal\OpenVpn\CA\CaInfo
 * @uses \Vpn\Portal\Ip
 * @uses \Vpn\Portal\WireGuard\Key
 * @uses \Vpn\Portal\NullLogger
 * @uses \Vpn\Portal\Http\Exception\HttpException
 * @uses \Vpn\Portal\HttpClient\HttpClientRequest
 * @uses \Vpn\Portal\HttpClient\HttpClientResponse
 * @uses \Vpn\Portal\Http\ApiUserInfo
 * @uses \Vpn\Portal\Dt
 * @uses \Vpn\Portal\IpNetList
 * @uses \Vpn\Portal\NodeInfo
 * @uses \Vpn\Portal\WireGuard\ClientConfig
 */
final class VpnApiThreeModuleTest extends TestCase
{
    private Config $config;
    private ApiService $service;
    private Storage $storage;
    private DateTimeImmutable $dateTime;
    private string $tlsCryptDefault;
    private string $tlsCryptDefaultWg;
    private string $nodePublicKey;

    #[\Override]
    protected function setUp(): void
    {
        $this->config = new Config(
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
                    [
                        'profileId' => 'default-wg',
                        'displayName' => 'Default (Prefer WireGuard)',
                        'hostName' => 'vpn.example',
                        'dnsServerList' => ['9.9.9.9', '2620:fe::fe'],
                        'wRangeFour' => '10.44.44.0/29',
                        'wRangeSix' => 'fd44::/64',
                        'oRangeFour' => '10.45.45.0/24',
                        'oRangeSix' => 'fd45::/64',
                        'preferredProto' => 'wireguard',
                    ],
                    [
                        'profileId' => 'default-wg-only',
                        'displayName' => 'Default (WireGuard Only)',
                        'hostName' => 'vpn.example',
                        'dnsServerList' => ['9.9.9.9', '2620:fe::fe'],
                        'wRangeFour' => '10.46.46.0/29',
                        'wRangeSix' => 'fd46::/64',
                    ],
                ],
            ]
        );

        $this->dateTime = new DateTimeImmutable('2022-01-01T09:00:00+00:00');
        $tmpDir = \sprintf('%s/vpn-user-portal-%s', sys_get_temp_dir(), bin2hex(random_bytes(32)));
        mkdir($tmpDir);
        $this->tlsCryptDefaultWg = TestKeys::tlsCrypt($tmpDir, 'default-wg');
        $this->tlsCryptDefault = TestKeys::tlsCrypt($tmpDir, 'default');
        $this->nodePublicKey = TestKeys::nodePublicKey($tmpDir);

        $baseDir = \dirname(__DIR__, 2);

        $this->storage = new Storage($this->config->dbConfig($baseDir));

        // XXX the user & authorization MUST exist apparently, this will NOT work with guest usage!
        $this->storage->userAdd(new UserInfo('user_id', []), $this->dateTime);
        $oauthStorage = new OAuthStorage($this->storage->dbPdo(), 'oauth_');
        $oauthStorage->storeAuthorization('user_id', 'client_id', new Scope('config'), 'auth_key', $this->dateTime, $this->dateTime->add($this->config->sessionExpiry()));

        $apiModule = new TestVpnApiThreeModule(
            $this->config,
            $this->storage,
            new ServerInfo(
                'https://vpn.example.org/',
                $tmpDir,
                new TestCa(),
                new TlsCrypt($tmpDir),
                $this->config->wireGuardConfig(),
                $this->config->openVpnConfig(),
                'gc6RjjPtIKeflbOun+dyAssnsdXzD6bmWisbxJrZiB0=',
            ),
            new TestConnectionManager(
                $this->config,
                new VpnDaemon(
                    new TestHttpClient(),
                    new NullLogger()
                ),
                $this->storage
            )
        );
        $this->service = new ApiService(new TestValidator());
        $this->service->addModule($apiModule);
    }

    public function testInfo(): void
    {
        $request = new Request(
            [
                'REQUEST_URI' => '/v3/info',
                'REQUEST_METHOD' => 'GET',
            ],
            [],
            [],
            []
        );

        static::assertSame(
            '{"info":{"profile_list":[{"profile_id":"default","profile_priority":0,"display_name":"Default (Prefer OpenVPN)","vpn_proto_list":["openvpn","wireguard"],"default_gateway":true,"vpn_proto_transport_list":["openvpn+udp","openvpn+tcp","wireguard+udp"]},{"profile_id":"default-wg","profile_priority":0,"display_name":"Default (Prefer WireGuard)","vpn_proto_list":["openvpn","wireguard"],"default_gateway":true,"vpn_proto_transport_list":["openvpn+udp","openvpn+tcp","wireguard+udp"]},{"profile_id":"default-wg-only","profile_priority":0,"display_name":"Default (WireGuard Only)","vpn_proto_list":["wireguard"],"default_gateway":true,"vpn_proto_transport_list":["wireguard+udp"]}]}}',
            $this->service->run($request)->responseBody()
        );
    }

    public function testConnectMissingProfile(): void
    {
        $request = new Request(
            [
                'REQUEST_URI' => '/v3/connect',
                'REQUEST_METHOD' => 'POST',
            ],
            [],
            [
                'profile_id' => 'missing-profile',
                // we specify random pubic key here, it doesn't come back in
                // the WireGuard config anyway...
                'public_key' => Key::publicKeyFromSecretKey(Key::generate()),
            ],
            []
        );

        $httpResponse = $this->service->run($request);
        static::assertSame(404, $httpResponse->statusCode());
        static::assertSame('{"error":"no such \"profile_id\""}', $httpResponse->responseBody());
    }

    public function testConnectInvalidProfileSyntax(): void
    {
        $request = new Request(
            [
                'REQUEST_URI' => '/v3/connect',
                'REQUEST_METHOD' => 'POST',
            ],
            [],
            [
                'profile_id' => 'invalid%profile',
                // we specify random pubic key here, it doesn't come back in
                // the WireGuard config anyway...
                'public_key' => Key::publicKeyFromSecretKey(Key::generate()),
            ],
            []
        );

        $httpResponse = $this->service->run($request);
        static::assertSame(400, $httpResponse->statusCode());
        static::assertSame('{"error":"invalid value for \"profile_id\""}', $httpResponse->responseBody());
    }

    public function testConnectOpenVpn(): void
    {
        $request = new Request(
            [
                'REQUEST_URI' => '/v3/connect',
                'REQUEST_METHOD' => 'POST',
            ],
            [],
            [
                'profile_id' => 'default',
            ],
            []
        );

        static::assertSame(
            $this->expectedConfig('expected_openvpn_client_config.txt'),
            $this->service->run($request)->responseBody()
        );

        static::assertSame(
            [
                [
                    'user_id' => 'user_id',
                    'profile_id' => 'default',
                    'node_number' => 0,
                    'common_name' => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
                ],
            ],
            $this->storage->oCertInfoListByAuthKey('auth_key')
        );
    }

    public function testDisconnectOpenVpn(): void
    {
        $this->storage->oCertAdd('user_id', 0, 'default', 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', 'display_name', $this->dateTime, $this->dateTime->add($this->config->sessionExpiry()), 'auth_key');
        static::assertSame(
            [
                [
                    'user_id' => 'user_id',
                    'profile_id' => 'default',
                    'node_number' => 0,
                    'common_name' => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
                ],
            ],
            $this->storage->oCertInfoListByAuthKey('auth_key')
        );

        $request = new Request(
            [
                'REQUEST_URI' => '/v3/disconnect',
                'REQUEST_METHOD' => 'POST',
            ],
            [],
            [],
            []
        );

        static::assertSame(
            204,
            $this->service->run($request)->statusCode()
        );

        static::assertEmpty($this->storage->oCertInfoListByAuthKey('auth_key'));
    }

    public function testConnectWireGuard(): void
    {
        $request = new Request(
            [
                'REQUEST_URI' => '/v3/connect',
                'REQUEST_METHOD' => 'POST',
            ],
            [],
            [
                'profile_id' => 'default-wg',
                // we specify random pubic key here, it doesn't come back in
                // the WireGuard config anyway...
                'public_key' => Key::publicKeyFromSecretKey(Key::generate()),
            ],
            []
        );

        static::assertSame(
            $this->expectedConfig('expected_wireguard_client_config.txt'),
            $this->service->run($request)->responseBody()
        );
    }

    public function testPreferTcp(): void
    {
        $request = new Request(
            [
                'REQUEST_URI' => '/v3/connect',
                'REQUEST_METHOD' => 'POST',
            ],
            [],
            [
                'profile_id' => 'default',
                'prefer_tcp' => 'yes',
            ],
            []
        );

        static::assertSame(
            $this->expectedConfig('expected_openvpn_client_config_prefer_tcp.txt'),
            $this->service->run($request)->responseBody()
        );
    }

    /**
     * Here we connect using a client that only supports Wireguard, to a
     * profile that prefers OpenVPN. If the client would support both protocols
     * OpenVPN would be chosen, but as we only support WireGuard, we get
     * WireGuard here...
     */
    public function testOnlyWireGuardSupport(): void
    {
        $request = new Request(
            [
                'REQUEST_URI' => '/v3/connect',
                'REQUEST_METHOD' => 'POST',
                'HTTP_ACCEPT' => 'application/x-wireguard-profile',
            ],
            [],
            [
                'profile_id' => 'default',
                'public_key' => Key::publicKeyFromSecretKey(Key::generate()),
            ],
            []
        );

        static::assertSame(
            $this->expectedConfig('expected_wireguard_client_config_wireguard_only_client.txt'),
            $this->service->run($request)->responseBody()
        );
    }

    public function testOpenVpnOnlyClientToWireGuardOnlyProfile(): void
    {
        $request = new Request(
            [
                'REQUEST_URI' => '/v3/connect',
                'REQUEST_METHOD' => 'POST',
                'HTTP_ACCEPT' => 'application/x-openvpn-profile',
            ],
            [],
            [
                'profile_id' => 'default-wg-only',
            ],
            []
        );

        $httpResponse = $this->service->run($request);
        static::assertSame(406, $httpResponse->statusCode());
        static::assertSame('{"error":"no common VPN protocol [CLIENT=openvpn+udp,openvpn+tcp SERVER=wireguard+udp]"}', $httpResponse->responseBody());
    }

    public function testNoPubKeyToWireGuardOnlyProfile(): void
    {
        $request = new Request(
            [
                'REQUEST_URI' => '/v3/connect',
                'REQUEST_METHOD' => 'POST',
            ],
            [],
            [
                'profile_id' => 'default-wg-only',
            ],
            []
        );

        $httpResponse = $this->service->run($request);
        static::assertSame(406, $httpResponse->statusCode());
        static::assertSame('{"error":"no common VPN protocol [CLIENT=openvpn+udp,openvpn+tcp SERVER=wireguard+udp]"}', $httpResponse->responseBody());
    }

    public function testNoMoreAvailableWireGuardIp(): void
    {
        // use up all IPs so the client cannot get a WireGuard config
        for ($i = 2; $i < 7; ++$i) {
            $this->storage->wPeerAdd('user_id', 0, 'default-wg', 'My Test', Key::publicKeyFromSecretKey(Key::generate()), '10.44.44.' . $i, 'fd44::' . $i, $this->dateTime, $this->dateTime->add($this->config->sessionExpiry()), null);
        }

        $request = new Request(
            [
                'REQUEST_URI' => '/v3/connect',
                'REQUEST_METHOD' => 'POST',
            ],
            [],
            [
                'profile_id' => 'default-wg',
                'public_key' => Key::publicKeyFromSecretKey(Key::generate()),
            ],
            []
        );

        $httpResponse = $this->service->run($request);

        // as the IP addresses for WireGuard are depleted, we fall back to OpenVPN
        static::assertSame(
            $this->expectedConfig('expected_openvpn_client_config-default-wg.txt'),
            $this->service->run($request)->responseBody()
        );
    }

    /**
     * Read an expected client configuration, filling in the key material that
     * was generated for this test run.
     *
     * The keys are no longer fixtures on disk (the GitLab group rejects files
     * matching \.(pem|key)$ on push), so the expected output carries
     * placeholders instead of hardcoded key material.
     */
    private function expectedConfig(string $fileName): string
    {
        return trim(
            str_replace(
                ['{{TLS_CRYPT_DEFAULT_WG}}', '{{TLS_CRYPT_DEFAULT}}', '{{NODE_PUBLIC_KEY}}'],
                [$this->tlsCryptDefaultWg, $this->tlsCryptDefault, $this->nodePublicKey],
                FileIO::read(\dirname(__DIR__) . '/data/' . $fileName)
            )
        );
    }
}
