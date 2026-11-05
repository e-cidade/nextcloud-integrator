<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

require_once dirname(__DIR__).'/modules/dbseller/SourcePreflight.php';

$manifest = json_decode(file_get_contents(dirname(__DIR__).'/modules/dbseller/module.json'), true);
if ($manifest['upstream'] !== 'e-cidade/e-cidade-DBSeller@7fab5ac38b19dbd842ca81a259186c613c90f3c1') {
    throw new RuntimeException('Unexpected DBSeller revision');
}
if ($manifest['hooks'][0]['source']['git_blob'] !== 'a5980256e57e97fdb6b8f6dcdde5844c10cc7d07') {
    throw new RuntimeException('Unexpected source fingerprint');
}
if (\ECidade\Nextcloud\DBSeller\SourcePreflight::blobId('hello') !== 'b6fc4c620b67d95f953a5c1c1230aaab5db5a1b0') {
    throw new RuntimeException('Git blob calculation broken');
}
$failed = false;
try {
    \ECidade\Nextcloud\DBSeller\SourcePreflight::verify(__DIR__, $manifest);
} catch (RuntimeException $exception) {
    $failed = true;
}
if (!$failed) {
    throw new RuntimeException('Accepted an unrelated directory');
}
if ($manifest['production_ready'] !== false || in_array('estorage-adapter', $manifest['capabilities'], true)) {
    throw new RuntimeException('Unsafe DBSeller certification');
}
echo "PASS DBSeller source fingerprint, refusal, and E-Storage gate\n";
