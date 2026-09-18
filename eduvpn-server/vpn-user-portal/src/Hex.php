<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal;

/**
 * Wrapper class around sodium hex encoding/decoding functions using the
 * paragonie/constant_time_encoding API.
 */
final class Hex
{
    public static function encode(string $string): string
    {
        return sodium_bin2hex($string);
    }

    public static function decode(string $string): string
    {
        return sodium_hex2bin($string);
    }
}
