<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Refuse accidental publication of laboratory data, secrets or binaries.
 * Validates the selected SDK directory only, not the parent e-Cidade lab.
 */
$root = dirname(__DIR__);
$excluded = ['vendor', '.git', '.build', 'coverage', '__pycache__'];
$bad = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);
foreach ($it as $entry) {
    $relative = substr($entry->getPathname(), strlen($root) + 1);
    $segments = explode(DIRECTORY_SEPARATOR, $relative);
    $name = basename($relative);
    if (count(array_intersect($segments, $excluded)) > 0) {
        continue;
    }
    if (strpos($relative, 'vendor-bin/') === 0
        && in_array('vendor', $segments, true)) {
        continue;
    }
    if ($entry->isLink()) {
        $bad[] = $relative . ': symlinks require explicit review';
        continue;
    }
    if (!preg_match('/\.(php|json|xml|yml|yaml|md|toml|txt|dist|lock)$/iD', $name)
        && !in_array($name, ['README.md','LICENSE','Dockerfile.php74',
            'CONTRIBUTING.md','.gitignore','.editorconfig','.gitattributes','CODEOWNERS'], true)
        && strpos($relative, '.github/') !== 0) {
        $bad[] = $relative . ': unrecognized file extension';
    }
    if (preg_match('/(^|\\/)\.env($|\\.)|(\.key|\.pem|\.p12|\.pfx|\.dump|' .
        '\.backup|\.sql|\.sqlite|\.db|\.log)$/iD', $relative)
        || strpos($relative, 'selected.json') !== false) {
        // Explicitly exempt example credential-free configuration only.
        if ($name !== '.env.example') {
            $bad[] = $relative . ': sensitive or generated artifact';
        }
    }
    if ($entry->isFile() && $entry->getSize() > 2 * 1024 * 1024) {
        // Large license files are small; avoid accidentally committing dumps.
        $bad[] = $relative . ': file exceeds source size limit';
    }
}
if ($bad) {
    fwrite(STDERR, "FAIL: Source scope violations:\n".implode("\n", $bad)."\n");
    exit(1);
}
echo "PASS public source scope: no credentials, database dumps or lab artifacts\n";
