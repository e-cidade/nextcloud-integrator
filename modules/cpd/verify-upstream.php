<?php
// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
// Read-only CPD source pin check; compatible with PHP 5.6.
$root = dirname(dirname(__DIR__));
$manifest = json_decode(file_get_contents(__DIR__.'/module.json'), true);
if (!is_array($manifest) || $manifest['id'] !== 'cpd') {
    throw new RuntimeException('Invalid CPD manifest');
}
$parts = explode('@', $manifest['upstream'], 2);
if (count($parts) !== 2 || $parts[0] !== 'e-cidade/e-cidade-CPD'
    || !preg_match('/^[a-f0-9]{40}$/D', $parts[1])) {
    throw new RuntimeException('Unpinned CPD upstream revision');
}
$upstream = isset($argv[1]) ? $argv[1] : null;
if (!$upstream || !is_dir($upstream)) {
    fwrite(STDERR, "Usage: php modules/cpd/verify-upstream.php /path/to/disposable/cpd-checkout\n");
    exit(2);
}
foreach ($manifest['hooks'] as $hook) {
    if ($hook['type'] !== 'php-file-override') {
        continue;
    }
    $path = $hook['source']['path'];
    if (strpos($path, '..') !== false || strpos($path, '/') === 0) {
        throw new RuntimeException('Unsafe upstream path');
    }
    $source = $upstream.'/'.$path;
    if (!is_file($source)) {
        throw new RuntimeException('Missing upstream source: '.$path);
    }
    $data = file_get_contents($source);
    $actual = sha1('blob '.strlen($data)."\0".$data);
    if (!hash_equals($hook['source']['git_blob'], $actual)) {
        throw new RuntimeException('Upstream Git blob mismatch: '.$path);
    }
    if (!is_file(__DIR__.'/'.$hook['replacement'])) {
        throw new RuntimeException('Missing CPD override');
    }
}
foreach (array('FrontController.php', 'index.php', 'DBFileUpload.php', 'std/Modification.php') as $path) {
    if (!is_file($upstream.'/'.$path)) {
        throw new RuntimeException('Missing bootstrap/upload entrypoint: '.$path);
    }
}
$facade = file_get_contents($root.'/src/StandardLargeObjectFacade.php');
$legacy = file_get_contents($upstream.'/std/DBLargeObject.php');
foreach (array('criaOID', 'escrita', 'writeContent', 'leitura', 'exclusao') as $method) {
    $pattern = '/public\\s+static\\s+function\\s+'.preg_quote($method, '/').'\\s*\\(/';
    if (!preg_match($pattern, $facade) || !preg_match($pattern, $legacy)) {
        throw new RuntimeException('Legacy method missing: '.$method);
    }
}
echo "PASS CPD pinned facade and bootstrap source contract (not an ERP integration test)\n";
