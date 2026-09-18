<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Tests;

use PHPUnit\Framework\TestCase;
use Vpn\Portal\Http\AccessHook;
use Vpn\Portal\Http\Exception\HttpException;
use Vpn\Portal\Http\Request;
use Vpn\Portal\Http\UserInfo;

/**
 * @coversNothing
 */
final class AccessHookTest extends TestCase
{
    public function testYesAccess(): void
    {
        $a = new AccessHook(['foo', 'bar']);
        $u = new UserInfo('foo', ['foo']);
        static::assertNull(
            $a->afterAuth(
                new Request(['REQUEST_METHOD' => 'GET'], [], [], []),
                $u
            )
        );
    }

    public function testNoAccess(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('your account does not have the required permissions');
        $a = new AccessHook(['foo', 'bar']);
        $u = new UserInfo('foo', ['baz']);
        $a->afterAuth(
            new Request(['REQUEST_METHOD' => 'GET'], [], [], []),
            $u
        );
    }
}
