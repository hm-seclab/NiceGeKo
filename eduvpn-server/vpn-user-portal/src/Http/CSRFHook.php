<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Http;

use Vpn\Portal\Http\Exception\HttpException;

/**
 * Prevent CSRF in 2025.
 *
 * We only want to trust modern browsers, and no requests from anywhere that
 * are not "same-origin", so we can simplify the CSRF protection substantially.
 *
 * @see https://words.filippo.io/csrf/
 */
final class CSRFHook extends AbstractHook implements HookInterface
{
    #[\Override]
    public function beforeAuth(Request $request): ?Response
    {
        // Allow all GET, HEAD, or OPTIONS requests.
        if (\in_array($request->getRequestMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return null;
        }

        // Require Sec-Fetch-Site header to be set, and thus come from a
        // modern browser
        $httpSecFetchSite = $request->requireHeader('HTTP_SEC_FETCH_SITE');
        if (!\in_array($httpSecFetchSite, ['same-origin', 'none'], true)) {
            throw new HttpException('"Sec-Fetch-Site" header value is not "same-origin" or "none" (CSRF)', 403);
        }

        return null;
    }
}
