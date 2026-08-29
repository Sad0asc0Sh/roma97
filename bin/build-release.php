<?php
declare(strict_types=1);

/*
 * Build a clean production release zip for shared hosting deployment.
 *
 * Usage:  php bin/build-release.php
 * Output: dist/roma-release.zip
 *
 * Excludes development artifacts (.git, .agents, docs, dev reports, logs
 * content, local config, install.lock) and local upload content while keeping
 * the upload directory skeleton (.htaccess + index.html guards).
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$root = dirname(__DIR__);
$distDir = $root . DIRECTORY_SEPARATOR . 'dist';
$zipPath = $distDir . DIRECTORY_SEPARATOR . 'roma-release.zip';

$excludedDirs = [
    '.git', '.agents', '.claude', 'dist', 'logs',
];

$excludedFiles = [
    'config.local.php',
    'install.lock',
    'CHANGELOG_FINAL.md',
    'DEPLOYMENT.md',
    'INSTALLATION.md',
    'LOCAL_ASSET_FIX_REPORT.md',
    'LOCAL_DEPLOYMENT_FIX_REPORT.md',
    'LOCAL_ROUTING_FIX_REPORT.md',
    'LOCAL_UI_DIAGNOSTIC.md',
    'skills-lock.json',
];

// Keep these even inside otherwise-excluded patterns (upload dir guards).
$keepNames = ['.htaccess', 'index.html'];

if (is_file($zipPath)) {
    unlink($zipPath);
}
if (!is_dir($distDir)) {
    mkdir($distDir, 0777, true);
}

$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);

$filesToZip = [];
$totalBytes = 0;

/** @var SplFileInfo $file */
foreach ($rii as $file) {
    if (!$file->isFile()) {
        continue;
    }

    $realPath = $file->getRealPath();
    $relative = str_replace('\\', '/', substr($realPath, strlen($root) + 1));
    $segments = explode('/', $relative);
    $baseName = end($segments);

    // Skip excluded top-level directories.
    foreach ($excludedDirs as $dir) {
        if ($segments[0] === $dir) {
            continue 2;
        }
    }

    // Skip excluded files.
    if (in_array($baseName, $excludedFiles, true)) {
        continue;
    }

    // Inside assets/uploads keep only guard files; skip real uploaded content.
    if ($segments[0] === 'assets' && ($segments[1] ?? '') === 'uploads') {
        if (!in_array($baseName, $keepNames, true)) {
            continue;
        }
    }

    // Never ship db migration scratch or error logs anywhere in the tree.
    if (str_ends_with($baseName, '.log')) {
        continue;
    }

    $filesToZip[$relative] = $realPath;
    $totalBytes += filesize($realPath);
}

if (extension_loaded('zip')) {
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        exit("Cannot create {$zipPath}\n");
    }

    foreach ($filesToZip as $rel => $abs) {
        $zip->addFile($abs, $rel);
    }
    $zip->close();
} else {
    // Fallback: Use PowerShell Compress-Archive or tar on Windows
    $tempList = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'roma_release_files_' . bin2hex(random_bytes(4));
    @mkdir($tempList, 0777, true);

    foreach ($filesToZip as $rel => $abs) {
        $dest = $tempList . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        $destDir = dirname($dest);
        if (!is_dir($destDir)) {
            @mkdir($destDir, 0777, true);
        }
        copy($abs, $dest);
    }

    $psCmd = 'powershell.exe -NoProfile -Command "Compress-Archive -Path \'' . $tempList . '\*\' -DestinationPath \'' . $zipPath . '\' -Force"';
    exec($psCmd, $out, $ret);

    // Clean temp
    $delRii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tempList, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($delRii as $f) {
        $f->isDir() ? @rmdir($f->getRealPath()) : @unlink($f->getRealPath());
    }
    @rmdir($tempList);

    if ($ret !== 0 || !is_file($zipPath)) {
        exit("Failed to create zip via PowerShell fallback.\n");
    }
}

printf(
    "Release built: %s\nFiles: %d (%.1f KB raw, %.1f KB zip)\nUpload this zip into public_html and extract there.\n",
    $zipPath,
    count($filesToZip),
    $totalBytes / 1024,
    filesize($zipPath) / 1024
);
