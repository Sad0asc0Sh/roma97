<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);

if (($argv[1] ?? '') === 'worker') {
    $request = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
    if (!preg_match('/\Aroma_auth_test_[a-f0-9]{12}\z/', $request['database'])) { exit(2); }
    define('DB_HOST', '127.0.0.1');
    define('DB_NAME', $request['database']);
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DEVELOPMENT_MODE', true);
    define('SESSION_SAVE_PATH', $request['directory']);
    define('ERROR_LOG_PATH', $request['directory'] . '/errors.log');
    define('SITE_URL', 'http://localhost');
    require $root . '/config.php';
    require $root . '/includes/auth.php';
    require $root . '/includes/csrf.php';
    require $root . '/includes/sms.php';
    require $root . '/includes/auth_flow.php';
    require $root . '/includes/unified_auth.php';
    $_SESSION = $request['session'];
    $_SERVER['REQUEST_METHOD'] = $request['method'];
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['DOCUMENT_ROOT'] = $root;
    $_SERVER['SCRIPT_FILENAME'] = $root . '/' . $request['page'];
    $_SERVER['SCRIPT_NAME'] = '/' . $request['page'];
    $_GET = $request['get'];
    $_POST = $request['post'];
    if ($request['method'] === 'POST') { $_POST['csrf_token'] = generateCsrfToken(); }
    register_shutdown_function(static function () use ($request): void {
        file_put_contents($request['directory'] . '/session.json', json_encode($_SESSION, JSON_UNESCAPED_UNICODE));
    });
    require $root . '/' . $request['page'];
    exit;
}

$database = 'roma_auth_test_' . bin2hex(random_bytes(6));
$directory = sys_get_temp_dir() . '/' . $database;
mkdir($directory, 0700);
$server = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$created = false;
$checks = [];
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) { throw new RuntimeException('FAIL: ' . $message); }
    $checks[] = $message;
    echo 'PASS: ', $message, PHP_EOL;
};
$request = static function (string $page, array $post = [], array $session = [], array $get = []) use ($database, $directory): array {
    $data = ['database' => $database, 'directory' => $directory, 'page' => $page,
        'method' => $post === [] ? 'GET' : 'POST', 'post' => $post, 'get' => $get, 'session' => $session];
    file_put_contents($directory . '/request.json', json_encode($data));
    $command = [PHP_BINARY, '-d', 'extension_dir=' . ini_get('extension_dir'), '-d', 'extension=pdo_mysql',
        '-d', 'extension=mbstring', __FILE__, 'worker', $directory . '/request.json'];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $html = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0 || str_contains($html . $stderr, 'Fatal error')) {
        throw new RuntimeException("Page execution failed: $page ($exit): $stderr");
    }
    $state = json_decode(file_get_contents($directory . '/session.json'), true, 512, JSON_THROW_ON_ERROR);
    return [$html, $state];
};
try {
    $server->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $created = true;
    $server->exec("USE `$database`");
    $schema = file_get_contents($root . '/schema.sql');
    // Fresh-table definitions are sufficient; the following stored procedure only upgrades an old index.
    $schema = explode('-- ─── Post-create migrations', $schema)[0];
    $schema = preg_replace('/^--.*$/m', '', $schema);
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $schema) as $statement) {
        if (trim($statement) !== '') { $server->exec($statement); }
    }
    $server->exec("INSERT INTO settings (meta_key, meta_value) VALUES ('sms_enabled','0'),('site_name','آزمایش مهدکودک')");
    foreach (['login.php', 'register.php', 'forgot-password.php', 'reset-password.php'] as $page) {
        [$html] = $request($page);
        $check(str_contains($html, '<!doctype html>') && str_contains($html, '<h1>'), "$page renders with real MySQL");
    }
    $phone = '09123456789';
    $password = 'GardenTest123';
    [$html, $pending] = $request('register.php', ['action' => 'submit_form', 'first_name' => 'والد',
        'last_name' => 'آزمایشی', 'phone' => $phone, 'password' => $password, 'confirm_password' => $password]);
    $check(isset($pending['registration_pending']['challenge']), 'Registration stores session challenge');
    $check((int) $server->query('SELECT COUNT(*) FROM parents')->fetchColumn() === 0, 'Account not created before verification');
    $code = $server->query("SELECT code FROM sms_verification_codes WHERE action_type='register' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $check(is_string($code) && strlen($code) === 5, 'Development OTP generated without SMS');
    [$html] = $request('register.php', [], $pending, ['step' => 'verify', 'phone' => $phone]);
    $check(substr_count($html, '<form ') === 2 && str_contains($html, 'data-wait='), 'Verification and resend forms render separately');
    [$html, $registered] = $request('register.php', ['action' => 'verify_otp', 'phone' => $phone, 'otp_code' => $code], $pending);
    $parent = $server->query('SELECT * FROM parents')->fetch(PDO::FETCH_ASSOC);
    $check($parent !== false && $parent['status'] === 'pending' && (int) $parent['phone_verified'] === 1, 'Verified registration creates pending parent');
    $check(password_verify($password, $parent['password']), 'Registration stores working password hash');
    $check(!isset($registered['registration_pending']), 'Completed registration clears pending session');
    [$html, $session] = $request('login.php', ['identifier' => $phone, 'password' => $password]);
    $check(!isset($session['parent_id']) && str_contains($html, 'منتظر'), 'Pending parent sees approval notice');
    $server->exec("UPDATE parents SET status='active' WHERE id=" . (int) $parent['id']);
    [$html, $session] = $request('login.php', ['identifier' => $phone, 'password' => $password]);
    $check(($session['parent_id'] ?? null) === (int) $parent['id'], 'Active parent login establishes real session');
    [$html, $recovery] = $request('forgot-password.php', ['action' => 'request_otp', 'phone' => $phone]);
    $check(isset($recovery['password_reset_pending']['parent_id']), 'Recovery request stores account and challenge');
    $code = $server->query("SELECT code FROM sms_verification_codes WHERE action_type='reset_password' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $newPassword = 'GardenUpdated123';
    [$html, $recovered] = $request('forgot-password.php', ['action' => 'set_new_password', 'phone' => $phone,
        'otp_code' => $code, 'new_password' => $newPassword, 'confirm_password' => $newPassword], $recovery, ['step' => 'verify']);
    $hash = $server->query('SELECT password FROM parents')->fetchColumn();
    $check(password_verify($newPassword, $hash), 'OTP recovery changes password in MySQL');
    $check(!isset($recovered['password_reset_pending']), 'Recovery clears pending session');
    [$html, $session] = $request('login.php', ['identifier' => $phone, 'password' => $newPassword]);
    $check(($session['parent_id'] ?? null) === (int) $parent['id'], 'Login with recovered password succeeds');
    $token = bin2hex(random_bytes(32));
    $stmt = $server->prepare("INSERT INTO password_reset_tokens (token,account_type,account_id,expires_at) VALUES (?, 'parent', ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))");
    $stmt->execute([$token, $parent['id']]);
    $lastPassword = 'GardenFinal123';
    [$html] = $request('reset-password.php', ['token' => $token, 'password' => $lastPassword, 'confirm_password' => $lastPassword]);
    $check(password_verify($lastPassword, $server->query('SELECT password FROM parents')->fetchColumn()), 'Token recovery changes password in MySQL');
    $check((int) $server->query('SELECT used FROM password_reset_tokens')->fetchColumn() === 1, 'Successful reset consumes recovery token');
    $check((int) $server->query('SELECT COUNT(*) FROM sms_logs')->fetchColumn() === 0, 'No SMS delivery attempted');
    echo "\nRESULT: ", count($checks), " real-MySQL/CLI controller checks passed. Not an HTTP/browser test.\n";
} finally {
    if ($created) {
        try {
            $server->exec("DROP DATABASE `$database`");
            echo "Temporary test database removed.\n";
        } catch (Throwable $cleanupError) {
            fwrite(STDERR, "Cleanup incomplete: temporary database $database may remain; " . $cleanupError->getMessage() . PHP_EOL);
        }
    }
    foreach (glob($directory . '/*') as $file) { unlink($file); }
    rmdir($directory);
}
