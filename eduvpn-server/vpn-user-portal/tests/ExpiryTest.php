<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Tests;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Vpn\Portal\Expiry;
use Vpn\Portal\Http\UserInfo;

/**
 * @covers \Vpn\Portal\Expiry
 *
 * @uses \Vpn\Portal\Http\UserInfo
 * @uses \Vpn\Portal\Dt
 */
final class ExpiryTest extends TestCase
{
    public function testDefaultSessionExpiry(): void
    {
        $dataSet = [
            // expectedExpiry             dateTime                     caExpiresAt                  sessionExpiry
            ['2021-07-05T09:00:00+00:00', '2021-04-06T09:00:00+00:00', '2030-01-01T09:00:00+00:00', 'P90D'],
            ['2021-07-05T07:00:00+00:00', '2021-04-06T09:00:00+02:00', '2030-01-01T09:00:00+00:00', 'P90D'],
            ['2021-05-06T09:00:00+00:00', '2021-04-06T09:00:00+02:00', '2021-05-06T09:00:00+00:00', 'P90D'],
            ['2021-05-06T07:00:00+00:00', '2021-04-06T09:00:00+02:00', '2021-05-06T09:00:00+02:00', 'P90D'],
        ];

        foreach ($dataSet as $dataPoint) {
            $dateTime = new DateTimeImmutable($dataPoint[1]);
            $e = new Expiry(new DateInterval($dataPoint[3]), [], $dateTime, new DateTimeImmutable($dataPoint[2]));
            static::assertSame($dataPoint[0], $e->expiresAt()->setTimezone(new DateTimeZone('UTC'))->format(DateTimeImmutable::ATOM));
            static::assertSame($dataPoint[0], $dateTime->add($e->expiresIn())->setTimezone(new DateTimeZone('UTC'))->format(DateTimeImmutable::ATOM));
        }
    }

    public function testUserInfoSessionExpiry(): void
    {
        $dataSet = [
            // expectedExpiry             dateTime                     caExpiresAt                  sessionExpiry
            ['2022-04-06T09:00:00+00:00', '2021-04-06T09:00:00+00:00', '2030-01-01T09:00:00+00:00', 'P90D'],
            ['2022-04-06T07:00:00+00:00', '2021-04-06T09:00:00+02:00', '2030-01-01T09:00:00+00:00', 'P90D'],
            ['2021-05-06T09:00:00+00:00', '2021-04-06T09:00:00+02:00', '2021-05-06T09:00:00+00:00', 'P90D'],
            ['2021-05-06T07:00:00+00:00', '2021-04-06T09:00:00+02:00', '2021-05-06T09:00:00+02:00', 'P90D'],
        ];

        $userInfo = new UserInfo('foo', ['https://eduvpn.org/expiry#P1Y']);

        foreach ($dataSet as $dataPoint) {
            $dateTime = new DateTimeImmutable($dataPoint[1]);
            $e = new Expiry(new DateInterval($dataPoint[3]), ['P1Y'], $dateTime, new DateTimeImmutable($dataPoint[2]));
            static::assertSame($dataPoint[0], $e->expiresAt($userInfo)->setTimezone(new DateTimeZone('UTC'))->format(DateTimeImmutable::ATOM));
            static::assertSame($dataPoint[0], $dateTime->add($e->expiresIn($userInfo))->setTimezone(new DateTimeZone('UTC'))->format(DateTimeImmutable::ATOM));
        }
    }
}
