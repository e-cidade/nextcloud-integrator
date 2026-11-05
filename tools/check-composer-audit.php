<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Root package may intentionally have zero external dependencies.
 * Composer audit exits 1 for such an empty package, even when it has no CVEs.
 * The tool-specific locks still receive ordinary Composer security auditing.
 */
$root = dirname(__DIR__);
$lock = json_decode(file_get_contents($root . '/composer.lock'), true);
if (!is_array($lock) || !isset($lock['packages'], $lock['packages-dev'])) {
    fwrite(STDERR, "FAIL missing or invalid root Composer lock\n");
    exit(1);
}

if (count($lock['packages']) === 0 && count($lock['packages-dev']) === 0) {
    echo "PASS root Composer lock: no third-party packages to audit\n";
    exit(0);
}

$command = 'composer audit --locked --no-interaction';
passthru($command, $code);
exit((int) $code);
