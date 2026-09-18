<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

/*
 * Dependency-free test bootstrap for the posture-check PHP unit tests.
 *
 * Licensing note: this file is MIT, but it require_once's AGPL-3.0-or-later classes
 * from the vendored portal below. That is fine and deliberate — MIT is one-way
 * compatible with the (A)GPL, so the combination is permitted; the combined work as
 * executed is AGPL-governed. Do not "fix" this by relicensing the file.
 *
 * PHPUnit is not available/pinned in this project (no vendor/, no composer on
 * the host), and the only classes under test have tiny, explicit dependencies —
 * so we load them directly and provide a minimal TAP-style assert runner rather
 * than pull in a framework. Run inside php:8.4-cli (see run.sh).
 */

$src = \dirname(__DIR__, 2) . '/eduvpn-server/vpn-user-portal/src';

// Explicit require in dependency order. PostureChecker.php defines BOTH
// PostureChecker and PostureResult, so a PSR-4 autoloader would miss
// PostureResult (no PostureResult.php) — loading the file directly avoids that.
require_once $src . '/LoggerInterface.php';       // Vpn\Portal\LoggerInterface
require_once $src . '/NullLogger.php';            // Vpn\Portal\NullLogger
require_once $src . '/Extractor.php';             // Vpn\Portal\Extractor (uses SPL RangeException)
require_once $src . '/Cfg/PostureCheckConfig.php';// Vpn\Portal\Cfg\PostureCheckConfig
require_once $src . '/PostureChecker.php';        // Vpn\Portal\PostureChecker + PostureResult
// Config is the switch that decides whether the gate exists at all
// (postureCheckConfig() === null => web/api.php passes no PostureChecker =>
// VpnApiThreeModule skips the check). Loads standalone; only Extractor needed.
require_once $src . '/Cfg/Config.php';            // Vpn\Portal\Cfg\Config

// --- minimal TAP-style assert runner ----------------------------------------
final class T
{
    public static int $n = 0;
    public static int $pass = 0;
    public static int $fail = 0;

    public static function ok(bool $cond, string $msg): void
    {
        self::$n++;
        if ($cond) { self::$pass++; echo "ok " . self::$n . " - {$msg}\n"; }
        else { self::$fail++; echo "not ok " . self::$n . " - {$msg}\n"; }
    }

    public static function eq(mixed $want, mixed $got, string $msg): void
    {
        $cond = $want === $got;
        self::ok($cond, $msg);
        if (!$cond) {
            echo "  # expected: " . var_export($want, true) . "\n";
            echo "  # actual:   " . var_export($got, true) . "\n";
        }
    }

    /** Assert that $fn throws (optionally of a given class). */
    public static function throws(callable $fn, string $msg, ?string $class = null): void
    {
        try {
            $fn();
            self::ok(false, $msg . ' (no exception thrown)');
        } catch (\Throwable $e) {
            self::ok(null === $class || $e instanceof $class, $msg);
        }
    }

    public static function summary(string $suite): void
    {
        echo "\n1.." . self::$n . "  # {$suite}: " . self::$pass . " passed, " . self::$fail . " failed\n";
    }
}
