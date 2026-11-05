<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $namespace = 'ECidade\\Nextcloud\\';
    if (!str_starts_with($class, $namespace)) {
        return;
    }

    $file = dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', substr($class, strlen($namespace))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
