<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Git metadata helper for CI only. Never invokes a shell with unescaped refs.
 */
final class GitCommitRange
{
    public static function run(array $args)
    {
        $parts = [escapeshellarg('git')];
        foreach ($args as $value) {
            $parts[] = escapeshellarg($value);
        }
        $output = [];
        $status = 0;
        exec(implode(' ', $parts) . ' 2>&1', $output, $status);
        if ($status !== 0) {
            throw new RuntimeException('Git metadata unavailable: ' . implode("\n", $output));
        }
        return trim(implode("\n", $output));
    }

    public static function commits($base, $head)
    {
        $mergeBase = self::run(['merge-base', $base, $head]);
        $range = self::run(['rev-list', '--no-merges', $mergeBase . '..' . $head]);
        if ($range === '') {
            throw new RuntimeException('No non-merge commits in pull request');
        }
        $commits = explode("\n", $range);
        foreach ($commits as $sha) {
            if (!preg_match('/^[a-f0-9]{40}$/D', $sha)) {
                throw new RuntimeException('Git returned an unexpected commit identifier');
            }
        }
        return $commits;
    }
}
