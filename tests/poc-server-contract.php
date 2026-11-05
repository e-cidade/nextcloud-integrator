<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Standalone PHP 8.3 contract test for the companion Nextcloud PoC app.
 * Actual HTTP behavior is tested separately with Nextcloud started.
 */
$root = dirname(__DIR__);
$app = $root . '/nextcloud-app/ecidadelab';
$routesFile = $app . '/appinfo/routes.php';
$infoFile = $app . '/appinfo/info.xml';
$controllerFile = $app . '/lib/Controller/DocumentsController.php';

foreach ([$routesFile, $infoFile, $controllerFile] as $path) {
    if (!is_file($path)) {
        throw new RuntimeException('Missing Nextcloud app source: ' . $path);
    }
}
if (version_compare(PHP_VERSION, '8.3.0', '<')) {
    throw new RuntimeException('The Nextcloud PoC server contract requires PHP 8.3');
}

$manifest = file_get_contents($infoFile);
if (strpos($manifest, '<id>ecidadelab</id>') === false
    || strpos($manifest, 'not production ready') === false
    || strpos($manifest, 'min-version="35"') === false) {
    throw new RuntimeException('Invalid experimental Nextcloud appinfo');
}

$routes = require $routesFile;
if (!is_array($routes) || !isset($routes['ocs']) || count($routes['ocs']) !== 6) {
    throw new RuntimeException('Nextcloud app must expose exactly six document API routes');
}
$expected = [
    'documents#create' => 'POST',
    'documents#reserve' => 'POST',
    'documents#inspect' => 'GET',
    'documents#download' => 'GET',
    'documents#replace' => 'PUT',
    'documents#remove' => 'DELETE',
];
foreach ($routes['ocs'] as $route) {
    if (!isset($route['name'], $route['verb'])
        || !isset($expected[$route['name']])
        || $expected[$route['name']] !== $route['verb']) {
        throw new RuntimeException('Undocumented or dangerous Nextcloud API route');
    }
    unset($expected[$route['name']]);
}
if ($expected !== []) {
    throw new RuntimeException('Missing expected Nextcloud document API routes');
}
if (is_file($app . '/lib/Controller/FilesApiController.php')) {
    throw new RuntimeException('Benchmark-only file API must not be included in the publishable app');
}

foreach ([$routesFile, $controllerFile] as $file) {
    $output = [];
    $status = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $status);
    if ($status !== 0) {
        throw new RuntimeException('Invalid PHP syntax: ' . basename($file));
    }
}
echo "PASS Nextcloud 35 PoC app packaged with six document-only routes, PHP 8.3 syntax and no benchmark endpoints\n";
