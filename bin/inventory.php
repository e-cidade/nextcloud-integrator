#!/usr/bin/env php
<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Read-only storage API inventory on a reviewed public source checkout.
 *
 * NEVER scans servers, the network or databases. Output includes ONLY
 * relative code paths, line numbers and mechanism categories, no source
 * snippets, absolute local paths, credentials or document metadata.
 */
$args = isset($_SERVER['argv']) && is_array($_SERVER['argv']) ? $_SERVER['argv'] : [];
array_shift($args);
$source = null;
$synthetic = false;
$approved = false;
foreach ($args as $arg) {
    if (strpos($arg, '--source=') === 0) {
        $source = substr($arg, 9);
    } elseif ($arg === '--public-checkout') {
        $approved = true;
    } elseif ($arg === '--synthetic-test') {
        $synthetic = true;
    } else {
        fwrite(
            STDERR,
            "Usage: php bin/inventory.php --source=<public-git-checkout> --public-checkout\n"
        );
        exit(2);
    }
}
$root = $source === null ? false : realpath($source);
if ($root === false || !is_dir($root)
    || (!$approved && !$synthetic)
    || (!$synthetic && !file_exists($root . '/.git'))
    || ($synthetic && getenv('ECIDADE_INVENTORY_SYNTHETIC') !== '1')) {
    fwrite(STDERR, "Refusing inventory without an explicitly approved source checkout.\n");
    exit(2);
}

$rules = [
    'native_pg_lo' => '/\bpg_lo_(?:create|open|read|write|close|unlink|export|import)\s*\(/i',
    'postgres_lo_sql' => '/\blo_(?:create|import|export|unlink)\s*\(|\bpg_largeobject\b/i',
    'db_large_object' => '/\bDBLargeObject\s*::/i',
    'estorage' => '/\bStorageHelper\s*::|\bFileEstorage\b|ECidade\\\\Lib\\\\Request\\\\Storage|e-?storage/i',
    'framework_storage' => '/Illuminate\\\\Support\\\\Facades\\\\Storage|\bStorage::(?:put|get|delete|disk)\s*\(/i',
    'bytea' => '/\bbytea\b/i',
    'generic_file_io' => '/\b(?:file_get_contents|file_put_contents|move_uploaded_file|copy|fopen)\s*\(/i',
];
$skip = ['.git' => true,'vendor' => true,'node_modules' => true,'.cache' => true];
$directory = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
$filter = new RecursiveCallbackFilterIterator(
    $directory,
    function ($current, $key, $iterator) use ($skip) {
        return !$current->isLink() && (!$current->isDir()
            || !isset($skip[$current->getFilename()]));
    }
);
$iter = new RecursiveIteratorIterator($filter);
$results = [];
$counts = array_fill_keys(array_keys($rules), 0);
$seen = 0;
$truncated = false;
foreach ($iter as $file) {
    if (!$file->isFile() || !preg_match('/\.(php|sql)$/iD', $file->getFilename())) {
        continue;
    }
    $seen++;
    if ($seen > 50000) {
        $truncated = true;
        break;
    }
    $relative = substr($file->getPathname(), strlen($root) + 1);
    $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    // The public report MUST NOT contain any absolute host path.
    if (strpos($relative, '..') !== false || substr($relative, 0, 1) === '/') {
        throw new RuntimeException('Unsafe source pathname');
    }

    $handle = fopen($file->getPathname(), 'rb');
    if (!$handle) {
        throw new RuntimeException('Source code cannot be read');
    }
    $hits = [];
    $line = 0;
    while (($text = fgets($handle)) !== false) {
        $line++;
        foreach ($rules as $name => $pattern) {
            if (preg_match($pattern, $text)) {
                if (!isset($hits[$name])) {
                    $hits[$name] = [];
                }
                $hits[$name][] = $line;
                $counts[$name]++;
            }
        }
    }
    fclose($handle);
    if ($hits) {
        $results[] = ['path' => $relative,'hits' => $hits];
    }
}
usort($results, function ($a, $b) {
    return strcmp($a['path'], $b['path']);
});
echo json_encode([
    'classification' => 'candidate-callsites-not-certified-storage-coverage',
    'scanned_files' => $seen,
    'truncated' => $truncated,
    'counts' => $counts,
    'candidates' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
