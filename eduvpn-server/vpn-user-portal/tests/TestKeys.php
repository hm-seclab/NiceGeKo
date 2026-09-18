<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Tests;

use Vpn\Portal\FileIO;
use Vpn\Portal\OpenVpn\TlsCrypt;
use Vpn\Portal\WireGuard\Key;

/**
 * Generates the key material the tests used to load from tests/data/*.key.
 *
 * Those fixtures could not be kept in the repository: the GitLab group hosting
 * it rejects any file matching \.(pem|key)$ on push. The keys are generated
 * fresh into the test's temporary directory instead, using the same code paths
 * the portal itself uses.
 */
final class TestKeys
{
    /**
     * Register a node's WireGuard public key, as ServerConfig would.
     *
     * The value is random per run — no test asserts on it, they only need the
     * file to exist and to hold a well-formed public key.
     */
    public static function nodePublicKey(string $keyDir, int $nodeNumber = 0): string
    {
        $publicKey = Key::publicKeyFromSecretKey(Key::generate());
        FileIO::write(\sprintf('%s/wireguard.%d.public.key', $keyDir, $nodeNumber), $publicKey);

        return $publicKey;
    }

    /**
     * Create the tls-crypt key for a profile and return its content.
     *
     * TlsCrypt::get() writes the key on first use, so this both prepares the
     * directory and yields the material tests need to build their expected
     * OpenVPN configuration.
     */
    public static function tlsCrypt(string $keyDir, string $profileId): string
    {
        return (new TlsCrypt($keyDir))->get($profileId);
    }
}
