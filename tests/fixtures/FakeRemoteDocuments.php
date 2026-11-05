<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use ECidade\Nextcloud\Contract\RemoteDocuments;
use ECidade\Nextcloud\Domain\Scope;

/** PHP 5.6-compatible test double; NEVER load from an application runtime. */
final class FakeRemoteDocuments implements RemoteDocuments
{
    public $objects = [];
    public $failNextFinalize = false;

    private function path(Scope $scope, $documentKey, $operationKey)
    {
        return $scope->key() . ':' . $documentKey . ':' . $operationKey;
    }

    public function stage(Scope $scope, $documentKey, $operationKey, $bytes)
    {
        $path = $this->path($scope, $documentKey, $operationKey);
        if (!isset($this->objects[$path])) {
            $this->objects[$path] = [
                'scope' => $scope,'doc' => $documentKey,'op' => $operationKey,
                'bytes' => $bytes,'id' => count($this->objects) + 101,
                'state' => 'staged','created_at' => time() - 90000
            ];
        } elseif ($this->objects[$path]['bytes'] !== $bytes) {
            throw new RuntimeException('An idempotent stage cannot change bytes');
        }
        return $this->details($path);
    }

    private function details($path)
    {
        $record = $this->objects[$path];
        return [
            'id' => $record['id'],'checksum' => hash('sha256', $record['bytes']),
            'size' => strlen($record['bytes']),'state' => $record['state']
        ];
    }

    public function finalize(Scope $scope, $documentKey, $operationKey)
    {
        if ($this->failNextFinalize) {
            $this->failNextFinalize = false;
            throw new RuntimeException('Synthetic connection failure');
        }
        $path = $this->path($scope, $documentKey, $operationKey);
        if (!isset($this->objects[$path])) {
            throw new RuntimeException('Staged document missing');
        }
        $this->objects[$path]['state'] = 'committed';
        return $this->details($path);
    }

    public function download(Scope $scope, $documentKey, $operationKey)
    {
        $path = $this->path($scope, $documentKey, $operationKey);
        if (!isset($this->objects[$path]) || $this->objects[$path]['state'] !== 'committed') {
            throw new RuntimeException('Not committed');
        }
        return $this->objects[$path]['bytes'];
    }

    public function downloadPending(Scope $scope, $documentKey, $operationKey)
    {
        $path = $this->path($scope, $documentKey, $operationKey);
        if (!isset($this->objects[$path])) {
            throw new RuntimeException('Document version unavailable');
        }
        return $this->objects[$path]['bytes'];
    }

    public function abort(Scope $scope, $documentKey, $operationKey)
    {
        $path = $this->path($scope, $documentKey, $operationKey);
        if (!isset($this->objects[$path])) {
            return true;
        }
        if ($this->objects[$path]['state'] !== 'staged') {
            throw new RuntimeException('Committed bytes cannot be aborted');
        }
        unset($this->objects[$path]);
        return true;
    }

    public function listStaged(Scope $scope, $beforeUnixTimestamp, $limit)
    {
        $result = [];
        foreach ($this->objects as $obj) {
            if (!$obj['scope']->equals($scope) || $obj['state'] !== 'staged'
                || $obj['created_at'] >= $beforeUnixTimestamp) {
                continue;
            }
            $result[] = ['document_key' => $obj['doc'],'operation_key' => $obj['op']];
            if (count($result) >= $limit) {
                break;
            }
        }
        return $result;
    }
}
