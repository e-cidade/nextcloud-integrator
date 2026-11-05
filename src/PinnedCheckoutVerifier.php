<?php
 
// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
 
namespace ECidade\Nextcloud;

/** PHP 5.6-compatible Git revision guard. No network access. */
final class PinnedCheckoutVerifier
{
    public static function verify($checkout, $upstream)
    {
        if (!is_string($upstream)
            || !preg_match('#^e-cidade/[A-Za-z0-9_.-]+@([a-f0-9]{40})$#D', $upstream, $matches)) {
            throw new \RuntimeException('Invalid pinned upstream revision');
        }
        if (!is_string($checkout)) {
            throw new \RuntimeException('Invalid public checkout');
        }
        $root = realpath($checkout);
        if ($root === false || !is_dir($root) || !is_dir($root . '/.git')) {
            throw new \RuntimeException('Public checkout with Git metadata required');
        }
        $output = [];
        $status = 1;
        exec('git -C ' . escapeshellarg($root) . ' rev-parse --verify HEAD 2>/dev/null', $output, $status);
        if ($status !== 0 || count($output) !== 1 || $output[0] !== $matches[1]) {
            throw new \RuntimeException('Pinned checkout revision mismatch');
        }
        return $matches[1];
    }
}
