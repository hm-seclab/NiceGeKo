<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Cfg;

use DateInterval;
use Vpn\Portal\Crypto\Minisign\PublicKey;
use Vpn\Portal\Extractor;

final class ApiConfig
{
    private const DEFAULT_TOKEN_EXPIRY = 'PT1H';
    private const DEFAULT_APP_GONE_INTERVAL = 'PT72H';
    private const DEFAULT_GUEST_ACCESS_SERVER_LIST_URL = 'https://disco.eduvpn.org/v2/server_list.json';
    private const DEFAULT_GUEST_ACCESS_SERVER_LIST_SIGNATURE_URL = 'https://disco.eduvpn.org/v2/server_list.json.minisig';
    private const DEFAULT_GUEST_ACCESS_PUBLIC_KEY_LIST = [
        'RWQKqtqvd0R7rUDp0rWzbtYPA3towPWcLDCl7eY9pBMMI/ohCmrS0WiM',
        'RWRtBSX1alxyGX+Xn3LuZnWUT0w//B6EmTJvgaAxBMYzlQeI+jdrO6KF',
    ];

    public function __construct(
        /** @var array<mixed> $d */
        private array $d
    ) {}

    /**
     * @return array<string>
     */
    public function appSets(): array
    {
        return Extractor::requireStringArray($this->d, 'appSets', ['eduVPN', 'LC']);
    }

    /**
     * OAuth "access_token" expiry.
     */
    public function tokenExpiry(): DateInterval
    {
        return new DateInterval(Extractor::requireString($this->d, 'tokenExpiry', self::DEFAULT_TOKEN_EXPIRY));
    }

    public function maxActiveConfigurations(): int
    {
        return Extractor::requireInt($this->d, 'maxActiveConfigurations', 3);
    }

    /**
     * The interval after which to consider an API client gone without
     * any activity.
     *
     * This is used to clean up WireGuard IP allocations for clients that are
     * most likely permanently gone and did not call the "/disconnect" API.
     */
    public function appGoneInterval(): DateInterval
    {
        return new DateInterval(Extractor::requireString($this->d, 'appGoneInterval', self::DEFAULT_APP_GONE_INTERVAL));
    }

    public function deleteAuthorizationOnDisconnect(): bool
    {
        return Extractor::requireBool($this->d, 'deleteAuthorizationOnDisconnect', false);
    }

    public function issuerIdentity(): ?string
    {
        return Extractor::optionalString($this->d, 'issuerIdentity');
    }

    public function enableGuestAccess(): bool
    {
        return Extractor::requireBool($this->d, 'enableGuestAccess', false);
    }

    public function guestAccessServerListUrl(): string
    {
        return Extractor::requireString($this->d, 'guestAccessServerListUrl', self::DEFAULT_GUEST_ACCESS_SERVER_LIST_URL);
    }

    public function guestAccessServerListSignatureUrl(): string
    {
        return Extractor::requireString($this->d, 'guestAccessServerListSignatureUrl', self::DEFAULT_GUEST_ACCESS_SERVER_LIST_SIGNATURE_URL);
    }

    /**
     * @return array<\Vpn\Portal\Crypto\Minisign\PublicKey>
     */
    public function guestAccessPublicKeyList(): array
    {
        $guestAccessPublicKeyList = [];
        foreach (Extractor::requireStringArray($this->d, 'guestAccessPublicKeyList', self::DEFAULT_GUEST_ACCESS_PUBLIC_KEY_LIST) as $publicKey) {
            $guestAccessPublicKeyList[] = PublicKey::fromEncodedString($publicKey);
        }

        return $guestAccessPublicKeyList;
    }
}
