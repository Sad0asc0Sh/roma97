<?php
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';

ob_start();
chdir(__DIR__);
try {
    include __DIR__ . '/index.php';
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage();
}
$output = ob_get_clean();

// Check for bottom nav HTML
echo "=== Bottom Nav HTML Check ===\n";
if (strpos($output, 'mobile-bottom-nav') !== false) {
    echo "PASS: mobile-bottom-nav HTML found\n";
} else {
    echo "FAIL: mobile-bottom-nav HTML NOT found\n";
}

// Check for CSS link
echo "\n=== CSS Link Check ===\n";
preg_match('/<link[^>]*href="([^"]*style\.css[^"]*)"/', $output, $matches);
if (!empty($matches)) {
    echo "CSS href: " . $matches[1] . "\n";
    
    // Check if CSS file exists
    $cssPath = __DIR__ . '/assets/css/style.css';
    if (file_exists($cssPath)) {
        $css = file_get_contents($cssPath);
        if (strpos($css, 'mobile-bottom-nav') !== false) {
            echo "PASS: mobile-bottom-nav CSS rules found\n";
            // Count occurrences
            $count = substr_count($css, 'mobile-bottom-nav');
            echo "Found $count occurrences of 'mobile-bottom-nav' in CSS\n";
        } else {
            echo "FAIL: mobile-bottom-nav CSS rules NOT found\n";
        }
    } else {
        echo "FAIL: CSS file not found at $cssPath\n";
    }
} else {
    echo "FAIL: CSS link not found in HTML\n";
}

// Check viewport meta tag
echo "\n=== Viewport Check ===\n";
if (strpos($output, 'viewport') !== false && strpos($output, 'width=device-width') !== false) {
    echo "PASS: viewport meta tag found\n";
} else {
    echo "FAIL: viewport meta tag not found\n";
}

// Show last 300 chars of output
echo "\n=== Last 300 chars of output ===\n";
echo substr($output, -300) . "\n";
