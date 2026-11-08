<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * GitHub Actions policy: external Actions must use full immutable SHAs and
 * have a human-readable upstream version next to the pin.
 */
$root = dirname(__DIR__);
$paths = glob($root . '/.github/workflows/*.{yml,yaml}', GLOB_BRACE);
$issues = [];
$count = 0;
foreach ($paths as $file) {
    foreach (file($file) as $lineNumber => $line) {
        if (!preg_match('/^\\s*-?\\s*uses:\\s*(\\S+)(.*)$/', $line, $m)) {
            continue;
        }
        $reference = $m[1];
        if (strpos($reference, './') === 0 || strpos($reference, '$/') === 0) {
            continue;
        }
        $count++;
        if (!preg_match('/^[\\w.-]+\\/[\\w.-]+(?:\\/[\\w.-]+)?@[a-f0-9]{40}$/D', $reference)) {
            $issues[] = basename($file).':'.($lineNumber + 1).' unpinned action: '.$reference;
        }
        if (!preg_match('/\\s+#\\s+(?:v?[0-9]+\\.[0-9]+(?:\\.[0-9]+)?|main at [a-f0-9]+)/', $m[2])) {
            $issues[] = basename($file).':'.($lineNumber + 1).' missing action version comment';
        }
    }
}
if ($count < 3) {
    $issues[] = 'Missing expected pinned actions in workflow catalog';
}
if ($issues) {
    fwrite(STDERR, "FAIL workflow policy:\n".implode("\n", $issues)."\n");
    exit(1);
}
echo 'PASS ' . $count . ' immutable GitHub Actions references with annotations' . PHP_EOL;
