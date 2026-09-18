<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Tests;

use PHPUnit\Framework\TestCase;
use RangeException;
use Vpn\Portal\Extractor;

/**
 * @covers \Vpn\Portal\Extractor
 */
final class ExtractorTest extends TestCase
{
    public function testRequireString(): void
    {
        static::assertSame('bar', Extractor::requireString(['foo' => 'bar'], 'foo'));
    }

    public function testOptionalString(): void
    {
        static::assertSame('bar', Extractor::optionalString(['foo' => 'bar'], 'foo'));
        static::assertNull(Extractor::optionalString(['foo' => 'bar'], 'foobar'));
    }

    public function testRequireStringWrongType(): void
    {
        $this->expectException(RangeException::class);
        $this->expectExceptionMessage('key "foo" not of type `string`');
        Extractor::optionalString(['foo' => 5], 'foo');
    }

    public function testOptionalStringWrongType(): void
    {
        $this->expectException(RangeException::class);
        $this->expectExceptionMessage('key "foo" not of type `string`');
        Extractor::optionalString(['foo' => 5], 'foo');
    }

    public function testRequireStringArray(): void
    {
        static::assertSame(['a', 'b'], Extractor::requireStringArray(['foo' => ['a', 'b']], 'foo'));
    }

    public function testRequireInt(): void
    {
        static::assertSame(5, Extractor::requireInt(['foo' => 5], 'foo'));
    }

    public function testRequireBool(): void
    {
        static::assertSame(true, Extractor::requireBool(['foo' => true], 'foo'));
    }

    public function testRequireStringOrStringArray(): void
    {
        static::assertSame(['bar'], Extractor::requireStringOrStringArray(['foo' => 'bar'], 'foo'));
        static::assertSame(['bar'], Extractor::requireStringOrStringArray(['foo' => ['bar']], 'foo'));
    }

    public function testRequireIntOrIntArray(): void
    {
        static::assertSame([5], Extractor::requireIntOrIntArray(['foo' => 5], 'foo'));
        static::assertSame([5], Extractor::requireIntOrIntArray(['foo' => [5]], 'foo'));
    }

    public function testRequireFloatArray(): void
    {
        static::assertSame([1.23], Extractor::requireFloatArray(['foo' => [1.23]], 'foo'));
        static::assertSame([1.23, 0.0, 0.01], Extractor::requireFloatArray(['foo' => [1.23, 0, 0.01]], 'foo'));
    }

    public function testRequireStringOrNull(): void
    {
        static::assertNull(Extractor::requireStringOrNull(['foo' => null], 'foo'));
        static::assertSame('bar', Extractor::requireStringOrNull(['foo' => 'bar'], 'foo'));
    }

    public function testRequireStringOrNullWrongType(): void
    {
        $this->expectException(RangeException::class);
        $this->expectExceptionMessage('key "foo" not of type `null|string`');
        Extractor::requireStringOrNull(['foo' => 5], 'foo');
    }

    public function testRequireStringOrNullNotThere(): void
    {
        $this->expectException(RangeException::class);
        $this->expectExceptionMessage('key "foo" not available');
        Extractor::requireStringOrNull([], 'foo');
    }
}
