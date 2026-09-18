<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Tests;

use PHPUnit\Framework\TestCase;
use Vpn\Portal\Crypto\Minisign\MinisignException;
use Vpn\Portal\Crypto\Minisign\PublicKey;
use Vpn\Portal\Crypto\Minisign\Signature;
use Vpn\Portal\Crypto\Minisign\Verifier;
use Vpn\Portal\FileIO;

/**
 * @covers \Vpn\Portal\Crypto\Minisign\Verifier
 *
 * @uses \Vpn\Portal\Base64
 * @uses \Vpn\Portal\Crypto\Minisign\PublicKey
 * @uses \Vpn\Portal\Crypto\Minisign\Signature
 * @uses \Vpn\Portal\FileIO
 */
final class VerifierTest extends TestCase
{
    public function testVerify(): void
    {
        $signatureVerifier = new Verifier(
            [
                PublicKey::fromFile(__DIR__ . '/minisign.pub'),
            ]
        );

        static::assertTrue(
            $signatureVerifier->verifyDetached(
                FileIO::read(__DIR__ . '/minisign.pub'),
                Signature::fromFile(__DIR__ . '/minisign.pub.minisig')
            )
        );
    }

    public function testVerifyWrongAlgo(): void
    {
        $signatureVerifier = new Verifier(
            [
                PublicKey::fromFile(__DIR__ . '/minisign.pub'),
            ]
        );

        $this->expectException(MinisignException::class);
        $this->expectExceptionMessage('signature has invalid algorithm');
        $signatureVerifier->verifyDetached(
            FileIO::read(__DIR__ . '/minisign.pub'),
            Signature::fromFile(__DIR__ . '/minisign.pub.minihsig')
        );
    }
}
