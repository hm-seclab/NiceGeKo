<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal;

use RangeException;

final class Json
{
    private const ENCODE_FLAGS = \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES;
    private const DECODE_FLAGS = \JSON_THROW_ON_ERROR;
    private const DEPTH = 32;

    /**
     * @param array<mixed> $jsonData
     */
    public static function encode(array $jsonData): string
    {
        return json_encode($jsonData, self::ENCODE_FLAGS, self::DEPTH);
    }

    /**
     * @param array<mixed> $jsonData
     */
    public static function encodePretty(array $jsonData): string
    {
        return json_encode($jsonData, self::ENCODE_FLAGS | \JSON_PRETTY_PRINT, self::DEPTH);
    }

    /**
     * @return array<mixed>
     */
    public static function decode(string $jsonString): array
    {
        $jsonData = json_decode($jsonString, true, self::DEPTH, self::DECODE_FLAGS);
        if (!\is_array($jsonData)) {
            throw new RangeException('JSON is not an object');
        }

        return $jsonData;
    }
}
