<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Tests;

use PHPUnit\Framework\TestCase;
use Vpn\Portal\WireGuard\Key;

/**
 * @internal
 *
 * @coversNothing
 */
final class KeyTest extends TestCase
{
    /**
     * We expect the computePublicKey function to result in the same public key
     * as the wireguard-tools "wg" command.
     */
    public function testWgCompare(): void
    {
        // wg genkey
        $secretKey = '4Hz0EbDvEgsv86ZaNaoiB7cTSCMm7/YgnfN5ZB4HE2E=';
        // echo 4Hz0EbDvEgsv86ZaNaoiB7cTSCMm7/YgnfN5ZB4HE2E= | wg pubkey
        $publicKey = 'e0oG2NgyyXli4Chu7bP5ZnFfFaG2KOqcAIIzAg2Lojk=';

        static::assertSame($publicKey, Key::publicKeyFromSecretKey($secretKey));
    }
}
