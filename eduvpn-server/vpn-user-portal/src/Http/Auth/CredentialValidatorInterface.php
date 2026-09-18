<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Http\Auth;

use Vpn\Portal\Http\UserInfo;

interface CredentialValidatorInterface
{
    /**
     * @throws \Vpn\Portal\Http\Auth\Exception\CredentialValidatorException
     */
    public function validate(string $authUser, string $authPass): UserInfo;
}
