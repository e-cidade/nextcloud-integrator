<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
/**
 * The selected module is loaded via its bind-mounted facade when the fork uses
 * require_once(modification(...)). No hardcoded fork-specific logic here.
 */
$root = getenv('ECIDADE_INTEGRATOR_ROOT');
if (!$root || !is_dir($root)) {
    throw new RuntimeException('Missing ECIDADE_INTEGRATOR_ROOT');
}
$adapter = getenv('ECIDADE_ADAPTER');
if (!$adapter) {
    $path = $root.'/config/selected.json';
    if (is_file($path)) {
        $choice = json_decode(file_get_contents($path), true);
        $adapter = isset($choice['module']) ? $choice['module'] : null;
    }
}
// PHP 5.6-compatible lazy loading; binding a transaction remains explicit.
spl_autoload_register(function ($class) use ($root) {
    $prefix = 'ECidade\\Nextcloud\\';
    if (strpos($class, $prefix) !== 0 || strpos($class, '..') !== false
        || strpos($class, '/') !== false || strpos($class, "\0") !== false) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = $root.'/src/'.$relative.'.php';
    if (is_file($file)) {
        require_once $file;
    }
});

require_once $root.'/src/AdapterRegistry.php';
$registry = new \ECidade\Nextcloud\AdapterRegistry($root);
$module = $registry->checkSelection($adapter);
// SPI opt-in is NOT equivalent to production readiness or an ACL grant.
// Only validated persistent-document capabilities may enter the SPI path.
$spiCapable = false;
if (isset($module['storage_capabilities'])) {
    foreach ($module['storage_capabilities'] as $capability) {
        if ($capability['spi'] === 1 && $capability['usage'] === 'persistent-document') {
            $spiCapable = true;
            break;
        }
    }
}
$spiSelected = getenv('ECIDADE_SPI_V1') === '1' && $spiCapable;
if (empty($module['production_ready']) && getenv('ECIDADE_POC_CLIENT') !== '1' && !$spiSelected) {
    throw new RuntimeException('Experimental integration disabled outside explicit lab mode');
}
putenv('ECIDADE_ADAPTER='.$adapter);
require_once $root.'/src/DocumentClient.php';
require_once $root.'/src/LargeObjectBridge.php';
// Generic module entrypoints: reviewed scripts run only for the selected fork.
// They may register late framework hooks but must not perform document I/O now.
foreach ($module['hooks'] as $hook) {
    if ($hook['type'] === 'php-bootstrap') {
        require_once $root.'/modules/'.$adapter.'/'.$hook['script'];
    }
}

// Only a fallback for auto-loaded legacy classes; never declare it eagerly.
spl_autoload_register(function ($name) {
    if ($name === 'DBLargeObject' && !class_exists('DBLargeObject', false)) {
        $source = getenv('ECIDADE_LEGACY_DBLOBJECT_PATH');
        if (!$source) {
            $source = '/var/www/html/std/DBLargeObject.php';
        }
        if (!is_file($source)) {
            throw new RuntimeException('Legacy storage facade is not installed');
        }
        require_once $source;
    }
}, true, true);
