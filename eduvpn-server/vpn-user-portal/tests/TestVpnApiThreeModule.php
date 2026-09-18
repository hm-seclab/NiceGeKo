<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Tests;

use DateTimeImmutable;
use fkooman\OAuth\Server\PdoStorage as OAuthStorage;
use Vpn\Portal\Cfg\Config;
use Vpn\Portal\ConnectionManager;
use Vpn\Portal\Http\VpnApiThreeModule;
use Vpn\Portal\PermissionSourceManager;
use Vpn\Portal\ServerInfo;
use Vpn\Portal\Storage;

final class TestVpnApiThreeModule extends VpnApiThreeModule
{
    public function __construct(Config $config, Storage $storage, ServerInfo $serverInfo, ConnectionManager $connectionManager)
    {
        $oauthStorage = new OAuthStorage($storage->dbPdo(), 'oauth_');
        parent::__construct($config, $storage, $oauthStorage, $serverInfo, $connectionManager, new PermissionSourceManager());
        $this->dateTime = new DateTimeImmutable('2022-01-01T09:00:00+00:00');
    }
}
