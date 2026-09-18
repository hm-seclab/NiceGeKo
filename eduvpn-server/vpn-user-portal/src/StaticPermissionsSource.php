<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal;

use Vpn\Portal\Cfg\StaticPermissionsConfig;
use Vpn\Portal\Http\Request;

final class StaticPermissionsSource implements PermissionSourceInterface
{
    public function __construct(private StaticPermissionsConfig $staticPermissionsConfig, private Request $httpRequest) {}

    /**
     * Get current permissions for users directly from the source.
     *
     * If no permissions are available, or the user no longer exists, an empty
     * array is returned.
     *
     * @return array<string>
     */
    #[\Override]
    public function permissionsForUser(string $userId): array
    {
        $permissionsFile = $this->staticPermissionsConfig->permissionsFile();
        if (!FileIO::exists($permissionsFile)) {
            return [];
        }

        $permissionData = Json::decode(FileIO::read($permissionsFile));
        $permissionList = [];
        foreach ($permissionData as $permissionId => $userIdList) {
            if (!\is_string($permissionId)) {
                continue;
            }
            if (!\is_array($userIdList)) {
                continue;
            }
            if (!str_contains($permissionId, '!')) {
                $permissionId = \sprintf('%s!%s', $this->staticPermissionsConfig->defaultAttributeName(), $permissionId);
            }
            if (\in_array($userId, $userIdList, true)) {
                $permissionList[] = $permissionId;

                continue;
            }
            // check whether the permissions are IP prefixes that also result
            // in permissions
            $remoteAddr = Ip::fromIp($this->httpRequest->requireHeader('REMOTE_ADDR'));
            foreach ($userIdList as $ipPrefix) {
                if (!\is_string($ipPrefix)) {
                    continue;
                }
                if (!str_starts_with($ipPrefix, 'IP@')) {
                    continue;
                }
                $permissionAddr = Ip::fromIpPrefix(substr($ipPrefix, 3));
                if ($permissionAddr->contains($remoteAddr)) {
                    $permissionList[] = $permissionId;
                }
            }
        }

        return $permissionList;
    }
}
