<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
/**
 * Portable PHP syntax checks. Uses the current PHP binary, no Bash scripts.
 * Run under each supported interpreter in CI (PHP 5.6 and newer).
 */
$root = dirname(__DIR__);
$paths = [];
foreach (['/src/*.php','/src/*/*.php','/modules/*/*.php','/bin/*.php','/tools/*.php','/poc/fixtures/*.php','/tests/fixtures/*.php',
    '/tests/*.php','/tests/upstream/*.php','/bootstrap.php'] as $pattern) {
    foreach (glob($root.$pattern) as $path) {
        $paths[] = $path;
    }
}
sort($paths);
if (count($paths) < 8) {
    throw new RuntimeException('Too few PHP source files found');
}
$errors = [];
foreach ($paths as $file) {
    $command = escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file);
    $descriptors = [
        0 => ['pipe','r'],1 => ['pipe','w'],2 => ['pipe','w']
    ];
    $process = proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot run syntax check');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) {
        $errors[] = basename($file).': '.trim($output.' '.$error);
    }
}
if (count($errors) > 0) {
    fwrite(STDERR, implode("\n", $errors)."\n");
    exit(1);
}
echo 'PASS '.count($paths).' PHP source files syntax on '.PHP_VERSION.PHP_EOL;
