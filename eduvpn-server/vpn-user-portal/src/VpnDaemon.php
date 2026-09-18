<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal;

use Vpn\Portal\HttpClient\ClientException;
use Vpn\Portal\HttpClient\ClientInterface;
use Vpn\Portal\HttpClient\Request;
use Vpn\Portal\HttpClient\Response;

/**
 * Class interfacing with vpn-daemon and preparing the response data to be
 * easier to use from PHP.
 */
final class VpnDaemon
{
    public function __construct(
        private ClientInterface $httpClient,
        private LoggerInterface $logger
    ) {}

    /**
     * @return ?array{connection_count:int,rel_load_average:array<int>,load_average:array<float>,cpu_count:int,node_uptime:int,maintenance_mode:bool,v:string}
     */
    public function nodeInfo(string $nodeUrl, bool $connectionCount = false): ?array
    {
        $response = $this->send(
            new Request(
                requestMethod: 'GET',
                requestUrl: \sprintf('%s/i/node', $nodeUrl),
                queryParameters: [
                    'include_client_peer_count' => $connectionCount ? 'yes' : 'no',
                ]
            )
        );
        if (null === $response) {
            return null;
        }

        $nodeInfo = Json::decode($response->responseBody);

        return [
            'rel_load_average' => Extractor::requireIntArray($nodeInfo, 'rel_load_average', [0,0,0]),
            'load_average' => Extractor::requireFloatArray($nodeInfo, 'load_average', [0,0,0]),
            'cpu_count' => Extractor::requireInt($nodeInfo, 'cpu_count', 0),
            'node_uptime' => Extractor::requireInt($nodeInfo, 'node_uptime', 0),
            'maintenance_mode' => Extractor::requireBool($nodeInfo, 'maintenance_mode', false),
            'connection_count' => Extractor::requireInt($nodeInfo, 'w_peer_count', 0) + Extractor::requireInt($nodeInfo, 'o_client_count', 0),
            'v' => Extractor::optionalString($nodeInfo, 'v') ?? '?',
        ];
    }

    /**
     * @param bool $showAll also include peers that were never seen, or did not
     *   perform a handshake in the last 3 minutes
     *
     * @see https://codeberg.org/eduVPN/vpn-daemon#peer-list
     *
     * @return array<string,array{public_key:string,ip_net:array<string>,last_handshake_time:?string,bytes_in:int,bytes_out:int}>
     */
    public function wPeerList(string $nodeUrl, bool $showAll): array
    {
        $response = $this->send(
            new Request(
                requestMethod: 'GET',
                requestUrl: \sprintf('%s/w/peer_list', $nodeUrl),
                queryParameters: [
                    'show_all' => $showAll ? 'yes' : 'no',
                ]
            )
        );
        if (null === $response) {
            return [];
        }

        $wPeerList = Json::decode($response->responseBody);
        $pList = [];
        foreach (Extractor::requireArrayArray($wPeerList, 'peer_list') as $peerInfo) {
            $publicKey = Extractor::requireString($peerInfo, 'public_key');
            $pList[$publicKey] = [
                'public_key' => $publicKey,
                'ip_net' => Extractor::requireStringArray($peerInfo, 'ip_net'),
                // vpn-daemon < 3.5.5 had `null` or `string`
                // vpn-daemon >= 3.5.5 has `string` or omits the key entirely
                'last_handshake_time' => Extractor::optionalStringOrNull($peerInfo, 'last_handshake_time'),
                'bytes_in' => Extractor::requireInt($peerInfo, 'bytes_in'),
                'bytes_out' => Extractor::requireInt($peerInfo, 'bytes_out'),
            ];
        }

        return $pList;
    }

    public function wPeerAdd(string $nodeUrl, string $publicKey, string $ipFour, string $ipSix): void
    {
        $this->send(
            new Request(
                requestMethod: 'POST',
                requestUrl: \sprintf('%s/w/add_peer', $nodeUrl),
                postParameters: [
                    'public_key' => $publicKey,
                    'ip_net' => [$ipFour . '/32', $ipSix . '/128'],
                ]
            )
        );
    }

    /**
     * @return ?array{public_key:string,ip_net:array<string>,last_handshake_time:?string,bytes_in:int,bytes_out:int}
     */
    public function wPeerRemove(string $nodeUrl, string $publicKey): ?array
    {
        $response = $this->send(
            new Request(
                requestMethod: 'POST',
                requestUrl: \sprintf('%s/w/remove_peer', $nodeUrl),
                postParameters: [
                    'public_key' => $publicKey,
                ]
            )
        );
        if (null === $response) {
            return null;
        }
        if (200 === $response->responseCode) {
            $peerInfo = Json::decode($response->responseBody);

            return [
                'public_key' => Extractor::requireString($peerInfo, 'public_key'),
                'ip_net' => Extractor::requireStringArray($peerInfo, 'ip_net'),
                'last_handshake_time' => Extractor::optionalStringOrNull($peerInfo, 'last_handshake_time'),
                'bytes_in' => Extractor::requireInt($peerInfo, 'bytes_in'),
                'bytes_out' => Extractor::requireInt($peerInfo, 'bytes_out'),
            ];
        }

        // response was probably 204 ("No Content"), but not an error
        return null;
    }

    /**
     * @return array<string,array{common_name:string,ip_four:string,ip_six:string}>
     */
    public function oConnectionList(string $nodeUrl): array
    {
        $response = $this->send(
            new Request(
                requestMethod: 'GET',
                requestUrl: \sprintf('%s/o/connection_list', $nodeUrl)
            )
        );
        if (null === $response) {
            return [];
        }

        $oConnectionList = Json::decode($response->responseBody);
        $cList = [];
        foreach (Extractor::requireArrayArray($oConnectionList, 'connection_list') as $clientInfo) {
            $commonName = Extractor::requireString($clientInfo, 'common_name');
            $cList[$commonName] = [
                'common_name' => $commonName,
                'ip_four' => Extractor::requireString($clientInfo, 'ip_four'),
                'ip_six' => Extractor::requireString($clientInfo, 'ip_six'),
            ];
        }

        return $cList;
    }

    public function oDisconnectClient(string $nodeUrl, string $commonName): void
    {
        $this->send(
            new Request(
                requestMethod: 'POST',
                requestUrl: \sprintf('%s/o/disconnect_client', $nodeUrl),
                postParameters: [
                    'common_name' => $commonName,
                ]
            )
        );
    }

    private function send(Request $request): ?Response
    {
        try {
            $response = $this->httpClient->send($request);
            if (!$response->isOkay()) {
                $this->logger->warning(\sprintf('Unexpected HTTP response from URL "%s": %s', $request->requestUrlWithQuery(), $response));

                return null;
            }

            return $response;
        } catch (ClientException $e) {
            $this->logger->error(\sprintf('HTTP client error when requesting URL "%s": %s', $request->requestUrlWithQuery(), $e->getMessage()));

            return null;
        }
    }
}
