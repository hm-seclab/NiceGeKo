<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Tests;

use DateInterval;
use DateTimeImmutable;
use fkooman\OAuth\Server\AccessToken;
use fkooman\OAuth\Server\Http\Request;
use fkooman\OAuth\Server\Scope;
use fkooman\OAuth\Server\ValidatorInterface;

final class TestValidator implements ValidatorInterface
{
    #[\Override]
    public function validate(?Request $request = null): AccessToken
    {
        $dateTime = new DateTimeImmutable('2022-01-01T09:00:00+00:00');
        $expiresAt = $dateTime->add(new DateInterval('PT1H'));
        $authorizationExpiresAt = $dateTime->add(new DateInterval('P90D'));

        return new AccessToken(
            'token_id',
            'auth_key',
            'user_id',
            'client_id',
            new Scope('config'),
            $expiresAt,
            $authorizationExpiresAt,
            'raw_token'
        );
    }
}
