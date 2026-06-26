<?php
declare(strict_types=1);

/**
 * ROMA — Database Setup / Migration Script
 *
 * Run this ONCE after deploying to create all tables and seed default settings.
 * Usage (CLI):   php setup.php
 * Usage (web):   Visit setup.php in the browser, then DELETE it.
 *
 * This script executes schema.sql and optionally creates the default admin.
 */

define('ROOMA_APP', true);

require_once __DIR__ . '/config.php';

$isCli = php_sapi_name() === 'cli';

function out(string $msg): void
{
    global $isCli;
    if ($isCli) {
        echo $msg . "\n";
    } else {
        echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . "<br>\n";
    }
}

function setupHeader(): void
{
    global $isCli;
    if ($isCli) {
        return;
    }
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
        . '<title>ROMA Setup</title>'
        . '<style>body{font-family:sans-serif;max-width:700px;margin:40px auto;padding:0 20px;'
        . 'direction:ltr;text-align:left}h1{color:#333}.ok{color:green}.err{color:red}</style>'
        . '</head><body><h1>ROMA Database Setup</h1><pre>';
}

function setupFooter(): void
{
    global $isCli;
    if ($isCli) {
        return;
    }
    echo '</pre></body></html>';
}

setupHeader();

try {
    out('Connecting to database...');
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    out('<span class="ok">✓ Connected to ' . DB_NAME . '</span>');

    // Execute schema.sql
    $schemaFile = __DIR__ . '/schema.sql';
    if (!is_file($schemaFile)) {
        throw new RuntimeException('schema.sql not found at ' . $schemaFile);
    }

    $sql = file_get_contents($schemaFile);
    if ($sql === false) {
        throw new RuntimeException('Could not read schema.sql');
    }

    // Split on DELIMITER blocks manually since PDO doesn't understand DELIMITER
    $delimiterBlock = '';
    $mainSql = $sql;

    if (preg_match('/DELIMITER\s+\/\/(.*?)DELIMITER\s*;/s', $sql, $m)) {
        $delimiterBlock = $m[0];
        // Remove the DELIMITER block from main SQL
        $mainSql = str_replace($delimiterBlock, '', $sql);
    }

    // Run the main SQL (CREATE TABLE IF NOT EXISTS + settings seed)
    out('Creating tables...');

    // Simple statement splitter (split on ";" followed by newline)
    $statements = array_filter(array_map('trim', preg_split('/;\s*[\r\n]+/', $mainSql)));
    $executed = 0;
    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '' || str_starts_with($stmt, '--')) {
            continue;
        }
        if (str_starts_with($stmt, 'SET ')) {
            $pdo->exec($stmt);
            continue;
        }
        $pdo->exec($stmt . ';');
        $executed++;
    }
    out('<span class="ok">✓ ' . $executed . ' statements executed</span>');

    // Handle the messages index migration (BUG-H05) in PHP for reliability
    out('Running messages index migration...');
    $idxCheck = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE table_schema = DATABASE() AND table_name = 'messages'
           AND index_name = 'idx_messages_parent_read'"
    );
    $idxCheck->execute();
    if ((int) $idxCheck->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE messages ADD INDEX idx_messages_parent_read (parent_id, is_read)');
        out('<span class="ok">✓ Added index idx_messages_parent_read</span>');
    } else {
        out('<span class="ok">✓ Index idx_messages_parent_read already exists</span>');
    }

    // Check if admin exists; create default admin if not
    $countStmt = $pdo->query('SELECT COUNT(*) FROM admins');
    $adminCount = (int) $countStmt->fetchColumn();

    if ($adminCount === 0) {
        out('Creating default admin account (admin / admin123)...');
        $defaultUser = 'admin';
        $defaultPass = 'admin123';
        $insert = $pdo->prepare(
            'INSERT INTO admins (username, password) VALUES (:u, :p)'
        );
        $insert->execute([
            ':u' => $defaultUser,
            ':p' => password_hash($defaultPass, PASSWORD_DEFAULT),
        ]);
        out('<span class="ok">✓ Default admin created. Username: admin | Password: admin123</span>');
        out('<span class="err">⚠️ Change this password immediately after first login!</span>');
    } else {
        out('<span class="ok">✓ Admin account already exists (' . $adminCount . ' found)</span>');
    }

    // Ensure required upload directories exist
    $uploadDirs = [
        __DIR__ . '/assets/uploads',
        __DIR__ . '/assets/uploads/avatars',
        __DIR__ . '/assets/uploads/children',
        __DIR__ . '/assets/uploads/certificates',
    ];
    foreach ($uploadDirs as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Options -Indexes\n<FilesMatch \"\\.php$\">\n  Deny from all\n</FilesMatch>\n");
        }
        $indexHtml = $dir . '/index.html';
        if (!file_exists($indexHtml)) {
            @file_put_contents($indexHtml, '');
        }
    }
    out('<span class="ok">✓ Upload directories secured</span>');

    out('');
    out('<span class="ok">═══════════════════════════════════════</span>');
    out('<span class="ok">  Setup complete! ROMA is ready.</span>');
    out('<span class="ok">═══════════════════════════════════════</span>');
    if (!$isCli) {
        out('<span class="err">⚠️ IMPORTANT: Delete setup.php from the server now!</span>');
        out('<a href="index.php">← Go to website</a>');
    }
} catch (Throwable $e) {
    out('<span class="err">✗ Setup failed: ' . $e->getMessage() . '</span>');
    if ($isCli) {
        exit(1);
    }
}

setupFooter();