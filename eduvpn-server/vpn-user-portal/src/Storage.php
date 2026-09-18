<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal;

use DateInterval;
use DateTimeImmutable;
use PDO;
use Vpn\Portal\Cfg\DbConfig;
use Vpn\Portal\Exception\StorageException;
use Vpn\Portal\Http\UserInfo;

final class Storage
{
    public const CURRENT_SCHEMA_VERSION = '2024052801';

    private PDO $db;

    public function __construct(DbConfig $dbConfig, bool $sqliteAutoInitMigrate = true)
    {
        $db = new PDO(
            $dbConfig->dbDsn(),
            $dbConfig->dbUser(),
            $dbConfig->dbPass(),
            $dbConfig->dbOptions()
        );
        // in PHP < 8.1 the ATTR_STRINGIFY_FETCHES attribute was always true,
        // but changed to a default of false in 8.1. Setting this option
        // restores the pre-8.1 behavior. We may switch it to false at some
        // point, but this requires proper testing...
        // @see https://www.php.net/manual/en/migration81.incompatible.php
        $db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);

        if ('sqlite' === $db->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            $db->exec('PRAGMA foreign_keys = ON');

            if ($sqliteAutoInitMigrate) {
                // run database initialization/migration if necessary
                Migration::run(
                    db: $db,
                    schemaDir: $dbConfig->schemaDir(),
                    schemaVersion: self::CURRENT_SCHEMA_VERSION,
                    autoInit: $sqliteAutoInitMigrate,
                    autoMigrate: $sqliteAutoInitMigrate
                );
            }
        }
        $this->db = $db;
    }

    public function driverName(): string
    {
        $driverName = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!\is_string($driverName)) {
            throw new StorageException('unable to determine PDO driver');
        }

        return $driverName;
    }

    public function dbPdo(): PDO
    {
        return $this->db;
    }

    public function wPeerAdd(string $userId, int $nodeNumber, string $profileId, string $displayName, string $publicKey, string $ipFour, string $ipSix, DateTimeImmutable $createdAt, DateTimeImmutable $expiresAt, ?string $authKey): void
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                INSERT
                INTO
                    wg_peers (
                        user_id,
                        node_number,
                        profile_id,
                        display_name,
                        public_key,
                        ip_four,
                        ip_six,
                        created_at,
                        expires_at,
                        auth_key
                    )
                    VALUES(
                        :user_id,
                        :node_number,
                        :profile_id,
                        :display_name,
                        :public_key,
                        :ip_four,
                        :ip_six,
                        :created_at,
                        :expires_at,
                        :auth_key
                    )
                SQL
        );

        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $stmt->bindValue(':node_number', $nodeNumber, PDO::PARAM_INT);
        $stmt->bindValue(':profile_id', $profileId, PDO::PARAM_STR);
        $stmt->bindValue(':display_name', $displayName, PDO::PARAM_STR);
        $stmt->bindValue(':public_key', $publicKey, PDO::PARAM_STR);
        $stmt->bindValue(':ip_four', $ipFour, PDO::PARAM_STR);
        $stmt->bindValue(':ip_six', $ipSix, PDO::PARAM_STR);
        $stmt->bindValue(':created_at', $createdAt->format(DateTimeImmutable::ATOM), PDO::PARAM_STR);
        $stmt->bindValue(':expires_at', $expiresAt->format(DateTimeImmutable::ATOM), PDO::PARAM_STR);
        $stmt->bindValue(':auth_key', $authKey, PDO::PARAM_STR | PDO::PARAM_NULL);
        $stmt->execute();
    }

    /**
     * @return ?array{user_id:string,profile_id:string,node_number:int,ip_four:string,ip_six:string}
     */
    public function wPeerInfo(string $publicKey): ?array
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                SELECT
                    user_id,
                    profile_id,
                    node_number,
                    ip_four,
                    ip_six
                FROM
                    wg_peers
                WHERE
                    public_key = :public_key
                SQL
        );
        $stmt->bindValue(':public_key', $publicKey, PDO::PARAM_STR);
        $stmt->execute();

        $resultRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!\is_array($resultRow)) {
            return null;
        }

        return [
            'user_id' => self::requireString($resultRow, 'user_id'),
            'profile_id' => self::requireString($resultRow, 'profile_id'),
            'node_number' => self::requireInt($resultRow, 'node_number'),
            'ip_four' => self::requireString($resultRow, 'ip_four'),
            'ip_six' => self::requireString($resultRow, 'ip_six'),
        ];
    }

    public function wPeerRemove(string $publicKey): void
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                DELETE
                FROM
                    wg_peers
                WHERE
                    public_key = :public_key
                SQL
        );
        $stmt->bindValue(':public_key', $publicKey, PDO::PARAM_STR);
        $stmt->execute();
    }

    public function wPeerSetExpiresAt(string $publicKey, DateTimeImmutable $expiresAt): void
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                UPDATE
                    wg_peers
                SET
                    expires_at = :expires_at
                WHERE
                    public_key = :public_key
                SQL
        );
        $stmt->bindValue(':public_key', $publicKey, PDO::PARAM_STR);
        $stmt->bindValue(':expires_at', $expiresAt->format(DateTimeImmutable::ATOM), PDO::PARAM_STR);
        $stmt->execute();
    }

    /**
     * Get a list of IPv4 addresses already in use by a specific node belonging
     * to a profile.
     *
     * @return array<string>
     */
    public function wAllocatedIpFourList(string $profileId, int $nodeNumber): array
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                SELECT
                    ip_four
                FROM
                    wg_peers
                WHERE
                    profile_id = :profile_id
                AND
                    node_number = :node_number
                SQL
        );
        $stmt->bindValue(':profile_id', $profileId, PDO::PARAM_STR);
        $stmt->bindValue(':node_number', $nodeNumber, PDO::PARAM_INT);
        $stmt->execute();

        $wAllocatedIpFourList = [];
        foreach (self::requireArrayOfArray($stmt->fetchAll(PDO::FETCH_ASSOC)) as $resultRow) {
            $wAllocatedIpFourList[] = self::requireString($resultRow, 'ip_four');
        }

        return $wAllocatedIpFourList;
    }

    /**
     * @return array<array{profile_id:string,node_number:int,display_name:string,public_key:string,ip_four:string,ip_six:string,expires_at:\DateTimeImmutable,auth_key:?string}>
     */
    public function wPeerInfoListByUserId(string $userId): array
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                SELECT
                    profile_id,
                    node_number,
                    display_name,
                    public_key,
                    ip_four,
                    ip_six,
                    expires_at,
                    auth_key
                FROM
                    wg_peers
                WHERE
                    user_id = :user_id
                ORDER BY
                    expires_at DESC
                SQL
        );

        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $stmt->execute();

        $wPeerInfoList = [];
        foreach (self::requireArrayOfArray($stmt->fetchAll(PDO::FETCH_ASSOC)) as $resultRow) {
            $wPeerInfoList[] = [
                'profile_id' => self::requireString($resultRow, 'profile_id'),
                'node_number' => self::requireInt($resultRow, 'node_number'),
                'display_name' => self::requireString($resultRow, 'display_name'),
                'public_key' => self::requireString($resultRow, 'public_key'),
                'ip_four' => self::requireString($resultRow, 'ip_four'),
                'ip_six' => self::requireString($resultRow, 'ip_six'),
                'expires_at' => new DateTimeImmutable(self::requireString($resultRow, 'expires_at')),
                'auth_key' => self::requireStringOrNull($resultRow, 'auth_key'),
            ];
        }

        return $wPeerInfoList;
    }

    /**
     * @return array<array{user_id:string,profile_id:string,node_number:int,public_key:string,ip_four:string,ip_six:string}>
     */
    public function wPeerInfoListByAuthKey(string $authKey): array
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                SELECT
                    user_id,
                    profile_id,
                    node_number,
                    public_key,
                    ip_four,
                    ip_six
                FROM
                    wg_peers
                WHERE
                    auth_key = :auth_key
                SQL
        );
        $stmt->bindValue(':auth_key', $authKey, PDO::PARAM_STR);
        $stmt->execute();

        $wPeerInfoList = [];
        foreach (self::requireArrayOfArray($stmt->fetchAll(PDO::FETCH_ASSOC)) as $resultRow) {
            $wPeerInfoList[] = [
                'user_id' => self::requireString($resultRow, 'user_id'),
                'profile_id' => self::requireString($resultRow, 'profile_id'),
                'node_number' => self::requireInt($resultRow, 'node_number'),
                'public_key' => self::requireString($resultRow, 'public_key'),
                'ip_four' => self::requireString($resultRow, 'ip_four'),
                'ip_six' => self::requireString($resultRow, 'ip_six'),
            ];
        }

        return $wPeerInfoList;
    }

    /**
     * @return array<string>
     */
    public function localUserList(): array
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                SELECT
                    user_id
                FROM
                    local_users
                SQL
        );

        $stmt->execute();
        $localUserList = [];
        foreach (self::requireArrayOfArray($stmt->fetchAll(PDO::FETCH_ASSOC)) as $resultRow) {
            $localUserList[] = self::requireString($resultRow, 'user_id');
        }

        return $localUserList;
    }

    public function localUserExists(string $authUser): bool
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                SELECT
                    COUNT(user_id) AS c
                FROM
                    local_users
                WHERE
                    user_id = :user_id
                SQL
        );

        $stmt->bindValue(':user_id', $authUser, PDO::PARAM_STR);
        $stmt->execute();

        $resultRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!\is_array($resultRow)) {
            return false;
        }

        return 1 === self::requireInt($resultRow, 'c');
    }

    public function localUserAdd(string $userId, string $passwordHash, DateTimeImmutable $createdAt): void
    {
        if ($this->localUserExists($userId)) {
            $this->localUserUpdatePassword($userId, $passwordHash);

            return;
        }

        $stmt = $this->db->prepare(
            <<< 'SQL'
                INSERT
                INTO
                    local_users (
                        user_id,
                        password_hash,
                        created_at
                    )
                    VALUES (
                        :user_id,
                        :password_hash,
                        :created_at
                    )
                SQL
        );

        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $stmt->bindValue(':password_hash', $passwordHash, PDO::PARAM_STR);
        $stmt->bindValue(':created_at', $createdAt->format(DateTimeImmutable::ATOM), PDO::PARAM_STR);
        $stmt->execute();
    }

    public function localUserDelete(string $userId): void
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                    DELETE FROM
                        local_users
                    WHERE
                        user_id = :user_id
                SQL
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $stmt->execute();
    }

    public function localUserUpdatePassword(string $userId, string $passwordHash): void
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                UPDATE
                    local_users
                SET
                    password_hash = :password_hash
                WHERE
                    user_id = :user_id
                SQL
        );

        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $stmt->bindValue(':password_hash', $passwordHash, PDO::PARAM_STR);
        $stmt->execute();
    }

    public function localUserPasswordHash(string $authUser): ?string
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                SELECT
                    password_hash
                FROM
                    local_users
                WHERE
                    user_id = :user_id
                SQL
        );

        $stmt->bindValue(':user_id', $authUser, PDO::PARAM_STR);
        $stmt->execute();

        $resultRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!\is_array($resultRow)) {
            return null;
        }

        return self::requireString($resultRow, 'password_hash');
    }

    public function userAdd(UserInfo $userInfo, DateTimeImmutable $lastSeen): void
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                    INSERT INTO
                        users (
                            user_id,
                            last_seen,
                            permission_list,
                            auth_data,
                            is_disabled
                        )
                    VALUES (
                        :user_id,
                        :last_seen,
                        :permission_list,
                        :auth_data,
                        :is_disabled
                    )
                SQL
        );
        $stmt->bindValue(':user_id', $userInfo->userId(), PDO::PARAM_STR);
        $stmt->bindValue(':last_seen', $lastSeen->format(DateTimeImmutable::ATOM), PDO::PARAM_STR);
        $stmt->bindValue(':permission_list', self::permissionListToString($userInfo->permissionList()), PDO::PARAM_STR);
        $stmt->bindValue(':auth_data', $userInfo->authData(), PDO::PARAM_STR | PDO::PARAM_NULL);
        $stmt->bindValue(':is_disabled', $userInfo->isDisabled(), PDO::PARAM_BOOL);
        $stmt->execute();
    }

    /**
     * @return array<\Vpn\Portal\Http\UserInfo>
     */
    public function userList(): array
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                    SELECT
                        user_id,
                        permission_list,
                        auth_data,
                        is_disabled
                    FROM
                        users
                    ORDER BY
                        user_id
                SQL
        );
        $stmt->execute();

        $userList = [];
        foreach (self::requireArrayOfArray($stmt->fetchAll(PDO::FETCH_ASSOC)) as $resultRow) {
            $userList[] = new UserInfo(
                self::requireString($resultRow, 'user_id'),
                self::stringToPermissionList(self::requireString($resultRow, 'permission_list')),
                self::requireStringOrNull($resultRow, 'auth_data'),
                self::requireBool($resultRow, 'is_disabled')
            );
        }

        return $userList;
    }

    /**
     * Return only users that were last seen before the provided "last seen".
     *
     * @return array<\Vpn\Portal\Http\UserInfo>
     */
    public function userListLastSeenBefore(DateTimeImmutable $lastSeen): array
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                    SELECT
                        user_id,
                        permission_list,
                        auth_data,
                        is_disabled
                    FROM
                        users
                    WHERE
                        last_seen < :last_seen
                    ORDER BY
                        user_id
                SQL
        );
        $stmt->bindValue(':last_seen', $lastSeen->format(DateTimeImmutable::ATOM), PDO::PARAM_STR);
        $stmt->execute();

        $userList = [];
        foreach (self::requireArrayOfArray($stmt->fetchAll(PDO::FETCH_ASSOC)) as $resultRow) {
            $userList[] = new UserInfo(
                self::requireString($resultRow, 'user_id'),
                self::stringToPermissionList(self::requireString($resultRow, 'permission_list')),
                self::requireStringOrNull($resultRow, 'auth_data'),
                self::requireBool($resultRow, 'is_disabled')
            );
        }

        return $userList;
    }

    public function userInfo(string $userId): ?UserInfo
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                    SELECT
                        user_id,
                        permission_list,
                        auth_data,
                        is_disabled
                    FROM
                        users
                    WHERE
                        user_id = :user_id
                SQL
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $stmt->execute();

        $resultRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!\is_array($resultRow)) {
            // user does not exist
            return null;
        }

        return new UserInfo(
            self::requireString($resultRow, 'user_id'),
            self::stringToPermissionList(self::requireString($resultRow, 'permission_list')),
            self::requireStringOrNull($resultRow, 'auth_data'),
            self::requireBool($resultRow, 'is_disabled')
        );
    }

    public function userDelete(string $userId): void
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                    DELETE FROM
                        users
                    WHERE
                        user_id = :user_id
                SQL
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $stmt->execute();
    }

    public function userUpdate(UserInfo $userInfo, DateTimeImmutable $lastSeen): void
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                    UPDATE
                        users
                    SET
                        last_seen = :last_seen,
                        permission_list = :permission_list,
                        auth_data = :auth_data
                    WHERE
                        user_id = :user_id
                SQL
        );
        $stmt->bindValue(':user_id', $userInfo->userId(), PDO::PARAM_STR);
        $stmt->bindValue(':last_seen', $lastSeen->format(DateTimeImmutable::ATOM), PDO::PARAM_STR);
        $stmt->bindValue(':permission_list', self::permissionListToString($userInfo->permissionList()), PDO::PARAM_STR);
        $stmt->bindValue(':auth_data', $userInfo->authData(), PDO::PARAM_STR | PDO::PARAM_NULL);

        $stmt->execute();
    }

    public function oCertAdd(string $userId, int $nodeNumber, string $profileId, string $commonName, string $displayName, DateTimeImmutable $createdAt, DateTimeImmutable $expiresAt, ?string $authKey): void
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                    INSERT INTO certificates
                        (node_number, profile_id, common_name, user_id, display_name, created_at, expires_at, auth_key)
                    VALUES
                        (:node_number, :profile_id, :common_name, :user_id, :display_name, :created_at, :expires_at, :auth_key)
                SQL
        );
        $stmt->bindValue(':node_number', $nodeNumber, PDO::PARAM_INT);
        $stmt->bindValue(':profile_id', $profileId, PDO::PARAM_STR);
        $stmt->bindValue(':common_name', $commonName, PDO::PARAM_STR);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $stmt->bindValue(':display_name', $displayName, PDO::PARAM_STR);
        $stmt->bindValue(':created_at', $createdAt->format(DateTimeImmutable::ATOM), PDO::PARAM_STR);
        $stmt->bindValue(':expires_at', $expiresAt->format(DateTimeImmutable::ATOM), PDO::PARAM_STR);
        $stmt->bindValue(':auth_key', $authKey, PDO::PARAM_STR | PDO::PARAM_NULL);
        $stmt->execute();
    }

    /**
     * @return array<array{user_id:string,profile_id:string,node_number:int,common_name:string}>
     */
    public function oCertInfoListByAuthKey(string $authKey): array
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                    SELECT
                        user_id,
                        profile_id,
                        node_number,
                        common_name
                    FROM
                        certificates
                    WHERE
                        auth_key = :auth_key
                SQL
        );
        $stmt->bindValue(':auth_key', $authKey, PDO::PARAM_STR);
        $stmt->execute();

        $oCertInfoList = [];
        foreach (self::requireArrayOfArray($stmt->fetchAll(PDO::FETCH_ASSOC)) as $resultRow) {
            $oCertInfoList[] = [
                'user_id' => self::requireString($resultRow, 'user_id'),
                'profile_id' => self::requireString($resultRow, 'profile_id'),
                'node_number' => self::requireInt($resultRow, 'node_number'),
                'common_name' => self::requireString($resultRow, 'common_name'),
            ];
        }

        return $oCertInfoList;
    }

    /**
     * @return array<string,array{node_number:int,user_id:string,profile_id:string,display_name:string,public_key:string,ip_four:string,ip_six:string,created_at:\DateTimeImmutable,expires_at:\DateTimeImmutable,auth_key:?string,user_is_disabled:bool}>
     */
    public function wPeerList(): array
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                SELECT
                    node_number,
                    u.user_id AS user_id,
                    profile_id,
                    display_name,
                    public_key,
                    ip_four,
                    ip_six,
                    created_at,
                    expires_at,
                    auth_key,
                    u.is_disabled AS user_is_disabled
                FROM
                    wg_peers w,
                    users u
                WHERE
                    u.user_id = w.user_id
                SQL
        );
        $stmt->execute();

        $peerList = [];
        foreach (self::requireArrayOfArray($stmt->fetchAll(PDO::FETCH_ASSOC)) as $resultRow) {
            $publicKey = self::requireString($resultRow, 'public_key');
            $peerList[$publicKey] = [
                'node_number' => self::requireInt($resultRow, 'node_number'),
                'user_id' => self::requireString($resultRow, 'user_id'),
                'profile_id' => self::requireString($resultRow, 'profile_id'),
                'display_name' => self::requireString($resultRow, 'display_name'),
                'public_key' => $publicKey,
                'ip_four' => self::requireString($resultRow, 'ip_four'),
                'ip_six' => self::requireString($resultRow, 'ip_six'),
                'created_at' => new DateTimeImmutable(self::requireString($resultRow, 'created_at')),
                'expires_at' => new DateTimeImmutable(self::requireString($resultRow, 'expires_at')),
                'auth_key' => self::requireStringOrNull($resultRow, 'auth_key'),
                'user_is_disabled' => self::requireBool($resultRow, 'user_is_disabled'),
            ];
        }

        return $peerList;
    }

    /**
     * @return array<string,array{node_number:int,user_id:string,profile_id:string,display_name:string,common_name:string,created_at:\DateTimeImmutable,expires_at:\DateTimeImmutable,auth_key:?string,user_is_disabled:bool}>
     */
    public function oCertList(): array
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                    SELECT
                        node_number,
                        u.user_id AS user_id,
                        profile_id,
                        display_name,
                        common_name,
                        created_at,
                        expires_at,
                        auth_key,
                        u.is_disabled AS user_is_disabled
                    FROM
                        certificates c,
                        users u
                    WHERE
                        u.user_id = c.user_id
                SQL
        );
        $stmt->execute();

        $certList = [];
        foreach (self::requireArrayOfArray($stmt->fetchAll(PDO::FETCH_ASSOC)) as $resultRow) {
            $commonName = self::requireString($resultRow, 'common_name');
            $certList[$commonName] = [
                'node_number' => self::requireInt($resultRow, 'node_number'),
                'user_id' => self::requireString($resultRow, 'user_id'),
                'profile_id' => self::requireString($resultRow, 'profile_id'),
                'display_name' => self::requireString($resultRow, 'display_name'),
                'common_name' => $commonName,
                'created_at' => new DateTimeImmutable(self::requireString($resultRow, 'created_at')),
                'expires_at' => new DateTimeImmutable(self::requireString($resultRow, 'expires_at')),
                'auth_key' => self::requireStringOrNull($resultRow, 'auth_key'),
                'user_is_disabled' => self::requireBool($resultRow, 'user_is_disabled'),
            ];
        }

        return $certList;
    }

    /**
     * @return array<array{profile_id:string,node_number:int,common_name:string,display_name:string,expires_at:\DateTimeImmutable,auth_key:?string}>
     */
    public function oCertInfoListByUserId(string $userId): array
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                    SELECT
                        profile_id,
                        node_number,
                        common_name,
                        display_name,
                        expires_at,
                        auth_key
                    FROM
                        certificates
                    WHERE
                        user_id = :user_id
                    ORDER BY
                        expires_at DESC
                SQL
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $stmt->execute();

        $oCertInfoList = [];
        foreach (self::requireArrayOfArray($stmt->fetchAll(PDO::FETCH_ASSOC)) as $resultRow) {
            $oCertInfoList[] = [
                'profile_id' => self::requireString($resultRow, 'profile_id'),
                'node_number' => self::requireInt($resultRow, 'node_number'),
                'common_name' => self::requireString($resultRow, 'common_name'),
                'display_name' => self::requireString($resultRow, 'display_name'),
                'expires_at' => new DateTimeImmutable(self::requireString($resultRow, 'expires_at')),
                'auth_key' => self::requireStringOrNull($resultRow, 'auth_key'),
            ];
        }

        return $oCertInfoList;
    }

    /**
     * @return ?array{user_id:string,user_is_disabled:bool,profile_id:string,node_number:int}
     */
    public function oCertInfo(string $commonName): ?array
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                    SELECT
                        u.user_id AS user_id,
                        u.is_disabled AS user_is_disabled,
                        c.profile_id,
                        c.node_number
                    FROM
                        users u,
                        certificates c
                    WHERE
                        u.user_id = c.user_id
                    AND
                        c.common_name = :common_name
                SQL
        );

        $stmt->bindValue(':common_name', $commonName, PDO::PARAM_STR);
        $stmt->execute();

        $resultRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!\is_array($resultRow)) {
            return null;
        }

        return [
            'user_id' => self::requireString($resultRow, 'user_id'),
            'user_is_disabled' => self::requireBool($resultRow, 'user_is_disabled'),
            'profile_id' => self::requireString($resultRow, 'profile_id'),
            'node_number' => self::requireInt($resultRow, 'node_number'),
        ];
    }

    public function oCertDelete(string $commonName): void
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                    DELETE FROM
                        certificates
                    WHERE
                        common_name = :common_name
                SQL
        );
        $stmt->bindValue(':common_name', $commonName, PDO::PARAM_STR);
        $stmt->execute();
    }

    public function userDisable(string $userId): void
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                    UPDATE
                        users
                    SET
                        is_disabled = true
                    WHERE
                        user_id = :user_id
                SQL
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $stmt->execute();
    }

    public function userEnable(string $userId): void
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                    UPDATE
                        users
                    SET
                        is_disabled = false
                    WHERE
                        user_id = :user_id
                SQL
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $stmt->execute();
    }

    public function clientConnect(string $userId, string $profileId, string $vpnProto, string $connectionId, string $ipFour, string $ipSix, DateTimeImmutable $connectedAt): void
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                    INSERT INTO connection_log
                        (
                            user_id,
                            profile_id,
                            vpn_proto,
                            connection_id,
                            ip_four,
                            ip_six,
                            connected_at
                        )
                    VALUES
                        (
                            :user_id,
                            :profile_id,
                            :vpn_proto,
                            :connection_id,
                            :ip_four,
                            :ip_six,
                            :connected_at
                        )
                SQL
        );

        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $stmt->bindValue(':profile_id', $profileId, PDO::PARAM_STR);
        $stmt->bindValue(':vpn_proto', $vpnProto, PDO::PARAM_STR);
        $stmt->bindValue(':connection_id', $connectionId, PDO::PARAM_STR);
        $stmt->bindValue(':ip_four', $ipFour, PDO::PARAM_STR);
        $stmt->bindValue(':ip_six', $ipSix, PDO::PARAM_STR);
        $stmt->bindValue(':connected_at', $connectedAt->format(DateTimeImmutable::ATOM), PDO::PARAM_STR);
        $stmt->execute();
    }

    public function clientDisconnect(string $connectionId, int $bytesIn, int $bytesOut, DateTimeImmutable $disconnectedAt): void
    {
        // XXX make sure the entry with disconnected_at IS NULL exists, otherwise scream
        $stmt = $this->db->prepare(
            <<< 'SQL'
                    UPDATE
                        connection_log
                    SET
                        bytes_in = :bytes_in,
                        bytes_out = :bytes_out,
                        disconnected_at = :disconnected_at
                    WHERE
                        connection_id = :connection_id
                    AND
                        disconnected_at IS NULL
                SQL
        );

        $stmt->bindValue(':connection_id', $connectionId, PDO::PARAM_STR);
        $stmt->bindValue(':disconnected_at', $disconnectedAt->format(DateTimeImmutable::ATOM), PDO::PARAM_STR);
        $stmt->bindValue(':bytes_in', $bytesIn, PDO::PARAM_INT);
        $stmt->bindValue(':bytes_out', $bytesOut, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Retrieve information about an *open* VPN connection, i.e. where
     * "disconnected_at" is not yet set.
     *
     * @return ?array{user_id:string,profile_id:string,vpn_proto:string,ip_four:string,ip_six:string}
     */
    public function openConnectionInfo(string $connectionId): ?array
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                    SELECT
                        user_id,
                        profile_id,
                        vpn_proto,
                        ip_four,
                        ip_six
                    FROM
                        connection_log
                    WHERE
                        connection_id = :connection_id
                    AND
                        disconnected_at IS NULL
                SQL
        );

        $stmt->bindValue(':connection_id', $connectionId, PDO::PARAM_STR);
        $stmt->execute();

        $resultRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!\is_array($resultRow)) {
            return null;
        }

        return [
            'user_id' => self::requireString($resultRow, 'user_id'),
            'profile_id' => self::requireString($resultRow, 'profile_id'),
            'vpn_proto' => self::requireString($resultRow, 'vpn_proto'),
            'ip_four' => self::requireString($resultRow, 'ip_four'),
            'ip_six' => self::requireString($resultRow, 'ip_six'),
        ];
    }

    /**
     * @return array<array{profile_id:string,ip_four:string,ip_six:string,connected_at:\DateTimeImmutable,disconnected_at:?\DateTimeImmutable}>
     */
    public function getConnectionLogForUser(string $userId): array
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                    SELECT
                        profile_id,
                        ip_four,
                        ip_six,
                        connected_at,
                        disconnected_at
                    FROM
                        connection_log
                    WHERE
                        user_id= :user_id
                    ORDER BY
                        connected_at
                    DESC
                SQL
        );

        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $stmt->execute();

        $connectionLog = [];
        foreach (self::requireArrayOfArray($stmt->fetchAll(PDO::FETCH_ASSOC)) as $resultRow) {

            if (null !== $disconnectedAt = self::requireStringOrNull($resultRow, 'disconnected_at')) {
                $disconnectedAt = new DateTimeImmutable($disconnectedAt);
            }

            $connectionLog[] = [
                'profile_id' => self::requireString($resultRow, 'profile_id'),
                'ip_four' => self::requireString($resultRow, 'ip_four'),
                'ip_six' => self::requireString($resultRow, 'ip_six'),
                'connected_at' => new DateTimeImmutable(self::requireString($resultRow, 'connected_at')),
                'disconnected_at' => $disconnectedAt,
            ];
        }

        return $connectionLog;
    }

    /**
     * @return array<array{user_id:string,profile_id:string,ip_four:string,ip_six:string,connected_at:\DateTimeImmutable,disconnected_at:?\DateTimeImmutable}>
     */
    public function getLogEntries(DateTimeImmutable $dateTime, string $ipAddress): array
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                    SELECT
                        user_id,
                        profile_id,
                        ip_four,
                        ip_six,
                        connected_at,
                        disconnected_at
                    FROM
                        connection_log
                    WHERE
                        (ip_four = :ip_address OR ip_six = :ip_address)
                    AND
                        connected_at <= :date_time
                    AND
                        (disconnected_at IS NULL OR disconnected_at >= :date_time)
                SQL
        );

        $stmt->bindValue(':ip_address', $ipAddress, PDO::PARAM_STR);
        $stmt->bindValue(':date_time', $dateTime->format(DateTimeImmutable::ATOM), PDO::PARAM_STR);
        $stmt->execute();
        $logEntries = [];
        foreach (self::requireArrayOfArray($stmt->fetchAll(PDO::FETCH_ASSOC)) as $resultRow) {
            if (null !== $disconnectedAt = self::requireStringOrNull($resultRow, 'disconnected_at')) {
                $disconnectedAt = new DateTimeImmutable($disconnectedAt);
            }
            $logEntries[] = [
                'user_id' => self::requireString($resultRow, 'user_id'),
                'profile_id' => self::requireString($resultRow, 'profile_id'),
                'ip_four' => self::requireString($resultRow, 'ip_four'),
                'ip_six' => self::requireString($resultRow, 'ip_six'),
                'connected_at' => new DateTimeImmutable(self::requireString($resultRow, 'connected_at')),
                'disconnected_at' => $disconnectedAt,
            ];
        }

        return $logEntries;
    }

    /**
     * Delete the entries from the log where the *DISCONNECT* time is before
     * the provided date.
     */
    public function cleanConnectionLog(DateTimeImmutable $dateTime): void
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                    DELETE FROM
                        connection_log
                    WHERE
                        disconnected_at IS NOT NULL
                    AND
                        disconnected_at < :date_time
                SQL
        );

        $stmt->bindValue(':date_time', $dateTime->format(DateTimeImmutable::ATOM), PDO::PARAM_STR);
        $stmt->execute();
    }

    public function cleanLiveStats(DateTimeImmutable $dateTime): void
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                    DELETE FROM
                        live_stats
                    WHERE
                        date_time < :date_time
                SQL
        );

        $stmt->bindValue(':date_time', $dateTime->format(DateTimeImmutable::ATOM), PDO::PARAM_STR);
        $stmt->execute();
    }

    public function cleanExpiredConfigurations(DateTimeImmutable $dateTime): void
    {
        $stmt = $this->db->prepare('DELETE FROM certificates WHERE expires_at < :date_time');
        $stmt->bindValue(':date_time', $dateTime->format(DateTimeImmutable::ATOM), PDO::PARAM_STR);
        $stmt->execute();

        $stmt = $this->db->prepare('DELETE FROM wg_peers WHERE expires_at < :date_time');
        $stmt->bindValue(':date_time', $dateTime->format(DateTimeImmutable::ATOM), PDO::PARAM_STR);
        $stmt->execute();
    }

    /**
     * Get the non-expired WireGuard and OpenVPN *API* configurations for a
     * particular user.
     *
     * @return array<array{profile_id:string,connection_id:string}>
     */
    public function activeApiConfigurations(string $userId, DateTimeImmutable $dateTime): array
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                SELECT
                    profile_id, common_name AS connection_id, created_at
                FROM
                    certificates
                WHERE
                    user_id = :user_id
                AND
                    auth_key IS NOT NULL
                AND
                    expires_at > :date_time
                UNION
                SELECT
                    profile_id, public_key AS connection_id, created_at
                FROM
                    wg_peers
                WHERE
                    user_id = :user_id
                AND
                    auth_key IS NOT NULL
                AND
                    expires_at > :date_time
                ORDER BY
                    created_at
                SQL
        );

        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $stmt->bindValue(':date_time', $dateTime->format(DateTimeImmutable::ATOM), PDO::PARAM_STR);
        $stmt->execute();

        $activeApiConfigurations = [];
        foreach (self::requireArrayOfArray($stmt->fetchAll(PDO::FETCH_ASSOC)) as $resultRow) {
            $activeApiConfigurations[] = [
                'profile_id' => self::requireString($resultRow, 'profile_id'),
                'connection_id' => self::requireString($resultRow, 'connection_id'),
            ];
        }

        return $activeApiConfigurations;
    }

    /**
     * Get the number of non-expired WireGuard and OpenVPN *portal*
     * configurations for a particular user.
     */
    public function numberOfActivePortalConfigurations(string $userId, DateTimeImmutable $dateTime): int
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                        SELECT SUM(c) AS active_configurations
                        FROM (
                            SELECT
                                COUNT(public_key) AS c
                            FROM
                                wg_peers
                            WHERE
                                user_id = :user_id
                            AND
                                auth_key IS NULL
                            AND
                                expires_at > :date_time
                        UNION ALL
                            SELECT
                                COUNT(common_name) AS c
                            FROM
                                certificates
                            WHERE
                                user_id = :user_id
                            AND
                                auth_key IS NULL
                            AND
                                expires_at > :date_time
                        ) AS c
                SQL
        );

        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $stmt->bindValue(':date_time', $dateTime->format(DateTimeImmutable::ATOM), PDO::PARAM_STR);
        $stmt->execute();

        $resultRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!\is_array($resultRow)) {
            return 0;
        }

        return self::requireInt($resultRow, 'active_configurations');
    }

    public function cleanExpiredOAuthAuthorizations(DateTimeImmutable $dateTime): void
    {
        // XXX is this still needed or already done by the OAuth library?!
        $stmt = $this->db->prepare('DELETE FROM oauth_authorizations WHERE expires_at < :date_time');
        $stmt->bindValue(':date_time', $dateTime->format(DateTimeImmutable::ATOM), PDO::PARAM_STR);

        $stmt->execute();
    }

    public function statsAdd(DateTimeImmutable $dateTime, string $profileId, int $connectionCount): void
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                    INSERT INTO
                        live_stats (
                            date_time,
                            profile_id,
                            connection_count
                        )
                    VALUES (
                        :date_time,
                        :profile_id,
                        :connection_count
                    )
                SQL
        );
        $stmt->bindValue(':date_time', $dateTime->format(DateTimeImmutable::ATOM), PDO::PARAM_STR);
        $stmt->bindValue(':profile_id', $profileId, PDO::PARAM_STR);
        $stmt->bindValue(':connection_count', $connectionCount, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * @return array<array{date_time:\DateTimeImmutable,connection_count:int}>
     */
    public function statsGetLive(string $profileId): array
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                    SELECT
                        date_time,
                        connection_count
                    FROM
                        live_stats
                    WHERE
                        profile_id = :profile_id
                SQL
        );
        $stmt->bindValue(':profile_id', $profileId, PDO::PARAM_STR);
        $stmt->execute();

        $statsData = [];
        foreach (self::requireArrayOfArray($stmt->fetchAll(PDO::FETCH_ASSOC)) as $resultRow) {
            $statsData[] = [
                'date_time' => new DateTimeImmutable(self::requireString($resultRow, 'date_time')),
                'connection_count' => self::requireInt($resultRow, 'connection_count'),
            ];
        }

        return $statsData;
    }

    /**
     * @return array<string,int>
     */
    public function statsGetLiveMaxConnectionCount(): array
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                    SELECT
                        profile_id,
                        MAX(connection_count) AS max_connection_count
                    FROM
                        live_stats
                    GROUP BY
                        profile_id
                SQL
        );
        $stmt->execute();
        $statsData = [];
        foreach (self::requireArrayOfArray($stmt->fetchAll(PDO::FETCH_ASSOC)) as $resultRow) {
            $profileId = self::requireString($resultRow, 'profile_id');
            $maxConnectionCount = self::requireInt($resultRow, 'max_connection_count');
            $statsData[$profileId] = $maxConnectionCount;
        }

        return $statsData;
    }

    /**
     * @return array<string,array{unique_user_count:int}>
     *
     * TODO merge with statsYesterdayUniqueGuestUserCount
     */
    public function statsGetUniqueUsers(DateTimeImmutable $dateTime): array
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                SELECT
                    profile_id,
                    COUNT(DISTINCT user_id) AS unique_user_count
                FROM
                    connection_log
                WHERE
                    connected_at >= :date_time
                GROUP BY
                    profile_id

                SQL
        );
        $stmt->bindValue(':date_time', $dateTime->format(DateTimeImmutable::ATOM), PDO::PARAM_STR);
        $stmt->execute();
        $statsData = [];
        foreach (self::requireArrayOfArray($stmt->fetchAll(PDO::FETCH_ASSOC)) as $resultRow) {
            $statsData[self::requireString($resultRow, 'profile_id')] = [
                'unique_user_count' => self::requireInt($resultRow, 'unique_user_count'),
            ];
        }

        return $statsData;
    }

    /**
     * @return array<string,array{unique_user_count:int}>
     *
     * TODO merge with statsYesterdayUniqueUserCount
     */
    public function statsGetUniqueGuestUsers(DateTimeImmutable $dateTime): array
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                SELECT
                    profile_id,
                    COUNT(DISTINCT user_id) AS unique_user_count
                FROM
                    connection_log
                WHERE
                    connected_at >= :date_time
                AND
                    user_id LIKE '%@%'
                GROUP BY
                    profile_id

                SQL
        );
        $stmt->bindValue(':date_time', $dateTime->format(DateTimeImmutable::ATOM), PDO::PARAM_STR);
        $stmt->execute();
        $statsData = [];
        foreach (self::requireArrayOfArray($stmt->fetchAll(PDO::FETCH_ASSOC)) as $resultRow) {
            $statsData[self::requireString($resultRow, 'profile_id')] = [
                'unique_user_count' => self::requireInt($resultRow, 'unique_user_count'),
            ];
        }

        return $statsData;
    }

    /**
     * @return array<array{date:string,unique_user_count:int,unique_guest_user_count:int,max_connection_count:int}>
     */
    public function statsGetAggregate(string $profileId): array
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                    SELECT
                        date,
                        MAX(unique_user_count) AS unique_user_count,
                        MAX(unique_guest_user_count) AS unique_guest_user_count,
                        MAX(max_connection_count) AS max_connection_count
                    FROM
                        aggregate_stats
                    WHERE
                        profile_id = :profile_id
                    GROUP BY
                        date
                    ORDER BY
                        date
                SQL
        );
        $stmt->bindValue(':profile_id', $profileId, PDO::PARAM_STR);
        $stmt->execute();
        $statsData = [];
        foreach (self::requireArrayOfArray($stmt->fetchAll(PDO::FETCH_ASSOC)) as $resultRow) {
            $statsData[] = [
                'date' => self::requireString($resultRow, 'date'),
                'unique_user_count' => self::requireInt($resultRow, 'unique_user_count'),
                'unique_guest_user_count' => self::requireInt($resultRow, 'unique_guest_user_count'),
                'max_connection_count' => self::requireInt($resultRow, 'max_connection_count'),
            ];
        }

        return $statsData;
    }

    /**
     * Get yesterday's maximum number of concurrent client connections per
     * profile.
     *
     * @return array<string,int>
     */
    public function statsYesterdayMaxConnectionCount(DateTimeImmutable $dateTime): array
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                SELECT
                    profile_id,
                    MAX(connection_count) AS max_connection_count
                FROM
                    live_stats
                WHERE
                    date_time >= :from_date
                AND
                    date_time < :until_date
                GROUP BY
                    profile_id
                SQL
        );
        $stmt->bindValue(':from_date', $dateTime->sub(new DateInterval('P1D'))->format('Y-m-d'), PDO::PARAM_STR);
        $stmt->bindValue(':until_date', $dateTime->format('Y-m-d'), PDO::PARAM_STR);
        $stmt->execute();

        $maxConnectionCountList = [];
        foreach (self::requireArrayOfArray($stmt->fetchAll(PDO::FETCH_ASSOC)) as $resultRow) {
            $maxConnectionCountList[self::requireString($resultRow, 'profile_id')] = self::requireInt($resultRow, 'max_connection_count');
        }

        return $maxConnectionCountList;
    }

    /**
     * Get yesterday's number of unique users that connected to the VPN per
     * profile.
     *
     * @return array<string,int>
     */
    public function statsYesterdayUniqueUserCount(DateTimeImmutable $dateTime): array
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                SELECT
                    profile_id,
                    COUNT(DISTINCT user_id) AS unique_user_count
                FROM
                    connection_log
                WHERE
                    connected_at >= :from_date
                AND
                    connected_at < :until_date
                GROUP BY
                    profile_id
                SQL
        );
        $stmt->bindValue(':from_date', $dateTime->sub(new DateInterval('P1D'))->format('Y-m-d'), PDO::PARAM_STR);
        $stmt->bindValue(':until_date', $dateTime->format('Y-m-d'), PDO::PARAM_STR);
        $stmt->execute();

        $uniqueUserCountList = [];
        foreach (self::requireArrayOfArray($stmt->fetchAll(PDO::FETCH_ASSOC)) as $resultRow) {
            $uniqueUserCountList[self::requireString($resultRow, 'profile_id')] = self::requireInt($resultRow, 'unique_user_count');
        }

        return $uniqueUserCountList;
    }

    /**
     * Get yesterday's number of unique guest users that connected to the VPN
     * per profile.
     *
     * @return array<string,int>
     */
    public function statsYesterdayUniqueGuestUserCount(DateTimeImmutable $dateTime): array
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                SELECT
                    profile_id,
                    COUNT(DISTINCT user_id) AS unique_user_count
                FROM
                    connection_log
                WHERE
                    connected_at >= :from_date
                AND
                    connected_at < :until_date
                AND
                    user_id LIKE '%@%'
                GROUP BY
                    profile_id
                SQL
        );
        $stmt->bindValue(':from_date', $dateTime->sub(new DateInterval('P1D'))->format('Y-m-d'), PDO::PARAM_STR);
        $stmt->bindValue(':until_date', $dateTime->format('Y-m-d'), PDO::PARAM_STR);
        $stmt->execute();

        $uniqueUserCountList = [];
        foreach (self::requireArrayOfArray($stmt->fetchAll(PDO::FETCH_ASSOC)) as $resultRow) {
            $uniqueUserCountList[self::requireString($resultRow, 'profile_id')] = self::requireInt($resultRow, 'unique_user_count');
        }

        return $uniqueUserCountList;
    }

    /**
     * Collect everything we want to put in the "aggregate_stats" table.
     */
    public function statsAggregate(DateTimeImmutable $dateTime): void
    {
        $statsMaxConnectionCount = $this->statsYesterdayMaxConnectionCount($dateTime);
        $statsUniqueUserCount = $this->statsYesterdayUniqueUserCount($dateTime);
        $statsUniqueGuestUserCount = $this->statsYesterdayUniqueGuestUserCount($dateTime);

        foreach ($statsMaxConnectionCount as $profileId => $maxConnectionCount) {
            $uniqueUserCount = 0;
            $uniqueGuestUserCount = 0;
            if (\array_key_exists($profileId, $statsUniqueUserCount)) {
                $uniqueUserCount = $statsUniqueUserCount[$profileId];
            }
            if (\array_key_exists($profileId, $statsUniqueGuestUserCount)) {
                $uniqueGuestUserCount = $statsUniqueGuestUserCount[$profileId];
            }

            $stmt = $this->db->prepare(
                <<< 'SQL'
                        INSERT INTO
                            aggregate_stats (date, profile_id, max_connection_count, unique_user_count, unique_guest_user_count)
                        VALUES
                            (:date, :profile_id, :max_connection_count, :unique_user_count, :unique_guest_user_count)
                    SQL
            );
            $stmt->bindValue(':date', $dateTime->sub(new DateInterval('P1D'))->format('Y-m-d'), PDO::PARAM_STR);
            $stmt->bindValue(':profile_id', $profileId, PDO::PARAM_STR);
            $stmt->bindValue(':max_connection_count', $maxConnectionCount, PDO::PARAM_INT);
            $stmt->bindValue(':unique_user_count', $uniqueUserCount, PDO::PARAM_INT);
            $stmt->bindValue(':unique_guest_user_count', $uniqueGuestUserCount, PDO::PARAM_INT);
            $stmt->execute();
        }
    }

    /**
     * @return array<array{client_id:string,client_count:int}>
     */
    public function appUsage(): array
    {
        $stmt = $this->db->prepare(
            <<< 'SQL'
                    SELECT
                        client_id,
                        COUNT(client_id) AS client_count
                    FROM
                        oauth_authorizations
                    GROUP BY
                        client_id
                    ORDER BY
                        client_count DESC
                SQL
        );
        $stmt->execute();
        $appUsage = [];
        foreach (self::requireArrayOfArray($stmt->fetchAll(PDO::FETCH_ASSOC)) as $resultRow) {
            $appUsage[] = [
                'client_id' => self::requireString($resultRow, 'client_id'),
                'client_count' => self::requireInt($resultRow, 'client_count'),
            ];
        }

        return $appUsage;
    }

    /**
     * SQLite only.
     */
    public function getJournalMode(): string
    {
        $stmt = $this->db->prepare('PRAGMA journal_mode');
        $stmt->execute();
        $resultRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!\is_array($resultRow)) {
            throw new StorageException('unable to determine `journal_mode`');
        }

        return self::requireString($resultRow, 'journal_mode');
    }

    /**
     * SQLite only.
     */
    public function setJournalMode(string $journalMode): void
    {
        if (!\in_array($journalMode, ['wal','delete'], true)) {
            throw new StorageException('unsupported `journal_mode`');
        }
        $stmt = $this->db->prepare(\sprintf('PRAGMA journal_mode = %s', $journalMode));
        $stmt->execute();
    }

    /**
     * @param array<string> $permissionList
     */
    private static function permissionListToString(array $permissionList): string
    {
        return Json::encode($permissionList);
    }

    /**
     * @return array<string>
     */
    private static function stringToPermissionList(string $encodedPermissionList): array
    {
        $permissionList = [];
        foreach (Json::decode($encodedPermissionList) as $permission) {
            if (!\is_string($permission)) {
                continue;
            }
            $permissionList[] = $permission;
        }

        return $permissionList;
    }

    /**
     * @param mixed $inData
     *
     * @return array<array<mixed>>
     */
    private static function requireArrayOfArray($inData): array
    {
        if (!\is_array($inData)) {
            throw new StorageException();
        }
        $outData = [];
        foreach ($inData as $inItem) {
            if (!\is_array($inItem)) {
                throw new StorageException();
            }
            $outData[] = $inItem;
        }

        return $outData;
    }

    /**
     * Database may return an actual `bool`, or the `int` version of it, or
     * even `string` that indicate bool. Handle them all.
     *
     * @param array<mixed> $inData
     */
    private static function requireBool(array $inData, string $k): bool
    {
        if (!\array_key_exists($k, $inData)) {
            throw new StorageException();
        }
        if (\is_string($inData[$k])) {
            return '1' === $inData[$k];
        }
        if (\is_int($inData[$k])) {
            return 1 === $inData[$k];
        }
        if (\is_bool($inData[$k])) {
            return $inData[$k];
        }

        throw new StorageException();
    }

    /**
     * Database may return an actual `int`, or the `string` version of it.
     * Handle them all.
     *
     * @param array<mixed> $inData
     */
    private static function requireInt(array $inData, string $k): int
    {
        if (!\array_key_exists($k, $inData)) {
            throw new StorageException();
        }
        if (\is_string($inData[$k])) {
            return (int) $inData[$k];
        }
        if (\is_int($inData[$k])) {
            return $inData[$k];
        }

        throw new StorageException();
    }

    /**
     * @param array<mixed> $inData
     */
    private static function requireString(array $inData, string $k): string
    {
        if (!\array_key_exists($k, $inData)) {
            throw new StorageException();
        }
        if (\is_string($inData[$k])) {
            return $inData[$k];
        }

        throw new StorageException();
    }

    /**
     * @param array<mixed> $inData
     */
    private static function requireStringOrNull(array $inData, string $k): ?string
    {
        if (!\array_key_exists($k, $inData)) {
            throw new StorageException();
        }
        if (\is_string($inData[$k]) || \is_null($inData[$k])) {
            return $inData[$k];
        }

        throw new StorageException();
    }
}
