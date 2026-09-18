<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\OAuth;

use DateInterval;
use fkooman\OAuth\Server\ClientDbInterface;
use fkooman\OAuth\Server\OAuthServer;
use fkooman\OAuth\Server\SignerInterface;
use fkooman\OAuth\Server\StorageInterface;

/**
 * Class to allow overriding the access_token and refresh_token expiry.
 */
final class VpnOAuthServer extends OAuthServer
{
    public function __construct(StorageInterface $storage, ClientDbInterface $clientDb, SignerInterface $signer, DateInterval $accessTokenExpiry, string $issuerIdentity)
    {
        parent::__construct($storage, $clientDb, $signer, $issuerIdentity);
        $this->accessTokenExpiry = $accessTokenExpiry;
    }
}
