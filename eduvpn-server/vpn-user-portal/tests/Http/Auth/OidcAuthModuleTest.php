<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Tests;

use PHPUnit\Framework\TestCase;
use Vpn\Portal\Cfg\OidcAuthConfig;
use Vpn\Portal\Http\Auth\OidcAuthModule;
use Vpn\Portal\Http\Request;

/**
 * @coversNothing
 */
final class OidcAuthModuleTest extends TestCase
{
    public function testSimpleCase(): void
    {
        $o = new OidcAuthModule(new OidcAuthConfig(['permissionAttributeList' => ['OIDC_CLAIM_groups']]));
        $u = $o->userInfo(new Request(['REMOTE_USER' => 'foo', 'OIDC_CLAIM_groups' => 'one,two,three'], [], [], []));
        static::assertSame('foo', $u->userId());
        static::assertSame(['OIDC_CLAIM_groups!one', 'OIDC_CLAIM_groups!two', 'OIDC_CLAIM_groups!three'], $u->permissionList());
    }

    public function testClaimDelimiter(): void
    {
        $o = new OidcAuthModule(new OidcAuthConfig(['claimDelimiter' => ';', 'permissionAttributeList' => ['OIDC_CLAIM_groups']]));
        $u = $o->userInfo(new Request(['REMOTE_USER' => 'foo', 'OIDC_CLAIM_groups' => 'one,two,three;four,five,six'], [], [], []));
        static::assertSame('foo', $u->userId());
        static::assertSame(['OIDC_CLAIM_groups!one,two,three', 'OIDC_CLAIM_groups!four,five,six'], $u->permissionList());
    }

    /**
     * Test whitespace around the "delimiter".
     */
    public function testWhitespace(): void
    {
        $o = new OidcAuthModule(new OidcAuthConfig(['permissionAttributeList' => ['OIDC_CLAIM_groups']]));
        $u = $o->userInfo(new Request(['REMOTE_USER' => 'foo', 'OIDC_CLAIM_groups' => 'one , two , three'], [], [], []));
        static::assertSame('foo', $u->userId());
        static::assertSame(['OIDC_CLAIM_groups!one', 'OIDC_CLAIM_groups!two', 'OIDC_CLAIM_groups!three'], $u->permissionList());
    }
}
