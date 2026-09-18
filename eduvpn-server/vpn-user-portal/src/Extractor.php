<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal;

use RangeException;

final class Extractor
{
    /**
     * @param array<mixed> $inArray
     */
    public static function optionalString(array $inArray, string $k): ?string
    {
        if (!\array_key_exists($k, $inArray)) {
            return null;
        }

        return self::requireString($inArray, $k);
    }

    /**
     * @param array<mixed> $inArray
     */
    public static function requireString(array $inArray, string $k, ?string $defaultValue = null): string
    {
        if (!\array_key_exists($k, $inArray)) {
            if (null !== $defaultValue) {
                return $defaultValue;
            }

            throw new RangeException('key "' . $k . '" not available');
        }
        if (!\is_string($inArray[$k])) {
            throw new RangeException('key "' . $k . '" not of type `string`');
        }

        return $inArray[$k];
    }

    /**
     * @param array<mixed> $inArray
     */
    public static function requireStringOrNull(array $inArray, string $k): ?string
    {
        if (!\array_key_exists($k, $inArray)) {
            throw new RangeException('key "' . $k . '" not available');
        }
        if (null !== $inArray[$k] && !\is_string($inArray[$k])) {
            throw new RangeException('key "' . $k . '" not of type `null|string`');
        }

        return $inArray[$k];
    }

    /**
     * @param array<mixed> $inArray
     */
    public static function optionalStringOrNull(array $inArray, string $k): ?string
    {
        if (!\array_key_exists($k, $inArray)) {
            return null;
        }

        return self::requireStringOrNull($inArray, $k);
    }

    /**
     * @param array<mixed> $inArray
     * @param ?array<array<mixed>> $defaultValue
     *
     * @return array<array<mixed>>
     */
    public static function requireArrayArray(array $inArray, string $k, ?array $defaultValue = null): array
    {
        if (!\array_key_exists($k, $inArray)) {
            if (null !== $defaultValue) {
                return $defaultValue;
            }

            throw new RangeException('key "' . $k . '" not available');
        }
        if (!\is_array($inArray[$k])) {
            throw new RangeException('key "' . $k . '" not of type `array<array>`');
        }
        $outArray = [];
        foreach ($inArray[$k] as $inItem) {
            if (!\is_array($inItem)) {
                throw new RangeException('key "' . $k . '" not of type `array<array>`');
            }
            $outArray[] = $inItem;
        }

        return $outArray;
    }

    /**
     * @param array<mixed> $inArray
     * @param ?array<mixed> $defaultValue
     *
     * @return array<mixed>
     */
    public static function requireArray(array $inArray, string $k, ?array $defaultValue = null): array
    {
        if (!\array_key_exists($k, $inArray)) {
            if (null !== $defaultValue) {
                return $defaultValue;
            }

            throw new RangeException('key "' . $k . '" not available');
        }
        if (!\is_array($inArray[$k])) {
            throw new RangeException('key "' . $k . '" not of type `array`');
        }

        return $inArray[$k];
    }

    /**
     * @param array<mixed> $inArray
     *
     * @return array<string>
     */
    public static function optionalStringArray(array $inArray, string $k): ?array
    {
        if (!\array_key_exists($k, $inArray)) {
            return null;
        }

        return self::requireStringArray($inArray, $k);
    }

    /**
     * @param array<mixed> $inArray
     * @param ?array<string> $defaultValue
     *
     * @return array<string>
     */
    public static function requireStringArray(array $inArray, string $k, ?array $defaultValue = null): array
    {
        if (!\array_key_exists($k, $inArray)) {
            if (null !== $defaultValue) {
                return $defaultValue;
            }

            throw new RangeException('key "' . $k . '" not available');
        }
        if (!\is_array($inArray[$k])) {
            throw new RangeException('key "' . $k . '" not of type `array<string>`');
        }
        $outArray = [];
        foreach ($inArray[$k] as $inItem) {
            if (!\is_string($inItem)) {
                throw new RangeException('key "' . $k . '" not of type `array<string>`');
            }
            $outArray[] = $inItem;
        }

        return $outArray;
    }

    /**
     * @param array<mixed> $inArray
     */
    public static function requireBool(array $inArray, string $k, ?bool $defaultValue = null): bool
    {
        if (!\array_key_exists($k, $inArray)) {
            if (null !== $defaultValue) {
                return $defaultValue;
            }

            throw new RangeException('key "' . $k . '" not available');
        }
        if (!\is_bool($inArray[$k])) {
            throw new RangeException('key "' . $k . '" not of type `bool`');
        }

        return $inArray[$k];
    }

    /**
     * @param array<mixed> $inArray
     */
    public static function optionalInt(array $inArray, string $k): ?int
    {
        if (!\array_key_exists($k, $inArray)) {
            return null;
        }

        return self::requireInt($inArray, $k);
    }

    /**
     * @param array<mixed> $inArray
     */
    public static function requireInt(array $inArray, string $k, ?int $defaultValue = null): int
    {
        if (!\array_key_exists($k, $inArray)) {
            if (null !== $defaultValue) {
                return $defaultValue;
            }

            throw new RangeException('key "' . $k . '" not available');
        }
        if (!\is_int($inArray[$k])) {
            throw new RangeException('key "' . $k . '" not of type `int`');
        }

        return $inArray[$k];
    }

    /**
     * @param array<mixed> $inArray
     *
     * @return ?array<string>
     */
    public static function optionalStringOrStringArray(array $inArray, string $k): ?array
    {
        if (!\array_key_exists($k, $inArray)) {
            return null;
        }

        return self::requireStringOrStringArray($inArray, $k);
    }

    /**
     * @param array<mixed> $inArray
     * @param array<string> $defaultValue
     *
     * @return array<string>
     */
    public static function requireStringOrStringArray(array $inArray, string $k, ?array $defaultValue = null): array
    {
        if (!\array_key_exists($k, $inArray)) {
            if (null !== $defaultValue) {
                return $defaultValue;
            }

            throw new RangeException('key "' . $k . '" not available');
        }

        if (\is_string($inArray[$k])) {
            return [$inArray[$k]];
        }

        return self::requireStringArray($inArray, $k);
    }

    /**
     * @param array<mixed> $inArray
     * @param ?array<int> $defaultValue
     *
     * @return array<int>
     */
    public static function requireIntArray(array $inArray, string $k, ?array $defaultValue = null): array
    {
        if (!\array_key_exists($k, $inArray)) {
            if (null !== $defaultValue) {
                return $defaultValue;
            }

            throw new RangeException('key "' . $k . '" not available');
        }
        if (!\is_array($inArray[$k])) {
            throw new RangeException('key "' . $k . '" not of type `array<int>`');
        }
        $outArray = [];
        foreach ($inArray[$k] as $inItem) {
            if (!\is_int($inItem)) {
                throw new RangeException('key "' . $k . '" not of type `array<int>`');
            }
            $outArray[] = $inItem;
        }

        return $outArray;
    }

    /**
     * @param array<mixed> $inArray
     * @param ?array<float> $defaultValue
     *
     * @return array<float>
     */
    public static function requireFloatArray(array $inArray, string $k, ?array $defaultValue = null): array
    {
        if (!\array_key_exists($k, $inArray)) {
            if (null !== $defaultValue) {
                return $defaultValue;
            }

            throw new RangeException('key "' . $k . '" not available');
        }
        if (!\is_array($inArray[$k])) {
            throw new RangeException('key "' . $k . '" not of type `array<float>`');
        }
        $outArray = [];
        foreach ($inArray[$k] as $inItem) {
            // int is a special kind of float :)
            if (!\is_float($inItem) && !\is_int($inItem)) {
                throw new RangeException('key "' . $k . '" not of type `array<float>`');
            }
            $outArray[] = (float) $inItem;
        }

        return $outArray;
    }

    /**
     * @param array<mixed> $inArray
     * @param array<int> $defaultValue
     *
     * @return array<int>
     */
    public static function requireIntOrIntArray(array $inArray, string $k, ?array $defaultValue = null): array
    {
        if (!\array_key_exists($k, $inArray)) {
            if (null !== $defaultValue) {
                return $defaultValue;
            }

            throw new RangeException('key "' . $k . '" not available');
        }

        if (\is_int($inArray[$k])) {
            return [$inArray[$k]];
        }

        return self::requireIntArray($inArray, $k);
    }
}
