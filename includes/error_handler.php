<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

/**
 * Custom error handler for production security
 */

// Configure error reporting based on environment
if (defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE) {
    // Development: Show all errors
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
} else {
    // Production: Log errors, don't display
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    
    if (defined('ERROR_LOG_PATH')) {
        ini_set('error_log', ERROR_LOG_PATH);
    }
}

/**
 * Custom error handler function
 */
function customErrorHandler(int $errno, string $errstr, string $errfile, int $errline): bool
{
    // Don't handle errors suppressed with @
    if (!(error_reporting() & $errno)) {
        return false;
    }
    
    $errorTypes = [
        E_ERROR => 'Error',
        E_WARNING => 'Warning',
        E_PARSE => 'Parse Error',
        E_NOTICE => 'Notice',
        E_CORE_ERROR => 'Core Error',
        E_CORE_WARNING => 'Core Warning',
        E_COMPILE_ERROR => 'Compile Error',
        E_COMPILE_WARNING => 'Compile Warning',
        E_USER_ERROR => 'User Error',
        E_USER_WARNING => 'User Warning',
        E_USER_NOTICE => 'User Notice',
        E_STRICT => 'Strict Notice',
        E_RECOVERABLE_ERROR => 'Recoverable Error',
        E_DEPRECATED => 'Deprecated',
        E_USER_DEPRECATED => 'User Deprecated',
    ];
    
    $errorType = $errorTypes[$errno] ?? 'Unknown Error';
    $logMessage = sprintf(
        "[%s] %s: %s in %s on line %d\n",
        date('Y-m-d H:i:s'),
        $errorType,
        $errstr,
        $errfile,
        $errline
    );
    
    // Log to file
    if (defined('ERROR_LOG_PATH')) {
        error_log($logMessage, 3, ERROR_LOG_PATH);
    } else {
        error_log($logMessage);
    }
    
    // In production, show generic error page for fatal errors only
    if (!DEVELOPMENT_MODE && in_array($errno, [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
        showGenericErrorPage();
    }
    
    return true;
}

/**
 * Custom exception handler
 */
function customExceptionHandler(Throwable $exception): void
{
    $logMessage = sprintf(
        "[%s] Uncaught Exception: %s in %s on line %d\nStack trace:\n%s\n",
        date('Y-m-d H:i:s'),
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine(),
        $exception->getTraceAsString()
    );
    
    // Log to file
    if (defined('ERROR_LOG_PATH')) {
        error_log($logMessage, 3, ERROR_LOG_PATH);
    } else {
        error_log($logMessage);
    }
    
    // Show generic error page in production
    if (!DEVELOPMENT_MODE) {
        showGenericErrorPage($exception);
    } else {
        // In development, show the exception details
        echo '<pre>' . htmlspecialchars($logMessage) . '</pre>';
    }
}

/**
 * Display generic error page
 *
 * When the failure is a database/PDO connection problem, show a more helpful
 * page that guides the user toward running setup.php rather than a generic
 * "something went wrong" message. This dramatically improves the local
 * development experience (the most common cause of a 500 on every page is
 * that the database has not been created / setup.php has not been run).
 */
function showGenericErrorPage(?Throwable $exception = null): void
{
    if (headers_sent()) {
        return;
    }

    http_response_code(500);

    $isDbError = $exception instanceof PDOException
        || ($exception !== null && strpos((string) $exception->getMessage(), 'SQLSTATE') !== false)
        || ($exception !== null && stripos((string) $exception->getMessage(), 'mysql') !== false);

    $homeUrl = defined('SITE_URL') ? htmlspecialchars(SITE_URL, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '/';

    // In development mode, include the actual error text for fast debugging.
    $detailBlock = '';
    if (defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE && $exception !== null) {
        $safeMessage = htmlspecialchars($exception->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeFile = htmlspecialchars($exception->getFile(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeLine = (int) $exception->getLine();
        $detailBlock = '<div class="error-detail"><strong>خطا:</strong> ' . $safeMessage
            . '<br><strong>فایل:</strong> ' . $safeFile . ':' . $safeLine . '</div>';
    }

    $title = $isDbError ? 'خطای اتصال به پایگاه داده' : 'خطایی رخ داده است';
    $icon = $isDbError ? '🗄️' : '⚠️';

    if ($isDbError) {
        $message = 'اتصال به پایگاه داده ممکن نیست. لطفاً اطمینان حاصل کنید که:';
        $hint = '<ul class="hint-list">
            <li>سرویس MySQL (یا MariaDB) در حال اجراست.</li>
            <li>پایگاه داده <code>rooma_db</code> ایجاد شده است — برای راه‌اندازی اولیه به <a href="' . $homeUrl . '/setup.php">صفحه نصب</a> بروید.</li>
            <li>اعتبارنامه‌های پایگاه داده در <code>config.local.php</code> صحیح است.</li>
        </ul>';
    } else {
        $message = 'متأسفیم، خطای غیرمنتظره‌ای رخ داده است. تیم ما مطلع شده و در حال رفع مشکل است';
        $hint = '';
    }

    echo '<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . $title . ' - مهد کودک روما</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f8f9fa; margin: 0; padding: 0; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .error-container { background: white; padding: 40px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); text-align: center; max-width: 560px; }
        .error-icon { font-size: 48px; margin-bottom: 16px; }
        h1 { color: #FF6B6B; margin-bottom: 16px; }
        p { color: #64748b; line-height: 1.8; }
        a { color: #FF6B6B; text-decoration: none; font-weight: 600; }
        a:hover { text-decoration: underline; }
        .hint-list { text-align: right; direction: rtl; list-style: none; padding: 0; margin: 16px 0; }
        .hint-list li { background: #f1f5f9; padding: 10px 14px; margin-bottom: 8px; border-radius: 6px; color: #475569; font-size: 14px; }
        .hint-list code { background: #e2e8f0; padding: 2px 6px; border-radius: 4px; font-family: monospace; }
        .error-detail { background: #fef2f2; border: 1px solid #fecaca; padding: 12px; border-radius: 6px; margin-top: 16px; text-align: left; direction: ltr; color: #991b1b; font-size: 13px; font-family: monospace; }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-icon">' . $icon . '</div>
        <h1>' . $title . '</h1>
        <p>' . $message . '</p>
        ' . $hint . '
        ' . $detailBlock . '
        <p><a href="' . $homeUrl . '">بازگشت به صفحه اصلی</a></p>
    </div>
</body>
</html>';

    exit;
}

// Register error and exception handlers
set_error_handler('customErrorHandler');
set_exception_handler('customExceptionHandler');

// Register shutdown function to catch fatal errors
register_shutdown_function(function () {
    $error = error_get_last();
    
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $logMessage = sprintf(
            "[%s] Fatal Error: %s in %s on line %d\n",
            date('Y-m-d H:i:s'),
            $error['message'],
            $error['file'],
            $error['line']
        );
        
        if (defined('ERROR_LOG_PATH')) {
            error_log($logMessage, 3, ERROR_LOG_PATH);
        } else {
            error_log($logMessage);
        }
        
        if (!DEVELOPMENT_MODE) {
            showGenericErrorPage();
        }
    }
});
