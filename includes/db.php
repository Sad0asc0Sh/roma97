<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

require_once __DIR__ . '/../config.php';

function getDb(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    
    // Set strict SQL mode for data integrity
    $pdo->exec("SET sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");

    return $pdo;
}

/**
 * Runtime schema initialisation has been removed from the request path.
 * Tables are created via setup.php (or schema.sql) at install time.
 * These functions are kept as no-ops for backward compatibility with
 * any code that still calls them.
 */
function initializeDatabase(): bool
{
    return false;
}

function initializeCmsTables(): void
{
    // Schema is created at install time via setup.php / schema.sql
}

function initializeParentTables(): void
{
    // Schema is created at install time via setup.php / schema.sql
}

function initializeChildrenTable(): void
{
    // Schema is created at install time via setup.php / schema.sql
}

function initializeAttendanceTable(): void
{
    // Schema is created at install time via setup.php / schema.sql
}

function initializeEventTables(): void
{
    // Schema is created at install time via setup.php / schema.sql
}

function initializeTeachersTables(): void
{
    // Schema is created at install time via setup.php / schema.sql
}

function initializeFinancialTables(): void
{
    // Schema is created at install time via setup.php / schema.sql
}

function initializeMessagingTable(): void
{
    // Schema is created at install time via setup.php / schema.sql
}
