<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

require_once __DIR__ . '/functions.php';

function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');

    // Use the isHttps() function for consistency
    $isSecure = isHttps();
    
    // Also set session.cookie_secure in ini
    ini_set('session.cookie_secure', $isSecure ? '1' : '0');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

startSecureSession();

/**
 * Brute-force throttling, persisted in the database and keyed by client IP
 * (and optionally a per-account identifier such as an email/username).
 *
 * This intentionally does NOT rely on the visitor's session/cookies: a
 * session-based counter can be reset at will by the attacker simply by
 * dropping the session cookie between attempts, which defeats the lockout
 * entirely. Persisting the counter server-side closes that gap.
 */
const BRUTE_FORCE_MAX_ATTEMPTS = 5;
const BRUTE_FORCE_LOCKOUT_SECONDS = 900; // 15 minutes

/**
 * Create the login_throttle table (idempotent, cached per-request).
 */
function initializeLoginThrottleTable(): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    require_once __DIR__ . '/db.php';
    $pdo = getDb();

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS login_throttle (
    id INT AUTO_INCREMENT PRIMARY KEY,
    context VARCHAR(50) NOT NULL,
    key_type ENUM('ip','identifier') NOT NULL,
    key_value VARCHAR(191) NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_throttle_key (context, key_type, key_value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $initialized = true;
}

/**
 * The client's IP address, truncated to fit the storage column.
 */
function bruteForceClientIp(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

    return substr($ip, 0, 45);
}

/**
 * Build the list of [keyType, keyValue] throttle rows that apply to this attempt:
 * always the client IP, and additionally the supplied account identifier
 * (e.g. username/email) when given. Checking both means a single account can't
 * be brute-forced from many IPs, and a single IP can't brute-force many accounts.
 *
 * @return array<int, array{0:string,1:string}>
 */
function bruteForceKeys(?string $identifier): array
{
    $keys = [['ip', bruteForceClientIp()]];

    $identifier = $identifier !== null ? mb_strtolower(trim($identifier), 'UTF-8') : '';
    if ($identifier !== '') {
        $keys[] = ['identifier', substr($identifier, 0, 191)];
    }

    return $keys;
}

/**
 * Whether the given context/identifier combination is currently allowed to
 * attempt a login. Returns false if EITHER the IP or the account identifier
 * is currently locked out.
 */
function checkBruteForce(string $context = 'login', ?string $identifier = null): bool
{
    try {
        initializeLoginThrottleTable();
        $pdo = getDb();

        $stmt = $pdo->prepare(
            'SELECT locked_until FROM login_throttle
             WHERE context = :context AND key_type = :type AND key_value = :value
             LIMIT 1'
        );
        $clear = $pdo->prepare(
            'DELETE FROM login_throttle WHERE context = :context AND key_type = :type AND key_value = :value'
        );

        foreach (bruteForceKeys($identifier) as [$type, $value]) {
            $stmt->execute([':context' => $context, ':type' => $type, ':value' => $value]);
            $row = $stmt->fetch();

            if ($row === false || $row['locked_until'] === null) {
                continue;
            }

            $lockedUntil = new DateTimeImmutable((string) $row['locked_until']);
            if (new DateTimeImmutable() < $lockedUntil) {
                return false; // Still locked out.
            }

            // The lockout window has elapsed — clear the row so the next
            // failure starts a fresh count rather than immediately re-locking
            // (attempts would otherwise just keep climbing past the threshold).
            $clear->execute([':context' => $context, ':type' => $type, ':value' => $value]);
        }

        return true;
    } catch (Throwable $e) {
        // If the throttle store itself is unavailable, fail open rather than
        // blocking every login on an unrelated infrastructure problem — the
        // credential check that follows still protects the account.
        error_log('checkBruteForce failed: ' . $e->getMessage());
        return true;
    }
}

/**
 * Record a failed login attempt for both the client IP and (if given) the
 * account identifier, locking out whichever one crosses the threshold.
 */
function recordFailedAttempt(string $context = 'login', ?string $identifier = null): void
{
    try {
        initializeLoginThrottleTable();
        $pdo = getDb();

        $upsert = $pdo->prepare(
            'INSERT INTO login_throttle (context, key_type, key_value, attempts, locked_until)
             VALUES (:context, :type, :value, 1, NULL)
             ON DUPLICATE KEY UPDATE
                attempts = attempts + 1,
                locked_until = IF(attempts >= :max_attempts, :locked_until, locked_until)'
        );

        $lockedUntil = (new DateTimeImmutable())
            ->modify('+' . BRUTE_FORCE_LOCKOUT_SECONDS . ' seconds')
            ->format('Y-m-d H:i:s');

        foreach (bruteForceKeys($identifier) as [$type, $value]) {
            $upsert->execute([
                ':context' => $context,
                ':type' => $type,
                ':value' => $value,
                ':max_attempts' => BRUTE_FORCE_MAX_ATTEMPTS,
                ':locked_until' => $lockedUntil,
            ]);
        }
    } catch (Throwable $e) {
        error_log('recordFailedAttempt failed: ' . $e->getMessage());
    }
}

/**
 * Clear throttle state on successful login.
 */
function resetLoginAttempts(string $context = 'login', ?string $identifier = null): void
{
    try {
        initializeLoginThrottleTable();
        $pdo = getDb();

        $delete = $pdo->prepare(
            'DELETE FROM login_throttle WHERE context = :context AND key_type = :type AND key_value = :value'
        );

        foreach (bruteForceKeys($identifier) as [$type, $value]) {
            $delete->execute([':context' => $context, ':type' => $type, ':value' => $value]);
        }
    } catch (Throwable $e) {
        error_log('resetLoginAttempts failed: ' . $e->getMessage());
    }
}

function isLoggedIn(): bool
{
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        redirect(url('admin/login.php'));
    }
}

function isParentLoggedIn(): bool
{
    return isset($_SESSION['parent_id'])
        && filter_var($_SESSION['parent_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;
}

function requireParentLogin(): void
{
    if (!isParentLoggedIn()) {
        redirect(url('login.php'));
    }
}

function isTeacherLoggedIn(): bool
{
    return isset($_SESSION['teacher_id'])
        && filter_var($_SESSION['teacher_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;
}

function requireTeacherLogin(): void
{
    if (!isTeacherLoggedIn()) {
        redirect(url('teacher/login.php'));
    }
}
