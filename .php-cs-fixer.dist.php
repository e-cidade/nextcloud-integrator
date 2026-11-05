<?php
// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/modules')
    ->in(__DIR__ . '/bin')
    ->in(__DIR__ . '/tools')
    ->in(__DIR__ . '/tests')
    ->in(__DIR__ . '/poc/fixtures')
    ->in(__DIR__ . '/nextcloud-app/ecidadelab/lib')
    ->append([__DIR__ . '/bootstrap.php', __DIR__ . '/nextcloud-app/ecidadelab/appinfo/routes.php'])
    ->notPath('vendor');

return (new PhpCsFixer\Config())
    // Format with modern PHP, then gate generated syntax on PHP 5.6 and 7.4.
    ->setUnsupportedPhpVersionAllowed(true)
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'ordered_imports' => true,
        'single_quote' => true,
    ])
    ->setFinder($finder);
