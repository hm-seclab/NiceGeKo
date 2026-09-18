<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Http;

use DateTimeImmutable;
use Vpn\Portal\PermissionSourceManager;
use Vpn\Portal\Storage;

/**
 * Create a user in the users table if the user does not yet exists, or
 * update the stored user info in case the user *does* exist. Only once
 * per session.
 */
final class UpdateUserInfoHook extends AbstractHook implements HookInterface
{
    public function __construct(
        private SessionInterface $session,
        private Storage $storage,
        private AuthModuleInterface $authModule,
        private PermissionSourceManager $permissionSourceManager,
        private DateTimeImmutable $dateTime
    ) {}

    #[\Override]
    public function afterAuth(Request $request, UserInfo &$userInfo): ?Response
    {
        // only update the user info once per browser session, not on every
        // request
        if ('yes' === $this->session->get('_user_info_already_updated')) {
            if (null === $dbUserInfo = $this->storage->userInfo($userInfo->userId())) {
                // user was deleted (by admin) during the active session, so
                // we force a logout
                $this->session->destroy();

                // XXX the referrer is not necessarily set, so we have to return to the current URL
                // I do not know why this works...
                return $this->authModule->triggerLogout($request);
            }
            // use the user's information from the database so we restore all
            // the (extra) permissions we obtained during login
            $userInfo = $dbUserInfo;

            return null;
        }

        // add permissions from the permission source
        $userInfo->addPermissionList(
            permissionList: $this->permissionSourceManager->get($userInfo->userId()) ?? []
        );

        if (null === $dbUserInfo = $this->storage->userInfo($userInfo->userId())) {
            // user does not yet exist in the database, create it
            $this->storage->userAdd($userInfo, $this->dateTime);
            $this->session->set('_user_info_already_updated', 'yes');

            return null;
        }

        // if user is disabled in the database, make sure we retain this info
        if ($dbUserInfo->isDisabled()) {
            $userInfo->disableUser();
        }

        // update last seen & permissionList
        $this->storage->userUpdate($userInfo, $this->dateTime);
        $this->session->set('_user_info_already_updated', 'yes');

        return null;
    }
}
