<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Tests;

use PHPUnit\Framework\TestCase;
use Vpn\Portal\Http\AdminHook;
use Vpn\Portal\Http\Request;
use Vpn\Portal\Http\UserInfo;

/**
 * @coversNothing
 */
final class AdminHookTest extends TestCase
{
    public function testNoAdmin(): void
    {
        $tpl = new TestTpl();
        $a = new AdminHook(['xyz'], ['bar'], $tpl);
        $u = new UserInfo('foo', ['p1', 'p2']);
        static::assertNull($a->afterAuth(new Request([], [], [], []), $u));
        static::assertFalse($u->isAdmin());
        static::assertSame('{"foo":[]}', $tpl->render('foo', []));
    }

    public function testAdminPermissionList(): void
    {
        $tpl = new TestTpl();
        $a = new AdminHook(['p1'], ['bar'], $tpl);
        $u = new UserInfo('foo', ['p1', 'p2']);
        static::assertNull($a->afterAuth(new Request([], [], [], []), $u));
        static::assertTrue($u->isAdmin());
        static::assertSame('{"foo":{"isAdmin":true}}', $tpl->render('foo', []));
    }

    public function testAdminUserId(): void
    {
        $tpl = new TestTpl();
        $a = new AdminHook(['xyz'], ['foo'], $tpl);
        $u = new UserInfo('foo', ['p1', 'p2']);
        static::assertNull($a->afterAuth(new Request([], [], [], []), $u));
        static::assertTrue($u->isAdmin());
        static::assertSame('{"foo":{"isAdmin":true}}', $tpl->render('foo', []));
    }
}
