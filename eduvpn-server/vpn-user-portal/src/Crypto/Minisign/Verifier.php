<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Crypto\Minisign;

/**
 * Validate Signify/Minisign signatures with the "legacy" format, i.e. not
 * hashed first.
 *
 * @see https://jedisct1.github.io/minisign/
 */
final class Verifier
{
    public function __construct(
        /** @var array<PublicKey> */
        private array $publicKeys
    ) {}

    /**
     * Verify a detached signature.
     */
    public function verifyDetached(string $plainText, Signature $signature): bool
    {
        // when/if implementing "hashed" version, we need
        // sodium_crypto_generichash($plainText, '', 64)
        foreach ($this->publicKeys as $publicKey) {
            if ($signature->keyId === $publicKey->keyId) {
                return sodium_crypto_sign_verify_detached(
                    $signature->rawSignature,
                    $plainText,
                    $publicKey->rawPublicKey
                );
            }
        }

        return false;
    }
}
