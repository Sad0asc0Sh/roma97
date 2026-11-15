<?php
declare(strict_types=1);

/**
 * Shared bootstrap for roma97 CLI tests.
 *
 * Usage (from one test file):
 *   require __DIR__ . '/test-bootstrap.php';
 *   testAssert($cond, 'description');
 *   ...
 *   exit(testSummary());
 *
 * DB tests: set env ROMA_TEST_DB_HOST/USER/PASS/NAME (defaults below), then
 * call testDbConnect() which creates a FRESH test database from schema.sql.
 * Never point it at a production database — the bootstrap DROPs and recreates
 * the configured database.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = dirname(__DIR__);

// Safety: never run the test-suite against a real installation. If a local
// config exists it would take precedence over the test env vars below and the
// tests could touch production data.
if (is_file($projectRoot . '/config.local.php')) {
    fwrite(STDERR, "Refusing to run tests: config.local.php exists. Run the test suite in an environment without config.local.php.\n");
    exit(2);
}

// Test-only environment: dev mode surfaces OTP codes instead of sending SMS,
// and the SMS gateway stays disabled (no network calls in tests).
$testDbHost = getenv('ROMA_TEST_DB_HOST') ?: 'localhost';
$testDbUser = getenv('ROMA_TEST_DB_USER') ?: 'root';
$testDbPass = getenv('ROMA_TEST_DB_PASS') ?: '';
$testDbName = getenv('ROMA_TEST_DB_NAME') ?: 'roma97_test';
if (preg_match('/\Aroma[a-z0-9_]*_test(?:_[a-z0-9]+)?\z/', $testDbName) !== 1) {
    fwrite(STDERR, "Refusing to reset a database without a dedicated roma test name.\n");
    exit(2);
}

// Point the application itself at the throwaway test database BEFORE config
// loads (config.php reads these env vars into DB_* constants).
putenv("DB_HOST={$testDbHost}");
putenv("DB_USER={$testDbUser}");
putenv("DB_PASS={$testDbPass}");
putenv("DB_NAME={$testDbName}");
putenv("DB_DATABASE={$testDbName}");

defined('DEVELOPMENT_MODE') || define('DEVELOPMENT_MODE', true);

require $projectRoot . '/config.php';
require $projectRoot . '/includes/functions.php';

$__testTotal = 0;
$__testFailed = 0;
$__testFailures = [];

function testAssert(bool $condition, string $label): void
{
    global $__testTotal, $__testFailed, $__testFailures;
    $__testTotal++;

    if ($condition) {
        echo "  ok   - {$label}\n";
    } else {
        $__testFailed++;
        $__testFailures[] = $label;
        echo "  FAIL - {$label}\n";
    }
}

function testSummary(string $suiteName): int
{
    global $__testTotal, $__testFailed, $__testFailures;

    echo "\n=== {$suiteName}: {$__testTotal} assertions, {$__testFailed} failed ===\n";
    foreach ($__testFailures as $failure) {
        echo "  * {$failure}\n";
    }

    return $__testFailed === 0 ? 0 : 1;
}

/**
 * Open a PDO to the existing test database WITHOUT dropping it.
 * Used by subprocess probes that must see state created by the parent test.
 */
function testDbOpen(): PDO
{
    global $testDbHost, $testDbUser, $testDbPass, $testDbName;

    return new PDO(
        "mysql:host={$testDbHost};dbname={$testDbName};charset=utf8mb4",
        $testDbUser,
        $testDbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

/**
 * Create a fresh test database from schema.sql and return a PDO to it.
 * Also points the app's own getDb() at the test database.
 */
function testDbConnect(): PDO
{
    global $projectRoot, $testDbHost, $testDbUser, $testDbPass, $testDbName;

    $host = $testDbHost;
    $user = $testDbUser;
    $pass = $testDbPass;
    $name = $testDbName;

    $adminDsn = "mysql:host={$host};charset=utf8mb4";
    $admin = new PDO($adminDsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $admin->exec("DROP DATABASE IF EXISTS `{$name}`");
    $admin->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    // Use pipes rather than shell redirection, and keep passwords out of process arguments.
    $mysqlBin = getenv('ROMA_TEST_MYSQL_BIN') ?: 'mysql';
    $schemaFile = $projectRoot . '/schema.sql';
    $environment = getenv();
    $environment['MYSQL_PWD'] = $pass;
    $process = proc_open([$mysqlBin, '-h', $host, '-u', $user, $name],
        [0 => ['file', $schemaFile, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    if (!is_resource($process)) { throw new RuntimeException('Cannot launch test database importer.'); }
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('schema.sql import failed: ' . $output . $errors);
    }

    // The app's own getDb() already points at this database (env was set
    // before config.php loaded). Return a direct handle for assertions.
    return new PDO(
        "mysql:host={$host};dbname={$name};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}
