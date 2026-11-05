#!/usr/bin/env php
<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

if ($argc !== 2 || !is_dir($argv[1])) {
    fwrite(STDERR, "Usage: php modules/dbseller/preflight.php <public-checkout>\n");
    exit(2);
}
require_once __DIR__.'/SourcePreflight.php';
$manifest = json_decode(file_get_contents(__DIR__.'/module.json'), true);
try {
    $result = \ECidade\Nextcloud\DBSeller\SourcePreflight::verify($argv[1], $manifest);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
} catch (\Exception $exception) {
    fwrite(STDERR, "DBSeller preflight rejected: source contract mismatch.\n");
    exit(1);
}
