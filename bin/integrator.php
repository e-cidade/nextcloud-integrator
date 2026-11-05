#!/usr/bin/env php
<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
/**
 * e-Cidade adapter registry management (PHP 5.6+).
 * No production credentials, no database modification.
 */
$root = dirname(__DIR__);
require_once $root.'/src/AdapterRegistry.php';
$r = new \ECidade\Nextcloud\AdapterRegistry($root);
$command = isset($argv[1]) ? $argv[1] : 'list';
try {
    switch ($command) {
        case 'test-unit':
            require $root.'/tests/lint.php';
            require $root.'/tests/registry-unit.php';
            require $root.'/tests/manifest-unit.php';
            require $root.'/tests/community-hook-unit.php';
            break;
        case 'list':
            foreach ($r->ids() as $id) {
                $m = $r->find($id);
                echo $id,"\t",$m['name'],"\tmin PHP ",$m['min_php'],
                "\t",$m['certification'],PHP_EOL;
            }
            break;
        case 'inspect':
            if (!isset($argv[2])) {
                throw new \InvalidArgumentException('Missing adapter ID');
            }
            echo json_encode($r->find($argv[2]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),PHP_EOL;
            break;
        case 'verify':
            if (!isset($argv[2],$argv[3])) {
                throw new \InvalidArgumentException('Usage: verify <id> <fork-source-directory>');
            }
            $blobs = $r->verifySource($argv[2], $argv[3]);
            if (count($blobs) === 0) {
                throw new \RuntimeException('No implemented source hooks to verify');
            }
            foreach ($blobs as $file => $hash) {
                echo "PASS $file $hash",PHP_EOL;
            }
            echo 'PASS adapter ',$argv[2],' source compatibility',PHP_EOL;
            break;
        case 'detect':
            if (!isset($argv[2])) {
                throw new \InvalidArgumentException('Usage: detect <fork-source-root>');
            }
            $matches = [];
            foreach ($r->ids() as $id) {
                try {
                    $blobs = $r->verifySource($id, $argv[2]);
                    if (count($blobs) > 0) {
                        $matches[] = $id;
                    }
                } catch (\Exception $error) {
                    // A nonmatching source is normal during detection.
                }
            }
            if (count($matches) === 0) {
                echo "No recognized fork. Run scaffold and submit a tested adapter.\n";
                exit(1);
            }
            echo 'Compatible adapter candidates: ',implode(', ', $matches),PHP_EOL;
            echo "Choose explicitly using select. Detection does not install anything.\n";
            break;
        case 'plan':
            if (!isset($argv[2])) {
                throw new \InvalidArgumentException('Usage: plan <id> [container-app-root]');
            }
            $m = $r->find($argv[2]);
            $dest = isset($argv[3]) ? $argv[3] : '/var/www/html';
            if (!preg_match('#^/[a-zA-Z0-9_./-]+$#D', $dest) || strpos($dest, '..') !== false) {
                throw new \InvalidArgumentException('Invalid container application root');
            }
            echo '# Module ',$m['id'],'. Run verify against actual source BEFORE mounting.',PHP_EOL;
            echo '# Set ECIDADE_ADAPTER=',$m['id'],' and ECIDADE_INTEGRATOR_ROOT=/opt/ecidade-integrator',PHP_EOL;
            echo '# Mount shared integrator at /opt/ecidade-integrator:ro',PHP_EOL;
            foreach ($m['hooks'] as $h) {
                if ($h['type'] === 'php-file-override') {
                    echo '- ./integrator/modules/',$m['id'],'/',$h['replacement'],
                    ':',rtrim($dest, '/'),'/',$h['target'],':ro',PHP_EOL;
                } elseif ($h['type'] === 'php-bootstrap') {
                    echo '# Module bootstrap loaded from modules/',$m['id'],'/',$h['script'],PHP_EOL;
                } elseif ($h['type'] === 'source-signature') {
                    echo '# Version guard: ',$h['source']['path'],PHP_EOL;
                }
            }
            break;
        case 'select':
            if (!isset($argv[2])) {
                throw new \InvalidArgumentException('Usage: select <id>');
            }
            $m = $r->find($argv[2]);
            if (!$m['tested']) {
                throw new \RuntimeException('Cannot select unreviewed module');
            }
            $directory = $root.'/config';
            if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
                throw new \RuntimeException('Cannot create local configuration directory');
            }
            $selection = $directory.'/selected.json';
            file_put_contents($selection, json_encode(['module' => $m['id']], JSON_PRETTY_PRINT)."\n", LOCK_EX);
            echo 'Selected module: ',$m['id'],' (', $selection,')',PHP_EOL;
            break;
        case 'scaffold':
            if (!isset($argv[2],$argv[3])) {
                throw new \InvalidArgumentException('Usage: scaffold <new-id> <base-id>');
            }
            $id = $argv[2];
            if (!preg_match('/^[a-z][a-z0-9_-]{1,40}$/D', $id)) {
                throw new \InvalidArgumentException('Invalid new module ID');
            }
            if (in_array($id, $r->ids(), true)) {
                throw new \RuntimeException('Module already exists');
            }
            $base = $r->find($argv[3]);
            $target = $root.'/modules/'.$id;
            if (!mkdir($target, 0755)) {
                throw new \RuntimeException('Cannot create module');
            }
            $base['id'] = $id;
            $base['name'] = 'TODO: '.$id;
            $base['version'] = '0.1.0-dev';
            $base['upstream'] = 'TODO: owner/fork@commit';
            $base['tested'] = false;
            $base['production_ready'] = false;
            $base['certification'] = 'not-tested';
            $base['gaps'] = ['not-reviewed','not-tested'];
            foreach ($base['hooks'] as &$hook) {
                if ($hook['type'] === 'php-file-override') {
                    $hook['source']['git_blob'] = str_repeat('0', 40);
                    $replacement = $hook['replacement'];
                    $php = file_get_contents($root.'/modules/'.$argv[3].'/'.$replacement);
                    $php = str_replace("'".$argv[3]."'", "'".$id."'", $php);
                    file_put_contents($target.'/'.$replacement, $php);
                } elseif ($hook['type'] === 'source-signature') {
                    $hook['source']['git_blob'] = str_repeat('0', 40);
                } elseif ($hook['type'] === 'php-bootstrap') {
                    $script = $hook['script'];
                    $code = file_get_contents($root.'/modules/'.$argv[3].'/'.$script);
                    file_put_contents($target.'/'.$script, $code);
                }
            }
            unset($hook);
            file_put_contents(
                $target.'/module.json',
                json_encode($base, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
            );
            echo 'Created module ',$id,'. Fill upstream hook signatures and run tests before enabling.',PHP_EOL;
            break;
        default:
            throw new \InvalidArgumentException('Commands: test-unit, list, inspect, detect, verify, plan, select, scaffold');
    }
} catch (\Exception $e) {
    fwrite(STDERR, 'ERROR: '.$e->getMessage().PHP_EOL);
    exit(1);
}
