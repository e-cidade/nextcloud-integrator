<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace ECidade\Nextcloud\Application;

/** Side-effect-free bounded worker argument validation, PHP 5.6+. */
final class WorkerBatchOptions
{
    public static function parseLimit($argument)
    {
        if (!is_string($argument)
            || !preg_match('/^--max=([1-9][0-9]{0,2})$/D', $argument, $match)
            || (int)$match[1] > 100) {
            throw new \InvalidArgumentException('Worker batch must contain 1..100 operations');
        }
        return (int)$match[1];
    }
}
