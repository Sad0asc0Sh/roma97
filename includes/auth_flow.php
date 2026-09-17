<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) { die('Access denied.'); }

function authInput(array $source, string $key, string $default = ''): string
{
    return isset($source[$key]) && is_scalar($source[$key]) ? (string) $source[$key] : $default;
}

function authStrongPassword(string $password): bool
{
    return strlen($password) >= 8 && strlen($password) <= 72
        && !str_contains($password, "\0")
        && preg_match('/[A-Za-z]/', $password) === 1
        && preg_match('/[0-9]/', $password) === 1;
}

function authNormalizeOtp(string $code): string
{
    return strtr(trim($code), [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ]);
}

function authPending(string $key, string $phone): ?array
{
    $pending = $_SESSION[$key] ?? null;
    if (!is_array($pending)
        || (int) authInput($pending, 'expires_at', '0') <= time()
        || preg_match('/\A[a-f0-9]{64}\z/', authInput($pending, 'challenge')) !== 1
        || !isValidIranianMobile(authInput($pending, 'phone'))) {
        unset($_SESSION[$key]);
        return null;
    }
    return authInput($pending, 'phone') === $phone ? $pending : null;
}

function authResendAvailableAt(PDO $pdo, string $phone, string $action): int
{
    return smsOtpResendAvailableAt($pdo, $phone, $action);
}

function authVerifyPendingOtp(PDO $pdo, string $phone, string $code, string $action, array $pending): array
{
    if (!$pdo->inTransaction()) { throw new LogicException('OTP verification requires a transaction.'); }
    $invalid = ['valid' => false, 'payload' => null,
        'message' => 'کد تایید نامعتبر یا منقضی شده است. لطفاً دوباره درخواست دهید.'];
    $expected = authInput($pending, 'challenge');
    if ($expected === '' || authInput($pending, 'phone') !== $phone
        || (int) ($pending['expires_at'] ?? 0) <= time()) {
        return $invalid;
    }
    return verifySmsOtp($phone, $code, $action, $expected);
}
