<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\HttpClient;

use CurlHandle;

final class CurlClient implements ClientInterface
{
    private ClientConfig $clientConfig;

    public function __construct(
        ?ClientConfig $clientConfig = null
    ) {
        $this->clientConfig = $clientConfig ?? new ClientConfig();
    }

    #[\Override]
    public function send(Request $request): Response
    {
        if (false === $curlHandle = curl_init()) {
            throw new ClientException('cuRL: call to curl_init failed');
        }

        $this->setCurlOption($curlHandle, \CURLOPT_URL, $request->requestUrlWithQuery());
        $this->setCurlOption($curlHandle, \CURLOPT_HTTPHEADER, $request->requestHeaders);
        $this->setCurlOption($curlHandle, \CURLOPT_RETURNTRANSFER, true);
        $this->setCurlOption($curlHandle, \CURLOPT_FOLLOWLOCATION, false);
        $this->setCurlOption($curlHandle, \CURLOPT_SSL_VERIFYPEER, true);
        $this->setCurlOption($curlHandle, \CURLOPT_SSL_VERIFYHOST, 2);
        $this->setCurlOption($curlHandle, \CURLOPT_SSLVERSION, \CURL_SSLVERSION_TLSv1_3);
        $this->setCurlOption($curlHandle, \CURLOPT_CONNECTTIMEOUT, 8);
        $this->setCurlOption($curlHandle, \CURLOPT_TIMEOUT, 12);
        $this->setCurlOption($curlHandle, \CURLOPT_PROTOCOLS, \CURLPROTO_HTTP | \CURLPROTO_HTTPS);

        if (null !== $this->clientConfig->caFile && '' !== $this->clientConfig->caFile) {
            $this->setCurlOption($curlHandle, \CURLOPT_CAINFO, $this->clientConfig->caFile);
        }
        if (null !== $this->clientConfig->certFile && '' !== $this->clientConfig->certFile) {
            $this->setCurlOption($curlHandle, \CURLOPT_SSLCERT, $this->clientConfig->certFile);
        }
        if (null !== $this->clientConfig->keyFile && '' !== $this->clientConfig->keyFile) {
            $this->setCurlOption($curlHandle, \CURLOPT_SSLKEY, $this->clientConfig->keyFile);
        }
        if ('POST' === $request->requestMethod) {
            $this->setCurlOption($curlHandle, \CURLOPT_POSTFIELDS, $request->encodedPostParameters() ?? '');
        }

        if (false === $responseData = curl_exec($curlHandle)) {
            throw new ClientException(\sprintf('cURL: call to curl_exec failed: %s', curl_error($curlHandle)));
        }
        if (!\is_string($responseData)) {
            throw new ClientException('cURL: call to curl_exec returned unexpected value');
        }

        /** @var int */
        $responseCode = curl_getinfo($curlHandle, \CURLINFO_RESPONSE_CODE);
        curl_close($curlHandle);

        return new Response(
            responseCode: $responseCode,
            responseBody: $responseData
        );
    }

    /**
     * @param mixed $optionValue
     */
    private function setCurlOption(CurlHandle $curlHandle, int $curlOption, $optionValue): void
    {
        if (false === curl_setopt($curlHandle, $curlOption, $optionValue)) {
            throw new ClientException(\sprintf('cURL: unable to set option "%d"', $curlOption));
        }
    }
}
