<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Tests;

use PHPUnit\Framework\TestCase;
use Vpn\Portal\Cfg\ShibAuthConfig;
use Vpn\Portal\Http\Auth\ShibAuthModule;
use Vpn\Portal\Http\Request;

/**
 * @coversNothing
 */
final class ShibAuthModuleTest extends TestCase
{
    public function testAttributeValues(): void
    {
        $s = new ShibAuthModule(new ShibAuthConfig(['userIdAttribute' => 'REMOTE_USER', 'permissionAttributeList' => ['entitlement']]));
        $u = $s->userInfo(new Request(['REMOTE_USER' => 'foo', 'entitlement' => 'a;b;c%aad'], [], [], []));
        static::assertSame('foo', $u->userId());
        static::assertSame(
            [
                'entitlement!a',
                'entitlement!b',
                'entitlement!c%aad',
            ],
            $u->permissionList()
        );
    }

    public function testEncodedAttributeValues(): void
    {
        $s = new ShibAuthModule(new ShibAuthConfig(['userIdAttribute' => 'REMOTE_USER', 'permissionAttributeList' => ['entitlement'], 'encodedAttributeValues' => true]));
        $u = $s->userInfo(new Request(['REMOTE_USER' => 'foo', 'entitlement' => 'a;b;c%20d;%e2%99%a5%ef%b8%8f'], [], [], []));
        static::assertSame('foo', $u->userId());
        static::assertSame(
            [
                'entitlement!a',
                'entitlement!b',
                'entitlement!c d',
                'entitlement!♥️',
            ],
            $u->permissionList()
        );
    }

    public function testRawEncodedAttributeValues(): void
    {
        // we do NOT want to have a "+" decoded to " ", i.e. space
        $s = new ShibAuthModule(new ShibAuthConfig(['userIdAttribute' => 'REMOTE_USER', 'permissionAttributeList' => ['entitlement'], 'encodedAttributeValues' => true]));
        $u = $s->userInfo(new Request(['REMOTE_USER' => 'foo', 'entitlement' => 'a;b;c+d'], [], [], []));
        static::assertSame('foo', $u->userId());
        static::assertSame(
            [
                'entitlement!a',
                'entitlement!b',
                'entitlement!c+d',
            ],
            $u->permissionList()
        );
    }
}
