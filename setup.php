<?php
declare(strict_types=1);

/**
 * ROMA — Database Setup / Hardened Installer
 *
 * Run this ONCE after deploying to create all tables and an admin account.
 * After successful installation, an `install.lock` file is created which
 * blocks any future execution of this script.
 *
 * Usage (CLI):   php setup.php
 * Usage (web):   Visit setup.php in the browser, then it self-locks.
 *
 * Security:
 *   - No hardcoded default password. The admin password is set during installation.
 *   - After success, `install.lock` is written, preventing re-execution.
 *   - If `install.lock` exists, the script aborts immediately.
 */

define('ROOMA_APP', true);

require_once __DIR__ . '/config.php';

$isCli = php_sapi_name() === 'cli';
$lockFile = __DIR__ . '/install.lock';

/**
 * Check if installation is already locked.
 */
function isLocked(string $lockFile): bool
{
    return is_file($lockFile);
}

/**
 * Write the install.lock file.
 */
function writeLock(string $lockFile): bool
{
    $content = 'ROMA installation locked at ' . date('Y-m-d H:i:s') . "\n"
        . 'Remove this file only to re-run setup.' . "\n";
    return @file_put_contents($lockFile, $content) !== false;
}

/**
 * Output helper.
 */
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
        . 'direction:ltr;text-align:left}h1{color:#333}.ok{color:green}.err{color:red}'
        . '.warn{color:orange}form{margin:20px 0}label{display:block;margin:8px 0 4px;font-weight:bold}'
        . 'input{width:100%;padding:8px;margin-bottom:12px;box-sizing:border-box}'
        . 'button{padding:10px 20px;background:#007bff;color:#fff;border:none;cursor:pointer}'
        . '.info{background:#f8f9fa;padding:12px;border-radius:4px;margin:12px 0}</style>'
        . '</head><body><h1>ROMA Database Setup</h1>';
}

function setupFooter(): void
{
    global $isCli;
    if ($isCli) {
        return;
    }
    echo '</body></html>';
}

// ─── Gate 1: Block if already installed ─────────────────────────────────────
if (isLocked($lockFile)) {
    http_response_code(403);
    setupHeader();
    out('<span class="err">✗ Installation is locked (install.lock exists).</span>');
    out('<span class="warn">ROMA is already installed. Delete install.lock only if you need to re-run setup.</span>');
    out('');
    out('<a href="index.php">← Go to website</a>');
    setupFooter();
    exit;
}

// ─── Determine if this is the install step (POST) or the form display (GET) ──
$installStep = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($isCli || !empty($_POST['install']));

$adminUsername = '';
$adminPassword = '';
$adminPasswordConfirm = '';
$installError = '';

if ($installStep) {
    if ($isCli) {
        // CLI mode: prompt for credentials interactively
        out('Enter admin username [admin]: ');
        $adminUsername = trim((string) fgets(STDIN));
        if ($adminUsername === '') {
            $adminUsername = 'admin';
        }
        out('Enter admin password: ');
        $adminPassword = trim((string) fgets(STDIN));
        out('Confirm admin password: ');
        $adminPasswordConfirm = trim((string) fgets(STDIN));
    } else {
        $adminUsername = trim((string) ($_POST['admin_username'] ?? ''));
        $adminPassword = (string) ($_POST['admin_password'] ?? '');
        $adminPasswordConfirm = (string) ($_POST['admin_password_confirm'] ?? '');
    }

    // Validate admin credentials
    if ($adminUsername === '' || strlen($adminUsername) > 50 || !preg_match('/\A[A-Za-z0-9_]{3,50}\z/', $adminUsername)) {
        $installError = 'Username must be 3-50 characters (letters, numbers, underscore only).';
    } elseif ($adminPassword === '' || strlen($adminPassword) < 8) {
        $installError = 'Password must be at least 8 characters.';
    } elseif (!preg_match('/[A-Za-z]/', $adminPassword) || !preg_match('/[0-9]/', $adminPassword)) {
        $installError = 'Password must contain at least one letter and one number.';
    } elseif ($adminPassword !== $adminPasswordConfirm) {
        $installError = 'Passwords do not match.';
    }
}

// ─── If no error and install step, run the actual installation ──────────────
if ($installStep && $installError === '') {
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

        // Remove DELIMITER block (handled separately in PHP)
        $mainSql = $sql;
        if (preg_match('/DELIMITER\s+\/\/(.*?)DELIMITER\s*;/s', $sql, $m)) {
            $mainSql = str_replace($m[0], '', $sql);
        }

        out('Creating tables...');
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

        // Messages index migration (BUG-H05)
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

        // Create admin account with user-supplied credentials
        out('Creating admin account...');
        $countStmt = $pdo->query('SELECT COUNT(*) FROM admins');
        $adminCount = (int) $countStmt->fetchColumn();

        if ($adminCount === 0) {
            $insert = $pdo->prepare(
                'INSERT INTO admins (username, password) VALUES (:u, :p)'
            );
            $insert->execute([
                ':u' => $adminUsername,
                ':p' => password_hash($adminPassword, PASSWORD_DEFAULT),
            ]);
            out('<span class="ok">✓ Admin account created: ' . htmlspecialchars($adminUsername) . '</span>');
        } else {
            out('<span class="ok">✓ Admin account already exists (' . $adminCount . ' found) — skipping creation</span>');
        }

        // Ensure upload directories exist and are secured
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

        // ─── Write install.lock ────────────────────────────────────────────
        if (writeLock($lockFile)) {
            out('<span class="ok">✓ install.lock created — setup is now locked</span>');
        } else {
            out('<span class="err">⚠️ Could not create install.lock. Please manually create the file to prevent re-installation.</span>');
        }

        out('');
        out('<span class="ok">═══════════════════════════════════════</span>');
        out('<span class="ok">  Setup complete! ROMA is ready.</span>');
        out('<span class="ok">═══════════════════════════════════════</span>');
        out('');
        out('<span class="warn">⚠️ IMPORTANT: Delete setup.php from the server for maximum security.</span>');
        out('<span class="warn">⚠️ The install.lock file blocks re-running this script, but deleting setup.php is defense-in-depth.</span>');
        if (!$isCli) {
            out('<a href="index.php">← Go to website</a>');
        }
    } catch (Throwable $e) {
        out('<span class="err">✗ Setup failed: ' . $e->getMessage() . '</span>');
        if ($isCli) {
            exit(1);
        }
    }

    setupFooter();
    exit;
}

// ─── Display the installation form (GET) ─────────────────────────────────────
setupHeader();

if ($installError !== '') {
    out('<span class="err">' . htmlspecialchars($installError) . '</span>');
    out('');
}

if ($isCli) {
    // Re-prompt in CLI mode if there was an error
    out('Please re-run setup with valid credentials.');
    exit(1);
}
?>

<div class="info">
    <p>Welcome to the ROMA installer. This script will:</p>
    <ol>
        <li>Create all database tables</li>
        <li>Add performance indexes</li>
        <li>Create an admin account with your chosen credentials</li>
        <li>Secure upload directories</li>
        <li>Lock the installer (<code>install.lock</code>)</li>
    </ol>
    <p><strong>After installation, delete <code>setup.php</code> from the server.</strong></p>
</div>

<form method="post" action="">
    <input type="hidden" name="install" value="1">

    <label for="admin_username">Admin Username</label>
    <input type="text" id="admin_username" name="admin_username"
           value="<?= htmlspecialchars($adminUsername, ENT_QUOTES) ?>"
           placeholder="admin" maxlength="50" required
           pattern="[A-Za-z0-9_]{3,50}">

    <label for="admin_password">Admin Password</label>
    <input type="password" id="admin_password" name="admin_password"
           maxlength="255" required minlength="8"
           placeholder="At least 8 characters, with a letter and a number">

    <label for="admin_password_confirm">Confirm Password</label>
    <input type="password" id="admin_password_confirm" name="admin_password_confirm"
           maxlength="255" required minlength="8">

    <button type="submit">Run Installation</button>
</form>

<?php
setupFooter();