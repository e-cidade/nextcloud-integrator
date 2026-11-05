<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace ECidade\Nextcloud\DBSeller;

/** Read-only preflight for an exact public DBSeller Git checkout. */
class SourcePreflight
{
    public static function blobId($bytes)
    {
        return sha1('blob '.strlen($bytes)."\0".$bytes);
    }

    public static function verify($checkout, $manifest)
    {
        if (!is_array($manifest) || empty($manifest['upstream']) || empty($manifest['hooks'])) {
            throw new \RuntimeException('Missing source contract');
        }
        if (!preg_match('/^e-cidade\/e-cidade-DBSeller@([0-9a-f]{40})$/D', $manifest['upstream'], $matches)) {
            throw new \RuntimeException('Unpinned DBSeller revision');
        }
        $root = realpath($checkout);
        if ($root === false || !is_dir($root) || !is_dir($root.'/.git')) {
            throw new \RuntimeException('Expected a public Git checkout');
        }
        $command = 'git -C '.escapeshellarg($root).' rev-parse HEAD 2>/dev/null';
        $head = trim((string)shell_exec($command));
        if ($head !== $matches[1]) {
            throw new \RuntimeException('DBSeller checkout revision mismatch');
        }
        $verified = [];
        foreach ($manifest['hooks'] as $hook) {
            if (empty($hook['source']['path']) || empty($hook['source']['git_blob'])) {
                throw new \RuntimeException('Missing source hash');
            }
            $path = $hook['source']['path'];
            if (!preg_match('#^ecidade/[a-zA-Z0-9_./-]+\.php$#D', $path)
                || strpos('/'.$path.'/', '/../') !== false) {
                throw new \RuntimeException('Unsafe source path');
            }
            $file = $root.'/'.$path;
            if (!is_file($file) || is_link($file) || realpath($file) !== $file) {
                throw new \RuntimeException('Source missing or unsafe');
            }
            $bytes = file_get_contents($file);
            if ($bytes === false || self::blobId($bytes) !== $hook['source']['git_blob']) {
                throw new \RuntimeException('DBSeller source hash mismatch');
            }
            $verified[] = ['path' => $path, 'git_blob' => $hook['source']['git_blob']];
        }
        return ['revision' => $head, 'verified_hooks' => $verified];
    }
}
