<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\OAuth;

use fkooman\OAuth\Server\ClientInfo;
use fkooman\OAuth\Server\Scope;
use fkooman\OAuth\Server\SimpleClientDb;
use Vpn\Portal\FileIO;
use Vpn\Portal\Json;

final class VpnClientDb extends SimpleClientDb
{
    /**
     * @param array<string> $appSets
     */
    public function __construct(string $jsonClientDbFile, array $appSets)
    {
        if (\in_array('eduVPN', $appSets, true)) {
            $this->eduVPN();
        }
        if (\in_array('LC', $appSets, true)) {
            $this->LC();
        }
        if (\in_array('govVPN', $appSets, true)) {
            $this->govVPN();
        }
        $this->fromJsonFile($jsonClientDbFile);
    }

    private function fromJsonFile(string $jsonClientDbFile): void
    {
        if (!FileIO::exists($jsonClientDbFile)) {
            return;
        }
        $clientDbData = Json::decode(FileIO::read($jsonClientDbFile));
        foreach ($clientDbData as $clientInfoData) {
            if (!\is_array($clientInfoData)) {
                continue;
            }
            $this->add(ClientInfo::fromData($clientInfoData));
        }
    }

    private function eduVPN(): void
    {
        $this->add(
            new ClientInfo(
                'org.eduvpn.app.windows',
                ['http://127.0.0.1:{PORT}/callback', 'http://[::1]:{PORT}/callback'],
                null,
                'eduVPN for Windows',
                true,
                new Scope('config'),
                ['query', 'form_post']
            )
        );
        $this->add(
            new ClientInfo(
                'org.eduvpn.app.android',
                [
                    // we want the scheme of the redirect_uri to match the
                    // client_id, initially we made a mistake on Android...
                    'org.eduvpn.app:/api/callback',
                    'org.eduvpn.app.android:/api/callback',
                ],
                null,
                'eduVPN for Android',
                true,
                new Scope('config'),
                ['query']
            )
        );
        $this->add(
            new ClientInfo(
                'org.eduvpn.app.ios',
                ['org.eduvpn.app.ios:/api/callback'],
                null,
                'eduVPN for iOS',
                true,
                new Scope('config'),
                ['query']
            )
        );
        $this->add(
            new ClientInfo(
                'org.eduvpn.app.macos',
                ['org.eduvpn.app.macos:/api/callback', 'http://127.0.0.1:{PORT}/callback', 'http://[::1]:{PORT}/callback'],
                null,
                'eduVPN for macOS',
                true,
                new Scope('config'),
                ['query', 'form_post']
            )
        );
        $this->add(
            new ClientInfo(
                'org.eduvpn.app.linux',
                ['http://127.0.0.1:{PORT}/callback', 'http://[::1]:{PORT}/callback'],
                null,
                'eduVPN for Linux',
                true,
                new Scope('config'),
                ['query', 'form_post']
            )
        );
    }

    private function LC(): void
    {
        $this->add(
            new ClientInfo(
                'org.letsconnect-vpn.app.windows',
                ['http://127.0.0.1:{PORT}/callback', 'http://[::1]:{PORT}/callback'],
                null,
                'Let\'s Connect! for Windows',
                true,
                new Scope('config'),
                ['query', 'form_post']
            )
        );
        $this->add(
            new ClientInfo(
                'org.letsconnect-vpn.app.android',
                [
                    // we want the scheme of the redirect_uri to match the
                    // client_id, initially we made a mistake on Android...
                    'org.letsconnect-vpn.app:/api/callback',
                    'org.letsconnect-vpn.app.android:/api/callback',
                ],
                null,
                'Let\'s Connect! for Android',
                true,
                new Scope('config'),
                ['query']
            )
        );
        $this->add(
            new ClientInfo(
                'org.letsconnect-vpn.app.ios',
                ['org.letsconnect-vpn.app.ios:/api/callback'],
                null,
                'Let\'s Connect! for iOS',
                true,
                new Scope('config'),
                ['query']
            )
        );
        $this->add(
            new ClientInfo(
                'org.letsconnect-vpn.app.macos',
                ['org.letsconnect-vpn.app.macos:/api/callback', 'http://127.0.0.1:{PORT}/callback', 'http://[::1]:{PORT}/callback'],
                null,
                'Let\'s Connect! for macOS',
                true,
                new Scope('config'),
                ['query', 'form_post']
            )
        );
        $this->add(
            new ClientInfo(
                'org.letsconnect-vpn.app.linux',
                ['http://127.0.0.1:{PORT}/callback', 'http://[::1]:{PORT}/callback'],
                null,
                'Let\'s Connect! for Linux',
                true,
                new Scope('config'),
                ['query', 'form_post']
            )
        );
    }

    private function govVPN(): void
    {
        $this->add(
            new ClientInfo(
                'org.govvpn.app.windows',
                ['http://127.0.0.1:{PORT}/callback', 'http://[::1]:{PORT}/callback'],
                null,
                'govVPN for Windows',
                true,
                new Scope('config'),
                ['query', 'form_post']
            )
        );
        $this->add(
            new ClientInfo(
                'org.govvpn.app.android',
                [
                    // we want the scheme of the redirect_uri to match the
                    // client_id, initially we made a mistake on Android...
                    'org.govvpn.app:/api/callback',
                    'org.govvpn.app.android:/api/callback',
                ],
                null,
                'govVPN for Android',
                true,
                new Scope('config'),
                ['query']
            )
        );
        $this->add(
            new ClientInfo(
                'org.govvpn.app.ios',
                ['org.govvpn.app.ios:/api/callback'],
                null,
                'govVPN for iOS',
                true,
                new Scope('config'),
                ['query']
            )
        );
        $this->add(
            new ClientInfo(
                'org.govvpn.app.macos',
                ['org.govvpn.app.macos:/api/callback', 'http://127.0.0.1:{PORT}/callback', 'http://[::1]:{PORT}/callback'],
                null,
                'govVPN for macOS',
                true,
                new Scope('config'),
                ['query', 'form_post']
            )
        );
        $this->add(
            new ClientInfo(
                'org.govvpn.app.linux',
                ['http://127.0.0.1:{PORT}/callback', 'http://[::1]:{PORT}/callback'],
                null,
                'govVPN for Linux',
                true,
                new Scope('config'),
                ['query', 'form_post']
            )
        );
    }
}
