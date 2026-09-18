<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Http;

interface AuthModuleInterface
{
    public function init(ServiceInterface $service): void;

    public function userInfo(Request $request): ?UserInfo;

    public function startAuth(Request $request): ?Response;

    public function triggerLogout(Request $request): Response;
}
