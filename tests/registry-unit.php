<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
require_once dirname(__DIR__).'/src/AdapterRegistry.php';
$r = new \ECidade\Nextcloud\AdapterRegistry(dirname(__DIR__));
$ids = $r->ids();
foreach (['dbseller','contass','cpd'] as $id) {
    if (!in_array($id, $ids, true)) {
        throw new \RuntimeException('Missing module '.$id);
    }
}
foreach ($ids as $id) {
    $m = $r->find($id);
    if (empty($m['name']) || empty($m['upstream']) || empty($m['min_php'])
        || !isset($m['production_ready'],$m['certification'],$m['capabilities'],$m['gaps'])
        || !is_array($m['capabilities']) || !is_array($m['gaps'])) {
        throw new \RuntimeException('Incomplete manifest '.$id);
    }
    if ($m['production_ready']) {
        throw new \RuntimeException('POC declared production-ready: '.$id);
    }
    if ($m['schema'] !== 2 || empty($m['hooks'])) {
        throw new \RuntimeException('Invalid v2 hooks for '.$id);
    }
    foreach ($m['hooks'] as $hook) {
        if ($hook['type'] === 'php-file-override'
            && !is_file(dirname(__DIR__).'/modules/'.$id.'/'.$hook['replacement'])) {
            throw new \RuntimeException('Missing override implementation for '.$id);
        }
    }
}
foreach (['../dbseller','a/../b','bad module','/',''] as $invalid) {
    try {
        $r->find($invalid);
        throw new \RuntimeException('Unsafe module ID accepted');
    } catch (\InvalidArgumentException $e) {
    }
}
try {
    $r->find('missing-fork');
    throw new \RuntimeException('Unknown module accepted');
} catch (\RuntimeException $e) {
    if (strpos($e->getMessage(), 'Unknown adapter') === false) {
        throw $e;
    }
}
echo 'PASS registry modules=',count($ids),' and rejected invalid ids',PHP_EOL;
