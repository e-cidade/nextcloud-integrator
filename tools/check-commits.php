<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * DCO and Conventional Commits on every non-merge commit in the PR.
 */
require_once __DIR__ . '/GitCommitRange.php';

$mode = isset($argv[1]) ? $argv[1] : null;
$base = isset($argv[2]) ? $argv[2] : null;
$head = isset($argv[3]) ? $argv[3] : null;
if (!in_array($mode, ['--dco', '--conventional'], true) || !$base || !$head) {
    fwrite(STDERR, "Usage: php tools/check-commits.php --dco|--conventional BASE HEAD\n");
    exit(2);
}

try {
    $errors = [];
    $commits = GitCommitRange::commits($base, $head);
    foreach ($commits as $sha) {
        $subject = GitCommitRange::run(['show', '-s', '--format=%s', $sha]);
        $author = GitCommitRange::run(['show', '-s', '--format=%an <%ae>', $sha]);
        $message = GitCommitRange::run(['show', '-s', '--format=%B', $sha]);

        if ($mode === '--conventional') {
            $valid = preg_match('/^(feat|fix|docs|test|refactor|perf|build|ci|chore|revert|style)' .
                '(\([a-zA-Z0-9_.-]+\))?(!)?: .{3,}$/D', $subject);
            if (!$valid) {
                $errors[] = $sha . ': invalid Conventional Commit: ' . $subject;
            }
            continue;
        }

        $trailers = preg_split('/\r?\n/', $message);
        $match = in_array('Signed-off-by: ' . $author, $trailers, true);
        if (!$match && $author === 'dependabot[bot] <49699333+dependabot[bot]@users.noreply.github.com>') {
            $match = in_array('Signed-off-by: dependabot[bot] <support@github.com>', $trailers, true);
        }
        if (!$match) {
            $errors[] = $sha . ': missing DCO sign-off matching the Git author';
        }
    }

    if ($errors) {
        fwrite(STDERR, implode("\n", $errors) . "\n");
        exit(1);
    }
    echo 'PASS ' . $mode . ': ' . count($commits) . ' commits' . PHP_EOL;
} catch (Exception $error) {
    fwrite(STDERR, 'ERROR: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
