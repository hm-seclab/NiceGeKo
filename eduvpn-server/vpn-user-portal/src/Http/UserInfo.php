<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Http;

final class UserInfo
{
    private const SESSION_EXPIRY_REGEXP_LIST = [
        '|^https://eduvpn.org/expiry#(P.*)$|',
        // AARC-G027 resource capability format
        '|^urn:.*:res:eduvpn.org:session-expiry:(P.*)#.*$|',
    ];

    private bool $isAdmin = false;

    /**
     * @param array<string> $permissionList
     */
    public function __construct(private string $userId, private array $permissionList, private ?string $authData = null, private bool $isDisabled = false) {}

    public function setUserId(string $userId): void
    {
        $this->userId = $userId;
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function isAdmin(): bool
    {
        return $this->isAdmin;
    }

    public function makeAdmin(): void
    {
        $this->isAdmin = true;
    }

    /**
     * @return array<string>
     */
    public function permissionList(): array
    {
        return $this->permissionList;
    }

    /**
     * @param array<string> $requestedPermissionList
     */
    public function hasAnyPermission(array $requestedPermissionList): bool
    {
        // create a list of permissions this user has, both with the attribute
        // encoded in it, and without
        $permissionList = array_merge(
            $this->permissionList,
            array_map(
                function (string $s): string {
                    $e = explode('!', $s, 2);
                    if (2 !== \count($e)) {
                        return $s;
                    }

                    return $e[1];
                },
                $this->permissionList
            )
        );

        foreach ($requestedPermissionList as $requestedPermission) {
            if (\in_array($requestedPermission, $permissionList, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string> $permissionList
     */
    public function setPermissionList(array $permissionList): void
    {
        $this->permissionList = $permissionList;
    }

    /**
     * @param array<string> $permissionList
     */
    public function addPermissionList(array $permissionList): void
    {
        $this->permissionList = array_merge($this->permissionList, $permissionList);
    }

    public function setAuthData(string $authData): void
    {
        $this->authData = $authData;
    }

    public function authData(): ?string
    {
        return $this->authData;
    }

    public function disableUser(): void
    {
        $this->isDisabled = true;
    }

    public function isDisabled(): bool
    {
        return $this->isDisabled;
    }

    /**
     * @return array<string>
     */
    public function sessionExpiry(): array
    {
        $sessionExpiryList = [];
        foreach ($this->permissionList as $userPermission) {
            $e = explode('!', $userPermission, 2);
            if (2 === \count($e)) {
                $userPermission = $e[1];
            }
            if (null !== $sessionExpiry = self::fromRegexp($userPermission)) {
                $sessionExpiryList[] = $sessionExpiry;
            }
        }

        return $sessionExpiryList;
    }

    private static function fromRegexp(string $userPermission): ?string
    {
        foreach (self::SESSION_EXPIRY_REGEXP_LIST as $sessionExpiryRegExp) {
            if (1 === preg_match($sessionExpiryRegExp, $userPermission, $regExpMatches)) {
                return $regExpMatches[1];
            }
        }

        return null;
    }
}
