<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Crypto;

use Vpn\Portal\Base64UrlSafe;

final class Hmac
{
    public static function generate(string $m, HmacKey $hmacKey): string
    {
        return Base64UrlSafe::encodeUnpadded(
            hash_hmac('sha256', $m, $hmacKey->raw(), true)
        );
    }
}
