<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\OAuth\Tests;

use PHPUnit\Framework\TestCase;
use Vpn\Portal\OAuth\VpnClientDb;

/**
 * @internal
 *
 * @coversNothing
 */
final class VpnClientDbTest extends TestCase
{
    public function testWindows(): void
    {
        $clientDb = new VpnClientDb(__DIR__ . '/does_not_exist.json', ['eduVPN', 'LC']);
        $clientInfo = $clientDb->get('org.eduvpn.app.windows');
        static::assertNotNull($clientInfo);
        static::assertSame('org.eduvpn.app.windows', $clientInfo->clientId());
        static::assertSame('eduVPN for Windows', $clientInfo->displayName());

        $clientInfo = $clientDb->get('org.letsconnect-vpn.app.windows');
        static::assertNotNull($clientInfo);
        static::assertSame('org.letsconnect-vpn.app.windows', $clientInfo->clientId());
        static::assertSame('Let\'s Connect! for Windows', $clientInfo->displayName());
    }

    public function testAndroid(): void
    {
        $clientDb = new VpnClientDb(__DIR__ . '/does_not_exist.json', ['eduVPN', 'LC']);
        $clientInfo = $clientDb->get('org.eduvpn.app.android');
        static::assertNotNull($clientInfo);
        static::assertSame('org.eduvpn.app.android', $clientInfo->clientId());
        static::assertSame('eduVPN for Android', $clientInfo->displayName());
        static::assertTrue($clientInfo->isValidRedirectUri('org.eduvpn.app:/api/callback'));

        $clientInfo = $clientDb->get('org.letsconnect-vpn.app.android');
        static::assertNotNull($clientInfo);
        static::assertSame('org.letsconnect-vpn.app.android', $clientInfo->clientId());
        static::assertSame('Let\'s Connect! for Android', $clientInfo->displayName());
        static::assertTrue($clientInfo->isValidRedirectUri('org.letsconnect-vpn.app:/api/callback'));
    }

    public function testiOS(): void
    {
        $clientDb = new VpnClientDb(__DIR__ . '/does_not_exist.json', ['eduVPN', 'LC']);
        $clientInfo = $clientDb->get('org.eduvpn.app.ios');
        static::assertNotNull($clientInfo);
        static::assertSame('org.eduvpn.app.ios', $clientInfo->clientId());
        static::assertSame('eduVPN for iOS', $clientInfo->displayName());
        static::assertTrue($clientInfo->isValidRedirectUri('org.eduvpn.app.ios:/api/callback'));

        $clientInfo = $clientDb->get('org.letsconnect-vpn.app.ios');
        static::assertNotNull($clientInfo);
        static::assertSame('org.letsconnect-vpn.app.ios', $clientInfo->clientId());
        static::assertSame('Let\'s Connect! for iOS', $clientInfo->displayName());
        static::assertTrue($clientInfo->isValidRedirectUri('org.letsconnect-vpn.app.ios:/api/callback'));
    }

    public function testJsonFile(): void
    {
        $clientDb = new VpnClientDb(__DIR__ . '/oauth_client_db.json', ['eduVPN', 'LC']);
        $clientInfo = $clientDb->get('foo');
        static::assertNotNull($clientInfo);
        static::assertSame('foo', $clientInfo->clientId());
        static::assertSame(['https://foo.example.org/callback'], $clientInfo->redirectUriList());
    }
}
