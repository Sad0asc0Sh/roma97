<?php
declare(strict_types=1);

/**
 * Runs every test suite for the roma97 fixes and reports the aggregate result.
 *
 * Each suite runs in its own PHP process so DB suites get an isolated fresh
 * database. Exit code is 0 only when every suite passes.
 *
 * Run: php tests/run-all-tests.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$suites = [
    'tests/jalali-date-test.php',
    'tests/sms-system-verification-test.php',
    'tests/sms-queue-reaper-test.php',
    'tests/parent-phone-test.php',
    'tests/role-matrix-test.php',
];

$root = dirname(__DIR__);
$failed = [];

foreach ($suites as $suite) {
    echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "Running {$suite}\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/' . $suite);
    passthru($cmd, $exitCode);

    if ($exitCode !== 0) {
        $failed[] = $suite;
    }
}

echo "\n════════════════════════════════════════════════════\n";
if ($failed === []) {
    echo "ALL TEST SUITES PASSED ✅\n";
    exit(0);
}

echo "FAILED SUITES (" . count($failed) . "):\n";
foreach ($failed as $suite) {
    echo "  - {$suite}\n";
}
exit(1);
