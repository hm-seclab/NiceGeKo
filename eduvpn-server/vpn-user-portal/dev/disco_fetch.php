<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

require_once \dirname(__DIR__) . '/vendor/autoload.php';

use Vpn\Portal\Crypto\Minisign\PublicKey;
use Vpn\Portal\Crypto\Minisign\Signature;
use Vpn\Portal\Crypto\Minisign\Verifier;
use Vpn\Portal\HttpClient\CurlClient;
use Vpn\Portal\HttpClient\Request;

try {
    $h1 = new Request('GET', 'https://disco.eduvpn.org/v2/server_list.json');
    $h2 = new Request('GET', 'https://disco.eduvpn.org/v2/server_list.json.minisig');
    $v = new Verifier(
        [
            PublicKey::fromEncodedString('RWQKqtqvd0R7rUDp0rWzbtYPA3towPWcLDCl7eY9pBMMI/ohCmrS0WiM'),
            PublicKey::fromEncodedString('RWRtBSX1alxyGX+Xn3LuZnWUT0w//B6EmTJvgaAxBMYzlQeI+jdrO6KF'),
        ]
    );
    $c = new CurlClient();
    $r1 = $c->send($h1);
    $r2 = $c->send($h2);

    var_dump(
        $v->verifyDetached(
            $r1->responseBody,
            Signature::fromString($r2->responseBody)
        )
    );
} catch (Throwable $e) {
    echo \sprintf('ERROR: %s', $e->getMessage()) . \PHP_EOL;
    exit(1);
}
