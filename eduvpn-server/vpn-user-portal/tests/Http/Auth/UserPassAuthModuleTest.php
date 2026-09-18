<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Tests;

use PHPUnit\Framework\TestCase;
use Vpn\Portal\Http\Auth\UserPassAuthModule;

/**
 * @coversNothing
 */
final class UserPassAuthModuleTest extends TestCase
{
    public function testSanitize(): void
    {
        static::assertSame('foo', UserPassAuthModule::sanitizeUserId('foo'));
        static::assertSame('foo_', UserPassAuthModule::sanitizeUserId('foo*'));
        static::assertSame('_foo_', UserPassAuthModule::sanitizeUserId('*foo*'));
        static::assertSame('_foo_foo_', UserPassAuthModule::sanitizeUserId('*foo*foo*'));
        static::assertSame('foo__bar', UserPassAuthModule::sanitizeUserId("foo\n\nbar"));
        static::assertSame('foo.bar', UserPassAuthModule::sanitizeUserId("foo.bar"));
        static::assertSame('foo..bar', UserPassAuthModule::sanitizeUserId("foo..bar"));
        static::assertSame('x_y', UserPassAuthModule::sanitizeUserId("x_y"));
        static::assertSame('x-y-z', UserPassAuthModule::sanitizeUserId("x-y-z"));
        static::assertSame('a_b', UserPassAuthModule::sanitizeUserId('a\b'));
        static::assertSame('.._foo_bar', UserPassAuthModule::sanitizeUserId('../foo/bar'));
        static::assertSame('_script_window.alert__x_____script_', UserPassAuthModule::sanitizeUserId('<script>window.alert("x");</script>'));
    }
}
