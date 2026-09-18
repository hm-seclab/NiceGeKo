<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Tests;

use PHPUnit\Framework\TestCase;
use Vpn\Portal\Http\UserInfo;

/**
 * @covers \Vpn\Portal\Http\UserInfo
 */
final class UserInfoTest extends TestCase
{
    public function testNoExpiry(): void
    {
        $userInfo = new UserInfo(
            'foo',
            []
        );
        static::assertCount(0, $userInfo->sessionExpiry());
    }

    public function testOneExpiry(): void
    {
        $userInfo = new UserInfo(
            'foo',
            [
                'https://eduvpn.org/expiry#P1Y',
            ]
        );
        static::assertSame(
            [
                'P1Y',
            ],
            $userInfo->sessionExpiry()
        );
    }

    public function testExpiryFromCapability(): void
    {
        $userInfo = new UserInfo(
            'foo',
            [
                'urn:example.org:res:eduvpn.org:session-expiry:P99D#idp.example.org',
            ]
        );
        static::assertSame(
            [
                'P99D',
            ],
            $userInfo->sessionExpiry()
        );
    }

    public function testMultipleExpiries(): void
    {
        $userInfo = new UserInfo(
            'foo',
            [
                'https://eduvpn.org/expiry#P1Y',
                'eduPersonEntitlement!https://eduvpn.org/expiry#PT12H',
            ]
        );
        static::assertSame(
            [
                'P1Y',
                'PT12H',
            ],
            $userInfo->sessionExpiry()
        );
    }

    public function testHasAnyLegacyPermission(): void
    {
        $u = new UserInfo(
            'foo',
            [
                'one',
                'two',
            ]
        );

        static::assertTrue($u->hasAnyPermission(['one']));
        static::assertTrue($u->hasAnyPermission(['two']));
        static::assertTrue($u->hasAnyPermission(['three', 'one']));
        static::assertFalse($u->hasAnyPermission([]));
        static::assertFalse($u->hasAnyPermission(['three']));
        static::assertFalse($u->hasAnyPermission(['three', 'four']));
    }

    public function testHasAnyAttributePermission(): void
    {
        $u = new UserInfo(
            'foo',
            [
                'foo',
                'isMember!one',
                'isMember!two',
                'foo!bar!baz',  // attribute=foo, value=bar!baz
            ]
        );

        static::assertTrue($u->hasAnyPermission(['foo']));
        static::assertTrue($u->hasAnyPermission(['one']));
        static::assertTrue($u->hasAnyPermission(['two']));
        static::assertTrue($u->hasAnyPermission(['isMember!one']));
        static::assertTrue($u->hasAnyPermission(['isMember!two']));
        static::assertTrue($u->hasAnyPermission(['isMember!three', 'isMember!one']));
        static::assertFalse($u->hasAnyPermission(['bar']));
        static::assertFalse($u->hasAnyPermission(['isMember!foo']));
        static::assertFalse($u->hasAnyPermission([]));
        static::assertFalse($u->hasAnyPermission(['isMember!three']));
        static::assertFalse($u->hasAnyPermission(['isMember!three', 'isMember!four']));
        static::assertTrue($u->hasAnyPermission(['foo!bar!baz']));
        static::assertTrue($u->hasAnyPermission(['bar!baz']));
    }
}
