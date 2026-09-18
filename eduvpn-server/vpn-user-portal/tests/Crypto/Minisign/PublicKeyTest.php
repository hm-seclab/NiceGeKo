<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Tests;

use PHPUnit\Framework\TestCase;
use Vpn\Portal\Crypto\Minisign\MinisignException;
use Vpn\Portal\Crypto\Minisign\PublicKey;

/**
 * @covers \Vpn\Portal\Crypto\Minisign\PublicKey
 *
 * @uses \Vpn\Portal\FileIO
 * @uses \Vpn\Portal\Base64
 */
final class PublicKeyTest extends TestCase
{
    public function testPublicKeyFromFile(): void
    {
        $p = PublicKey::fromFile(__DIR__ . '/minisign.pub');
        static::assertSame('d5820960685b3d2e', bin2hex($p->keyId));
        static::assertSame('cf205468ed0f7b4f480b4b42639268c9e6c49f63d5bdc1b1fc6345869ee5bfd6', bin2hex($p->rawPublicKey));
    }

    public function testPublicKeyFromWrongFile(): void
    {
        $this->expectException(MinisignException::class);
        $this->expectExceptionMessage('public key has invalid length');
        $p = PublicKey::fromFile(__FILE__);
    }
}
