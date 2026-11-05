<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
/**
 * Manifest and Composer metadata validation in pure PHP.
 * No Python, jq, shell script, Docker daemon, or real municipal data.
 */
$root = dirname(__DIR__);
require_once $root.'/src/AdapterRegistry.php';

function parseJsonFile($path)
{
    $content = @file_get_contents($path);
    $value = $content === false ? null : json_decode($content, true);
    if (!is_array($value) || json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Invalid JSON: '.$path);
    }
    return $value;
}

$composer = parseJsonFile($root.'/composer.json');
if (!isset($composer['name'],$composer['bin'],$composer['autoload']['psr-4'])) {
    throw new RuntimeException('Incomplete Composer package definition');
}
if (!in_array('bin/integrator.php', $composer['bin'], true)) {
    throw new RuntimeException('Composer CLI must point to PHP entrypoint');
}

$schema = parseJsonFile($root.'/adapter.schema.json');
if (!isset($schema['required'],$schema['properties']['hooks'])) {
    throw new RuntimeException('Invalid manifest JSON schema');
}

$registry = new \ECidade\Nextcloud\AdapterRegistry($root);
$count = 0;
foreach ($registry->ids() as $id) {
    $manifest = parseJsonFile($root.'/modules/'.$id.'/module.json');
    foreach ($schema['required'] as $key) {
        if (!array_key_exists($key, $manifest)) {
            throw new RuntimeException($id.' missing required field '.$key);
        }
    }
    if ($manifest['schema'] !== 2 || $manifest['id'] !== $id) {
        throw new RuntimeException($id.' unsupported schema or mismatched ID');
    }
    if ($manifest['production_ready'] === true) {
        throw new RuntimeException($id.' incorrectly marked production-ready');
    }
    foreach ($manifest['hooks'] as $hook) {
        if (!in_array($hook['type'], ['php-file-override',
                'php-bootstrap','source-signature'], true)) {
            throw new RuntimeException($id.' has unknown hook');
        }
        if ($hook['type'] === 'source-signature' || $hook['type'] === 'php-file-override') {
            if (empty($hook['source']['path'])
                || !preg_match('/^[0-9a-f]{40}$/D', $hook['source']['git_blob'])) {
                throw new RuntimeException($id.' source signature invalid');
            }
        }
    }
    $registry->find($id); // Enforces safe module paths and existing implementations.
    $count++;
}
if ($count < 3) {
    throw new RuntimeException('Base vendor adapters absent');
}
echo 'PASS Composer JSON and '.$count.' PHP adapter manifests (schema v2)'.PHP_EOL;
