<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Crypto\Minisign;

use Vpn\Portal\Base64;
use Vpn\Portal\FileIO;

/**
 * @see https://jedisct1.github.io/minisign/#public-key-format
 */
final class PublicKey
{
    private const PUBLIC_KEY_LENGTH = 32;
    private const KEY_ID_LENGTH = 8;
    private const KEY_ALGO_LENGTH = 2;

    private function __construct(
        public string $keyId,
        /** @var non-empty-string */
        public string $rawPublicKey
    ) {}

    public static function fromEncodedString(string $fromEncodedString): self
    {
        // 00000000  45 64 d5 82 09 60 68 5b  3d 2e cf 20 54 68 ed 0f  |Ed?..`h[=.? Th?.|
        // 00000010  7b 4f 48 0b 4b 42 63 92  68 c9 e6 c4 9f 63 d5 bd  |{OH.KBc.h???.cս|
        // 00000020  c1 b1 fc 63 45 86 9e e5  bf d6                    |???cE..??|
        // 0000002a
        $publicKey = Base64::decode($fromEncodedString);
        if (self::KEY_ALGO_LENGTH + self::KEY_ID_LENGTH + self::PUBLIC_KEY_LENGTH !== \strlen($publicKey)) {
            throw new MinisignException('public key has invalid length');
        }
        if ('Ed' !== substr($publicKey, 0, self::KEY_ALGO_LENGTH)) {
            throw new MinisignException('public key has invalid algorithm');
        }

        if ('' === $rawPublicKey = substr($publicKey, self::KEY_ALGO_LENGTH + self::KEY_ID_LENGTH)) {
            throw new MinisignException();
        }

        return new self(
            keyId: substr($publicKey, self::KEY_ALGO_LENGTH, self::KEY_ID_LENGTH),
            rawPublicKey: $rawPublicKey
        );
    }

    public static function fromString(string $fromString): self
    {
        // untrusted comment: minisign public key 2E3D5B68600982D5
        // RWTVgglgaFs9Ls8gVGjtD3tPSAtLQmOSaMnmxJ9j1b3BsfxjRYae5b/W
        $e = explode("\n", $fromString);
        if (!\array_key_exists(1, $e)) {
            throw new MinisignException('invalid public key file');
        }

        return self::fromEncodedString(fromEncodedString: trim($e[1]));
    }

    public static function fromFile(string $fromFile): self
    {
        return self::fromString(fromString: FileIO::read($fromFile));
    }
}
