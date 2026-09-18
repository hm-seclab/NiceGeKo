<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

/*
 * Entry point for the PHP unit tests. Loads the bootstrap (classes + T runner),
 * runs each *Test.php, prints a TAP summary, exits non-zero on any failure.
 */

require __DIR__ . '/bootstrap.php';

require __DIR__ . '/PostureCheckConfigTest.php';
require __DIR__ . '/PostureCheckerTest.php';

T::summary('php unit');
exit(T::$fail > 0 ? 1 : 0);
