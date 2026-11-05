<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace ECidade\Nextcloud;

/**
 * Versioned adapter contract. Each module declares integration hooks:
 * php-file-override (implemented), http-endpoint/laravel-driver (future).
 * No fork-specific code or conditionals are introduced in this registry.
 */
class AdapterRegistry
{
    private $root;
    public function __construct($root)
    {
        $this->root = rtrim($root, '/');
    }
    public function ids()
    {
        $out = [];
        foreach (glob($this->root.'/modules/*/module.json') as $path) {
            $out[] = basename(dirname($path));
        }
        sort($out);
        return $out;
    }
    private function id($id)
    {
        if (!is_string($id) || !preg_match('/^[a-z][a-z0-9_-]{1,40}$/D', $id)) {
            throw new \InvalidArgumentException('Invalid adapter ID');
        }
        return $id;
    }
    private function safePath($path)
    {
        return is_string($path) && strlen($path) > 0 && strlen($path) < 256
            && preg_match('#^[a-zA-Z0-9_./-]+$#D', $path)
            && $path[0] !== '/' && strpos($path, '..') === false;
    }
    public function find($id)
    {
        $id = $this->id($id);
        $file = $this->root.'/modules/'.$id.'/module.json';
        if (!is_file($file)) {
            throw new \RuntimeException('Unknown adapter: '.$id);
        }
        $m = json_decode(file_get_contents($file), true);
        foreach (['id','schema','name','version','upstream','min_php','tested',
                'production_ready','certification','capabilities','gaps','hooks'] as $key) {
            if (!is_array($m) || !array_key_exists($key, $m)) {
                throw new \RuntimeException('Missing adapter manifest field: '.$key);
            }
        }
        if ($m['schema'] !== 2 || $m['id'] !== $id || !is_array($m['hooks']) || count($m['hooks']) === 0) {
            throw new \RuntimeException('Unsupported or empty module manifest: '.$id);
        }
        foreach ($m['hooks'] as $hook) {
            if (!is_array($hook) || !isset($hook['type'])) {
                throw new \RuntimeException('Invalid module hook');
            }
            if ($hook['type'] === 'php-file-override') {
                foreach (['source','replacement','target'] as $key) {
                    if (!isset($hook[$key])) {
                        throw new \RuntimeException('Incomplete PHP hook: '.$key);
                    }
                }
                if (!isset($hook['source']['path'],$hook['source']['git_blob'])
                    || !$this->safePath($hook['source']['path'])
                    || !$this->safePath($hook['replacement'])
                    || !$this->safePath($hook['target'])
                    || !preg_match('/^[0-9a-f]{40}$/D', $hook['source']['git_blob'])) {
                    throw new \RuntimeException('Invalid PHP hook source or file path');
                }
                $replace = $this->root.'/modules/'.$id.'/'.$hook['replacement'];
                if (!is_file($replace)) {
                    throw new \RuntimeException('Missing module implementation: '.$replace);
                }
                $moduleDir = realpath($this->root.'/modules/'.$id);
                $resolved = realpath($replace);
                if (!$moduleDir || !$resolved || strpos($resolved, $moduleDir.DIRECTORY_SEPARATOR) !== 0) {
                    throw new \RuntimeException('Module override escapes module directory');
                }
            } elseif ($hook['type'] === 'source-signature') {
                if (!isset($hook['source']['path'],$hook['source']['git_blob'])
                    || !$this->safePath($hook['source']['path'])
                    || !preg_match('/^[0-9a-f]{40}$/D', $hook['source']['git_blob'])) {
                    throw new \RuntimeException('Invalid source signature hook');
                }
            } elseif ($hook['type'] === 'php-bootstrap') {
                if (!isset($hook['script']) || !$this->safePath($hook['script'])
                    || !is_file($this->root.'/modules/'.$id.'/'.$hook['script'])) {
                    throw new \RuntimeException('Missing module bootstrap file');
                }
                $moduleDir = realpath($this->root.'/modules/'.$id);
                $resolved = realpath($this->root.'/modules/'.$id.'/'.$hook['script']);
                if (!$moduleDir || !$resolved || strpos($resolved, $moduleDir.DIRECTORY_SEPARATOR) !== 0) {
                    throw new \RuntimeException('Bootstrap hook escapes module directory');
                }
            } else {
                throw new \RuntimeException('Unsupported hook type: '.$hook['type']);
            }
        }
        return $m;
    }
    public function checkSelection($id)
    {
        $m = $this->find($id);
        if (!$m['tested']) {
            throw new \RuntimeException('Module is not reviewed/tested: '.$id);
        }
        if (version_compare(PHP_VERSION, $m['min_php'], '<')) {
            throw new \RuntimeException('Module requires PHP >= '.$m['min_php']);
        }
        return $m;
    }
    /** Shared revision and immutable hook verification. */
    public function verifyPinnedCheckout($id, $sourceRoot)
    {
        $manifest = $this->find($id);
        require_once __DIR__.'/PinnedCheckoutVerifier.php';
        $revision = PinnedCheckoutVerifier::verify($sourceRoot, $manifest['upstream']);
        $hooks = $this->verifySource($id, $sourceRoot);
        if (count($hooks) === 0) {
            throw new \RuntimeException('Pinned adapter has no source signatures');
        }
        return ['revision' => $revision, 'verified_hooks' => $hooks];
    }

    public function verifySource($id, $sourceRoot)
    {
        $m = $this->find($id);
        if (!$m['tested']) {
            throw new \RuntimeException('Unreviewed adapter cannot pass verification');
        }
        $checked = [];
        foreach ($m['hooks'] as $h) {
            if ($h['type'] !== 'php-file-override' && $h['type'] !== 'source-signature') {
                continue;
            }
            $path = $h['source']['path'];
            $file = rtrim($sourceRoot, '/').'/'.$path;
            if (!is_file($file)) {
                throw new \RuntimeException('Missing upstream file: '.$path);
            }
            $sourceDir = realpath($sourceRoot);
            $resolved = realpath($file);
            if (!$sourceDir || !$resolved || strpos($resolved, $sourceDir.DIRECTORY_SEPARATOR) !== 0) {
                throw new \RuntimeException('Upstream signature file escapes source directory');
            }
            $bytes = file_get_contents($file);
            $actual = sha1('blob '.strlen($bytes)."\0".$bytes);
            if ($actual !== $h['source']['git_blob']) {
                throw new \RuntimeException('Incompatible source '.$path.' expected '.$h['source']['git_blob'].' got '.$actual);
            }
            $checked[$path] = $actual;
        }
        return $checked;
    }
}
