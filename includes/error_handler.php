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
        showGenericErrorPage();
    } else {
        // In development, show the exception
        echo '<pre>' . htmlspecialchars($logMessage) . '</pre>';
    }
}

/**
 * Display generic error page
 */
function showGenericErrorPage(): void
{
    if (headers_sent()) {
        return;
    }
    
    http_response_code(500);
    
    echo '<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>خطا - مهد کودک روما</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f8f9fa; margin: 0; padding: 0; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .error-container { background: white; padding: 40px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); text-align: center; max-width: 500px; }
        h1 { color: #FF6B6B; margin-bottom: 16px; }
        p { color: #64748b; line-height: 1.8; }
        a { color: #FF6B6B; text-decoration: none; font-weight: 600; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="error-container">
        <h1>خطایی رخ داده است</h1>
        <p>متأسفیم، خطای غیرمنتظره‌ای رخ داده است. تیم ما مطلع شده و در حال رفع مشکل است</p>
        <p><a href="/">بازگشت به صفحه اصلی</a></p>
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
