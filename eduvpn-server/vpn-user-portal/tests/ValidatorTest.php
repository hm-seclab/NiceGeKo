<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Tests;

use PHPUnit\Framework\TestCase;
use RangeException;
use Vpn\Portal\Validator;

/**
 * @internal
 *
 * @coversNothing
 */
final class ValidatorTest extends TestCase
{
    public function testInvalidPublicKey(): void
    {
        $this->expectException(RangeException::class);
        Validator::publicKey('EtPxdnGP+KCS7tVBohNgt5lcXF7XubTDKr6QdwuyGU=');
    }

    public function testDisplayNameNonUtf(): void
    {
        $this->expectException(RangeException::class);
        // utf-16 encoding of "€"
        Validator::displayName("\x20\xac");
    }

    public function testDisplayNameTooShort(): void
    {
        $this->expectException(RangeException::class);
        Validator::displayName('');
    }

    public function testDisplayNameTooLong(): void
    {
        $this->expectException(RangeException::class);
        Validator::displayName(str_repeat('€', 65));
    }

    public function testDateInterval(): void
    {
        $this->expectException(RangeException::class);
        // invalid DateInterval MUST throw exception
        Validator::dateInterval('X');
    }
}
