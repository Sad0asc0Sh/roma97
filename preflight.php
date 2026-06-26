<?php
declare(strict_types=1);

/**
 * Rooma production pre-flight health check.
 *
 * Access with: /preflight.php?token=rooma_check_2026
 * Keep the token private. This page avoids printing secrets such as database
 * credentials and only reports deployment readiness at a high level.
 */

const PREFLIGHT_TOKEN = 'rooma_check_2026';
const STATUS_PASS = 'pass';
const STATUS_WARN = 'warn';
const STATUS_FAIL = 'fail';
const STATUS_INFO = 'info';

if (!isset($_GET['token']) || !is_string($_GET['token']) || !hash_equals(PREFLIGHT_TOKEN, $_GET['token'])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Not found.';
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

$baseDir = __DIR__;
$sections = [];

/**
 * Escape output for safe HTML rendering.
 */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Add a single check result to the report.
 *
 * Critical failures determine the final FAIL summary. Warnings and info items
 * keep the page useful without treating manual follow-ups as broken deploys.
 *
 * @param array<int, string> $details
 */
function addCheck(
    array &$sections,
    string $section,
    string $title,
    string $status,
    string $message,
    array $details = [],
    bool $critical = false
): void {
    if (!isset($sections[$section])) {
        $sections[$section] = [];
    }

    $sections[$section][] = [
        'title' => $title,
        'status' => $status,
        'message' => $message,
        'details' => $details,
        'critical' => $critical,
    ];
}

function isCurrentRequestHttps(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function pathFromRoot(string $relativePath): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
}

function fileContains(string $relativePath, string $needle): bool
{
    $path = pathFromRoot($relativePath);

    if (!is_file($path) || !is_readable($path)) {
        return false;
    }

    $contents = file_get_contents($path);

    return is_string($contents) && str_contains($contents, $needle);
}

function hasDbConfig(): bool
{
    foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $constantName) {
        if (!defined($constantName)) {
            return false;
        }
    }

    return true;
}

$configLoaded = false;
$pdo = null;
$existingTables = [];

// 1. PHP version
$phpVersionOk = version_compare(PHP_VERSION, '8.1.0', '>=');
addCheck(
    $sections,
    'Runtime',
    'PHP Version',
    $phpVersionOk ? STATUS_PASS : STATUS_FAIL,
    'Current version: ' . PHP_VERSION . '. Required: 8.1 or newer.',
    [],
    !$phpVersionOk
);

// 2. Required extensions
$requiredExtensions = ['PDO', 'pdo_mysql', 'json', 'mbstring', 'fileinfo', 'gd', 'openssl'];
foreach ($requiredExtensions as $extension) {
    $loaded = extension_loaded($extension);
    addCheck(
        $sections,
        'Runtime',
        $extension . ' Extension',
        $loaded ? STATUS_PASS : STATUS_FAIL,
        $loaded ? 'Loaded.' : 'Missing. Enable this PHP extension before launch.',
        [],
        !$loaded
    );
}

// 3. Configuration files and constants
$configPath = $baseDir . DIRECTORY_SEPARATOR . 'config.php';
if (!is_file($configPath)) {
    addCheck($sections, 'Configuration', 'config.php', STATUS_FAIL, 'config.php is missing from the project root.', [], true);
} elseif (!is_readable($configPath)) {
    addCheck($sections, 'Configuration', 'config.php', STATUS_FAIL, 'config.php exists but is not readable by PHP.', [], true);
} else {
    addCheck($sections, 'Configuration', 'config.php', STATUS_PASS, 'config.php exists and is readable.');

    ob_start();
    try {
        include $configPath;
        $configLoaded = true;
    } catch (Throwable $exception) {
        addCheck(
            $sections,
            'Configuration',
            'Load config.php',
            STATUS_FAIL,
            'config.php could not be loaded. Review syntax and required constants.',
            [],
            true
        );
    } finally {
        $configOutput = ob_get_clean();
    }

    if ($configLoaded) {
        addCheck($sections, 'Configuration', 'Load config.php', STATUS_PASS, 'config.php loaded without exposing values.');

        if (!empty($configOutput)) {
            addCheck(
                $sections,
                'Configuration',
                'Config Output',
                STATUS_WARN,
                'config.php produced output while loading. Config files should stay silent in production.'
            );
        }
    }
}

if ($configLoaded) {
    $requiredConstants = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'SITE_URL', 'DEVELOPMENT_MODE', 'ERROR_LOG_PATH'];
    foreach ($requiredConstants as $constantName) {
        $defined = defined($constantName);
        addCheck(
            $sections,
            'Configuration',
            $constantName,
            $defined ? STATUS_PASS : STATUS_FAIL,
            $defined ? 'Defined.' : 'Missing required constant.',
            [],
            !$defined
        );
    }

    if (defined('DEVELOPMENT_MODE')) {
        $developmentMode = constant('DEVELOPMENT_MODE');
        addCheck(
            $sections,
            'Configuration',
            'DEVELOPMENT_MODE Value',
            $developmentMode === false ? STATUS_PASS : STATUS_WARN,
            $developmentMode === false
                ? 'Production-safe value detected: false.'
                : 'DEVELOPMENT_MODE should be false in production.'
        );
    }

    if (defined('FORCE_HTTPS')) {
        $forceHttps = constant('FORCE_HTTPS');
        addCheck(
            $sections,
            'Configuration',
            'FORCE_HTTPS Value',
            $forceHttps === true ? STATUS_PASS : STATUS_WARN,
            $forceHttps === true
                ? 'FORCE_HTTPS is enabled.'
                : 'FORCE_HTTPS is disabled. Enable it on production servers with SSL/TLS.'
        );
    } else {
        addCheck($sections, 'Configuration', 'FORCE_HTTPS', STATUS_WARN, 'FORCE_HTTPS is not defined. Define it explicitly for production.');
    }

    addCheck(
        $sections,
        'Configuration',
        'Current Protocol',
        isCurrentRequestHttps() ? STATUS_PASS : STATUS_WARN,
        isCurrentRequestHttps()
            ? 'This pre-flight request is using HTTPS.'
            : 'This pre-flight request is using HTTP. HTTPS enforcement must be verified on the production hostname.'
    );
}

// 4. Database connection and tables
if (!extension_loaded('PDO') || !extension_loaded('pdo_mysql')) {
    addCheck($sections, 'Database', 'PDO Connection', STATUS_FAIL, 'Database check skipped because PDO or pdo_mysql is missing.', [], true);
} elseif (!$configLoaded || !hasDbConfig()) {
    addCheck($sections, 'Database', 'PDO Connection', STATUS_FAIL, 'Database check skipped because database constants are missing.', [], true);
} else {
    try {
        $dsn = 'mysql:host=' . (string) DB_HOST . ';dbname=' . (string) DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, (string) DB_USER, (string) DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->query('SELECT 1');
        addCheck($sections, 'Database', 'PDO Connection', STATUS_PASS, 'Connected successfully using the configured database settings.');
    } catch (Throwable $exception) {
        $errorCode = $exception instanceof PDOException ? (string) $exception->getCode() : 'unknown';
        addCheck(
            $sections,
            'Database',
            'PDO Connection',
            STATUS_FAIL,
            'Could not connect to the database. Review MySQL availability and configured credentials.',
            ['PDO error code: ' . $errorCode],
            true
        );
        $pdo = null;
    }
}

$expectedTables = [
    'admins',
    'settings',
    'slides',
    'news',
    'pages',
    'parents',
    'children',
    'classrooms',
    'child_classroom',
    'teachers',
    'daily_reports',
    'attendance',
    'events',
    'messages',
    'salary_payments',
    'tuition_payments',
    'login_tokens',
];

if ($pdo instanceof PDO) {
    try {
        $tableStatement = $pdo->query('SHOW TABLES');
        while (($tableName = $tableStatement->fetchColumn()) !== false) {
            $existingTables[] = (string) $tableName;
        }

        $missingTables = array_values(array_diff($expectedTables, $existingTables));
        addCheck(
            $sections,
            'Database',
            'Expected Tables',
            $missingTables === [] ? STATUS_PASS : STATUS_FAIL,
            $missingTables === []
                ? 'All expected database tables are present.'
                : count($missingTables) . ' expected table(s) are missing.',
            $missingTables,
            $missingTables !== []
        );
    } catch (Throwable $exception) {
        addCheck($sections, 'Database', 'Expected Tables', STATUS_FAIL, 'Could not inspect database tables.', [], true);
    }

    if (in_array('admins', $existingTables, true)) {
        try {
            $adminCount = (int) $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
            $defaultAdminStatement = $pdo->prepare('SELECT COUNT(*) FROM admins WHERE username = :username');
            $defaultAdminStatement->execute([':username' => 'admin']);
            $defaultAdminCount = (int) $defaultAdminStatement->fetchColumn();

            if ($adminCount === 0) {
                addCheck($sections, 'Database', 'Admin Accounts', STATUS_FAIL, 'No admin accounts exist. Admin login will not be possible.', [], true);
            } elseif ($adminCount === 1 && $defaultAdminCount === 1) {
                addCheck(
                    $sections,
                    'Database',
                    'Default Admin Check',
                    STATUS_WARN,
                    'Only one admin account exists and its username is "admin". Change the default password and create a named admin before production.'
                );
            } else {
                addCheck($sections, 'Database', 'Default Admin Check', STATUS_PASS, 'Admin accounts do not match the default-only pattern.');
            }
        } catch (Throwable $exception) {
            addCheck($sections, 'Database', 'Default Admin Check', STATUS_WARN, 'Could not inspect admin account status.');
        }
    }

    if (in_array('parents', $existingTables, true)) {
        try {
            $parentCountStatement = $pdo->query("SELECT COUNT(*) FROM parents WHERE status IN ('active', 'pending')");
            $parentCount = (int) $parentCountStatement->fetchColumn();
            addCheck(
                $sections,
                'Database',
                'Parents',
                $parentCount > 0 ? STATUS_PASS : STATUS_WARN,
                $parentCount . ' active or pending parent account(s) found.'
            );
        } catch (Throwable $exception) {
            addCheck($sections, 'Database', 'Parents', STATUS_WARN, 'Could not count active or pending parent accounts.');
        }
    }

    if (in_array('children', $existingTables, true)) {
        try {
            $childCount = (int) $pdo->query('SELECT COUNT(*) FROM children')->fetchColumn();
            addCheck(
                $sections,
                'Database',
                'Children',
                $childCount > 0 ? STATUS_PASS : STATUS_WARN,
                $childCount . ' child record(s) found.'
            );
        } catch (Throwable $exception) {
            addCheck($sections, 'Database', 'Children', STATUS_WARN, 'Could not count child records.');
        }
    }
}

// 5. Directory permissions
$writableDirectories = [
    'assets/uploads/',
    'assets/uploads/avatars/',
    'assets/uploads/children/',
    'assets/uploads/certificates/',
    'logs/',
];

foreach ($writableDirectories as $relativePath) {
    $path = pathFromRoot($relativePath);
    if (!is_dir($path)) {
        addCheck($sections, 'Files & Permissions', $relativePath, STATUS_FAIL, 'Directory is missing.', [], true);
        continue;
    }

    $writable = is_writable($path);
    addCheck(
        $sections,
        'Files & Permissions',
        $relativePath,
        $writable ? STATUS_PASS : STATUS_FAIL,
        $writable ? 'Directory exists and is writable by PHP.' : 'Directory exists but is not writable by PHP.',
        [],
        !$writable
    );
}

// 6. Security measures
$htaccessFiles = [
    '.htaccess',
    'assets/uploads/.htaccess',
    'includes/.htaccess',
    'templates/.htaccess',
];

foreach ($htaccessFiles as $relativePath) {
    $exists = is_file(pathFromRoot($relativePath));
    addCheck(
        $sections,
        'Security',
        $relativePath,
        $exists ? STATUS_PASS : STATUS_WARN,
        $exists ? '.htaccess file is present.' : '.htaccess file is missing. Root rewrite rules may still block access, but verify this manually.'
    );
}

addCheck(
    $sections,
    'Security',
    'config.php Direct Access',
    STATUS_INFO,
    'Manual verification required: request /config.php in a browser and confirm the server blocks it. The script does not make external self-requests.'
);

addCheck(
    $sections,
    'Security',
    'ROOMA_APP Constant',
    defined('ROOMA_APP') ? STATUS_PASS : STATUS_FAIL,
    defined('ROOMA_APP')
        ? 'ROOMA_APP is defined, so include-file guards can run.'
        : 'ROOMA_APP is not defined after loading config.php. Include-file guards may not work.',
    [],
    !defined('ROOMA_APP')
);

$securityHeaderFiles = ['templates/header.php', 'admin/header.php', 'parent/header.php', 'teacher/header.php'];
$missingHeaderCalls = [];
foreach ($securityHeaderFiles as $relativePath) {
    if (!fileContains($relativePath, 'sendSecurityHeaders();')) {
        $missingHeaderCalls[] = $relativePath;
    }
}
addCheck(
    $sections,
    'Security',
    'Security Headers',
    $missingHeaderCalls === [] ? STATUS_INFO : STATUS_WARN,
    $missingHeaderCalls === []
        ? 'Templates call sendSecurityHeaders(); verify the actual response headers in the browser or hosting control panel.'
        : 'Some portal templates do not call sendSecurityHeaders(); verify header coverage.',
    $missingHeaderCalls
);

// 10. Mail/notifications
addCheck(
    $sections,
    'Notifications',
    'Email Verification',
    STATUS_INFO,
    'Skipped. Mail delivery is not configured in this app; email verification currently depends on manual/admin workflow.'
);

// 11. File integrity
$criticalFiles = [
    'index.php',
    'login.php',
    'logout.php',
    'register.php',
    'news.php',
    'page.php',
    'admin/attendance.php',
    'admin/child-action.php',
    'admin/child-detail.php',
    'admin/children.php',
    'admin/classrooms.php',
    'admin/events.php',
    'admin/footer.php',
    'admin/header.php',
    'admin/index.php',
    'admin/login.php',
    'admin/logout.php',
    'admin/messages.php',
    'admin/news.php',
    'admin/pages.php',
    'admin/salary.php',
    'admin/settings.php',
    'admin/slides.php',
    'admin/teachers.php',
    'admin/tuition.php',
    'parent/add-child.php',
    'parent/attendance.php',
    'parent/child-detail.php',
    'parent/children.php',
    'parent/footer.php',
    'parent/header.php',
    'parent/index.php',
    'parent/messages.php',
    'parent/payments.php',
    'parent/profile.php',
    'teacher/auto-login.php',
    'teacher/footer.php',
    'teacher/header.php',
    'teacher/index.php',
    'teacher/login.php',
    'teacher/logout.php',
    'teacher/messages.php',
    'teacher/report.php',
    'includes/admin_menu.php',
    'includes/auth.php',
    'includes/csrf.php',
    'includes/db.php',
    'includes/error_handler.php',
    'includes/functions.php',
    'includes/parent_children_helpers.php',
    'templates/footer.php',
    'templates/header.php',
];

$missingFiles = [];
foreach ($criticalFiles as $relativePath) {
    if (!is_file(pathFromRoot($relativePath))) {
        $missingFiles[] = $relativePath;
    }
}

addCheck(
    $sections,
    'Files & Permissions',
    'Critical PHP Files',
    $missingFiles === [] ? STATUS_PASS : STATUS_FAIL,
    $missingFiles === []
        ? 'All listed critical PHP files are present.'
        : count($missingFiles) . ' critical PHP file(s) are missing.',
    $missingFiles,
    $missingFiles !== []
);

// 12. Summary
$counts = [STATUS_PASS => 0, STATUS_WARN => 0, STATUS_FAIL => 0, STATUS_INFO => 0];
$criticalFailures = 0;
foreach ($sections as $checks) {
    foreach ($checks as $check) {
        $counts[$check['status']]++;
        if ($check['status'] === STATUS_FAIL && $check['critical']) {
            $criticalFailures++;
        }
    }
}

$overallStatus = STATUS_PASS;
$overallLabel = 'PASS';
$overallMessage = 'All critical checks passed.';
if ($criticalFailures > 0) {
    $overallStatus = STATUS_FAIL;
    $overallLabel = 'FAIL';
    $overallMessage = $criticalFailures . ' critical issue(s) must be fixed before production launch.';
} elseif ($counts[STATUS_WARN] > 0) {
    $overallStatus = STATUS_WARN;
    $overallLabel = 'WARN';
    $overallMessage = 'Critical checks passed, but warnings need review before launch.';
}

$generatedAt = date('Y-m-d H:i:s T');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rooma Pre-flight Health Check</title>
    <style>
        :root {
            --primary: #FF6B6B;
            --secondary: #FFD166;
            --accent: #06D6A0;
            --neutral-dark: #2D3436;
            --neutral-light: #F8F9FA;
            --white: #FFFFFF;
            --danger: #E63946;
            --warning: #F59E0B;
            --success: #16A34A;
            --info: #457B9D;
            --muted: #64748b;
            --border: #E5E7EB;
            --font-base: 'Nunito', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            --shadow-sm: 0 2px 8px rgba(45, 52, 54, 0.08);
            --shadow-md: 0 4px 16px rgba(45, 52, 54, 0.12);
            --radius-sm: 8px;
            --radius-md: 12px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: var(--font-base);
            color: var(--neutral-dark);
            background:
                radial-gradient(circle at top left, rgba(255, 209, 102, 0.24), transparent 34rem),
                linear-gradient(180deg, #fff, var(--neutral-light));
            line-height: 1.6;
        }

        .page {
            width: min(1180px, calc(100% - 32px));
            margin: 0 auto;
            padding: 32px 0 48px;
        }

        .hero {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 24px;
            align-items: center;
            padding: 28px;
            background: rgba(255, 255, 255, 0.92);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-md);
        }

        h1,
        h2,
        h3,
        p {
            margin-top: 0;
        }

        h1 {
            margin-bottom: 8px;
            font-size: clamp(2rem, 4vw, 3rem);
            line-height: 1.1;
        }

        .subtitle {
            max-width: 760px;
            margin-bottom: 0;
            color: var(--muted);
        }

        .overall {
            min-width: 170px;
            padding: 18px;
            text-align: center;
            border-radius: var(--radius-md);
            color: var(--white);
            background: var(--success);
        }

        .overall.warn {
            background: var(--warning);
        }

        .overall.fail {
            background: var(--danger);
        }

        .overall-label {
            display: block;
            font-size: 2rem;
            font-weight: 800;
            line-height: 1;
        }

        .overall-text {
            display: block;
            margin-top: 8px;
            font-size: 0.92rem;
        }

        .meta {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin: 18px 0 0;
            color: var(--muted);
            font-size: 0.95rem;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin: 18px 0 28px;
        }

        .stat {
            padding: 16px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            background: var(--white);
            box-shadow: var(--shadow-sm);
        }

        .stat strong {
            display: block;
            font-size: 1.75rem;
            line-height: 1.1;
        }

        .stat span {
            color: var(--muted);
            font-size: 0.9rem;
        }

        .sections {
            display: grid;
            gap: 18px;
        }

        .section {
            overflow: hidden;
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            background: var(--white);
            box-shadow: var(--shadow-sm);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: center;
            padding: 18px 20px;
            border-bottom: 1px solid var(--border);
            background: #fff;
        }

        .section-header h2 {
            margin: 0;
            font-size: 1.2rem;
        }

        .section-count {
            color: var(--muted);
            font-size: 0.9rem;
        }

        .check {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 14px;
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
        }

        .check:last-child {
            border-bottom: 0;
        }

        .badge {
            display: inline-flex;
            min-width: 70px;
            height: 28px;
            align-items: center;
            justify-content: center;
            padding: 0 10px;
            border-radius: 999px;
            color: var(--white);
            font-size: 0.78rem;
            font-weight: 800;
            letter-spacing: 0;
            text-transform: uppercase;
        }

        .badge.pass {
            background: var(--success);
        }

        .badge.warn {
            background: var(--warning);
        }

        .badge.fail {
            background: var(--danger);
        }

        .badge.info {
            background: var(--info);
        }

        .check-title {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            margin-bottom: 4px;
            font-weight: 800;
        }

        .critical {
            color: var(--danger);
            font-size: 0.82rem;
            font-weight: 800;
        }

        .message {
            margin: 0;
            color: var(--muted);
        }

        .details {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 10px 0 0;
            padding: 0;
            list-style: none;
        }

        .details li {
            max-width: 100%;
            padding: 6px 9px;
            overflow-wrap: anywhere;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            background: var(--neutral-light);
            color: var(--neutral-dark);
            font-family: ui-monospace, SFMono-Regular, Consolas, 'Liberation Mono', monospace;
            font-size: 0.85rem;
        }

        .footer-note {
            margin: 24px 0 0;
            color: var(--muted);
            font-size: 0.92rem;
            text-align: center;
        }

        @media (max-width: 780px) {
            .hero {
                grid-template-columns: 1fr;
                padding: 22px;
            }

            .overall {
                width: 100%;
            }

            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .check {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 460px) {
            .page {
                width: min(100% - 20px, 1180px);
                padding-top: 18px;
            }

            .stats {
                grid-template-columns: 1fr;
            }

            .section-header {
                align-items: flex-start;
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <main class="page">
        <section class="hero" aria-labelledby="page-title">
            <div>
                <h1 id="page-title">Rooma Pre-flight Health Check</h1>
                <p class="subtitle"><?= e($overallMessage) ?> This report avoids exposing secrets and should still be reviewed from the production hostname before launch.</p>
                <div class="meta">
                    <span>Generated: <?= e($generatedAt) ?></span>
                    <span>PHP: <?= e(PHP_VERSION) ?></span>
                </div>
            </div>
            <div class="overall <?= e($overallStatus) ?>">
                <span class="overall-label"><?= e($overallLabel) ?></span>
                <span class="overall-text"><?= e($overallMessage) ?></span>
            </div>
        </section>

        <section class="stats" aria-label="Check counts">
            <div class="stat"><strong><?= (int) $counts[STATUS_PASS] ?></strong><span>Passed</span></div>
            <div class="stat"><strong><?= (int) $counts[STATUS_WARN] ?></strong><span>Warnings</span></div>
            <div class="stat"><strong><?= (int) $counts[STATUS_FAIL] ?></strong><span>Failures</span></div>
            <div class="stat"><strong><?= (int) $counts[STATUS_INFO] ?></strong><span>Manual notes</span></div>
        </section>

        <div class="sections">
            <?php foreach ($sections as $sectionName => $checks): ?>
                <section class="section" aria-labelledby="<?= e('section-' . preg_replace('/[^a-z0-9]+/i', '-', strtolower((string) $sectionName))) ?>">
                    <div class="section-header">
                        <h2 id="<?= e('section-' . preg_replace('/[^a-z0-9]+/i', '-', strtolower((string) $sectionName))) ?>"><?= e($sectionName) ?></h2>
                        <span class="section-count"><?= count($checks) ?> check(s)</span>
                    </div>
                    <?php foreach ($checks as $check): ?>
                        <article class="check">
                            <div><span class="badge <?= e($check['status']) ?>"><?= e($check['status']) ?></span></div>
                            <div>
                                <div class="check-title">
                                    <span><?= e($check['title']) ?></span>
                                    <?php if ($check['critical']): ?>
                                        <span class="critical">Critical</span>
                                    <?php endif; ?>
                                </div>
                                <p class="message"><?= e($check['message']) ?></p>
                                <?php if ($check['details'] !== []): ?>
                                    <ul class="details">
                                        <?php foreach ($check['details'] as $detail): ?>
                                            <li><?= e($detail) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </section>
            <?php endforeach; ?>
        </div>

        <p class="footer-note">Keep this URL private. Rotate the token in preflight.php if it has been shared.</p>
    </main>
</body>
</html>
