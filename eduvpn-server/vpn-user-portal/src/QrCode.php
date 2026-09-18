<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal;

use Vpn\Portal\Exception\QrCodeException;

final class QrCode
{
    private const QR_ENCODE_PATH = '/usr/bin/qrencode';

    public static function generate(string $qrText): string
    {
        if (false === ob_start()) {
            throw new QrCodeException('unable to start output buffering (ob_start)');
        }

        passthru(
            \sprintf(
                '%s -m 4 -s 4 --inline -t SVG -o - -- %s',
                self::QR_ENCODE_PATH,
                escapeshellarg($qrText)
            ),
            $resultCode
        );

        if (0 !== $resultCode) {
            ob_end_clean();

            throw new QrCodeException('unable to generate QR code');
        }

        if (false === $outStr = ob_get_clean()) {
            throw new QrCodeException('unable to stop output buffering (ob_get_clean)');
        }

        return $outStr;
    }
}
