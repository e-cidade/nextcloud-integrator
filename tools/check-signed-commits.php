<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Require GitHub's cryptographic verification on every PR commit.
 * Read-only GitHub REST; token stays in the environment, never logged.
 */
require_once __DIR__ . '/GitCommitRange.php';

function githubVerification($payload)
{
    $decoded = json_decode($payload, true);
    return is_array($decoded)
        && isset($decoded['commit']['verification']['verified'])
        && $decoded['commit']['verification']['verified'] === true;
}

if (isset($argv[1]) && $argv[1] === '--self-test') {
    $verified = '{"commit":{"verification":{"verified":true,"reason":"valid"}}}';
    $unsigned = '{"commit":{"verification":{"verified":false,"reason":"unsigned"}}}';
    if (!githubVerification($verified) || githubVerification($unsigned)
        || githubVerification('{}') || githubVerification('{notjson')) {
        fwrite(STDERR, "FAIL signature response parsing\n");
        exit(1);
    }
    echo "PASS GitHub verified signature parser\n";
    exit(0);
}

if (!isset($argv[1], $argv[2])) {
    fwrite(STDERR, "Usage: php tools/check-signed-commits.php BASE HEAD\n");
    exit(2);
}
$repo = getenv('GITHUB_REPOSITORY');
$token = getenv('GITHUB_TOKEN');
if (!$repo || !$token || !preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#D', $repo)) {
    fwrite(STDERR, "ERROR: signed-commit verification requires GitHub Actions read credentials\n");
    exit(2);
}

try {
    $commits = GitCommitRange::commits($argv[1], $argv[2]);
    $failures = [];
    foreach ($commits as $sha) {
        $curl = curl_init('https://api.github.com/repos/' . $repo . '/commits/' . $sha);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Accept: application/vnd.github+json',
                'User-Agent: LibreCode-eCidade-signed-commit-check',
                'Authorization: Bearer ' . $token,
                'X-GitHub-Api-Version: 2022-11-28',
            ],
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($body === false || $status !== 200 || !githubVerification($body)) {
            $failures[] = $sha . ': missing GitHub verified signature or API unavailable';
        }
    }
    if ($failures) {
        fwrite(STDERR, implode("\n", $failures) . "\n");
        exit(1);
    }
    echo 'PASS verified signatures on ' . count($commits) . ' commits' . PHP_EOL;
} catch (Exception $error) {
    fwrite(STDERR, 'ERROR: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
