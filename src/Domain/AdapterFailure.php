<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace ECidade\Nextcloud\Domain;

/** Canonical, non-sensitive SPI v1 errors. Translate at the legacy boundary. */
final class AdapterFailure extends \RuntimeException
{
    const DENIED = 'DENIED';
    const UNSUPPORTED = 'UNSUPPORTED';
    const INVALID = 'INVALID';
    const TRANSACTION_REQUIRED = 'TRANSACTION_REQUIRED';
    const CONFLICT = 'CONFLICT';
    const NOT_FOUND = 'NOT_FOUND';
    const STORAGE_FAILURE = 'STORAGE_FAILURE';

    private $reason;

    public function __construct($reason, $previous = null)
    {
        $codes = [self::DENIED, self::UNSUPPORTED, self::INVALID,
            self::TRANSACTION_REQUIRED, self::CONFLICT, self::NOT_FOUND,
            self::STORAGE_FAILURE];
        if (!in_array($reason, $codes, true)) {
            throw new \InvalidArgumentException('Unknown adapter error code');
        }
        $this->reason = $reason;
        parent::__construct('Document adapter refused operation: ' . $reason, 0, $previous);
    }

    public function reason()
    {
        return $this->reason;
    }
}
