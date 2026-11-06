<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Domain\Configuracao\Helpers;

/**
 * Synthetic E-Storage boundary for the pinned protocol model test.
 * Never opens a network connection or stores any document bytes.
 */
final class StorageHelper
{
    public static $attempts = 0;

    public static function downloadArquivo($id)
    {
        self::$attempts++;
        throw new \RuntimeException('Synthetic E-Storage disabled in business contract test');
    }
}
