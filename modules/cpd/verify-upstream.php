<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

// CPD-specific bootstrap check; common source/hash verification is shared.
// This script is intentionally compatible with PHP 5.6.
$root = dirname(dirname(__DIR__));
$upstream = isset($argv[1]) ? $argv[1] : null;
if (!$upstream || !is_dir($upstream)) {
    fwrite(STDERR, "Usage: php modules/cpd/verify-upstream.php <public-checkout>\n");
    exit(2);
}
require_once $root.'/src/AdapterRegistry.php';
$registry = new \ECidade\Nextcloud\AdapterRegistry($root);
$registry->verifyPinnedCheckout('cpd', $upstream);

// These are CPD-specific integration entrypoints, not generic ERP requirements.
foreach (array('FrontController.php', 'index.php', 'DBFileUpload.php', 'std/Modification.php') as $path) {
    if (!is_file($upstream.'/'.$path) || is_link($upstream.'/'.$path)) {
        throw new RuntimeException('Missing CPD bootstrap/upload entrypoint: '.$path);
    }
}
$facade = file_get_contents($root.'/src/StandardLargeObjectFacade.php');
$legacy = file_get_contents($upstream.'/std/DBLargeObject.php');
foreach (array('criaOID', 'escrita', 'writeContent', 'leitura', 'exclusao') as $method) {
    $pattern = '/public\s+static\s+function\s+'.preg_quote($method, '/').'\s*\(/';
    if (!preg_match($pattern, $facade) || !preg_match($pattern, $legacy)) {
        throw new RuntimeException('Legacy method missing: '.$method);
    }
}
echo "PASS CPD-specific bootstrap compatibility (not full ERP certification)\n";
