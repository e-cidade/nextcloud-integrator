<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace ECidade\Nextcloud\Domain;

/** 256-bit unpredictable ID for retries and orphan reconciliation. */
final class OperationKey
{
    public static function generate()
    {
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes(32));
        }
        // PHP 5.6 compatibility. Do not fall back to mt_rand/uniqid.
        if (!function_exists('openssl_random_pseudo_bytes')) {
            throw new \RuntimeException('Cryptographically secure randomness unavailable');
        }
        $strong = false;
        $bytes = openssl_random_pseudo_bytes(32, $strong);
        if ($strong !== true || strlen($bytes) !== 32) {
            throw new \RuntimeException('Cryptographically secure randomness unavailable');
        }
        return bin2hex($bytes);
    }

    public static function validate($value)
    {
        if (!is_string($value) || !preg_match('/^[a-f0-9]{64}$/D', $value)) {
            throw new \InvalidArgumentException('Invalid operation key');
        }
        return $value;
    }
}
