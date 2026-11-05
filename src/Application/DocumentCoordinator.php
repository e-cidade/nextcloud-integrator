<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace ECidade\Nextcloud\Application;

use ECidade\Nextcloud\Contract\DocumentLedger;
use ECidade\Nextcloud\Contract\RemoteDocuments;
use ECidade\Nextcloud\Domain\OperationKey;
use ECidade\Nextcloud\Domain\Scope;

/**
 * Two-phase document writes.
 *
 * SQL transaction -> reserve ID -> stage immutable remote bytes -> commit SQL
 * -> reconciler promotes staged bytes. No SQL code ever stores file bytes.
 */
final class DocumentCoordinator
{
    private $ledger;
    private $remote;

    public function __construct(DocumentLedger $ledger, RemoteDocuments $remote)
    {
        $this->ledger = $ledger;
        $this->remote = $remote;
    }

    /** Must be called INSIDE the e-Cidade business transaction. */
    public function reserve(Scope $scope, $businessKey)
    {
        return $this->ledger->reserve($scope, $businessKey);
    }

    /** Must be called INSIDE the same e-Cidade business transaction. */
    public function write(Scope $scope, $legacyId, $bytes)
    {
        // Remote staged bytes must never be created on an invalid SQL call.
        $this->ledger->assertBusinessTransaction();
        if (!is_string($bytes)) {
            throw new \InvalidArgumentException('Document bytes must be a string');
        }
        $document = $this->ledger->find($scope, $legacyId);
        if ($document['deleted_at'] !== null || $document['pending_revision'] !== null) {
            throw new \RuntimeException('Document cannot accept another revision');
        }
        $key = OperationKey::generate();
        $expected = hash('sha256', $bytes);

        // The remote version is not readable until a committed SQL journal
        // entry is reconciled. A failed/rolled-back SQL write leaves only a
        // staged remote version; the orphan collector eventually discards it.
        $remote = $this->remote->stage($scope, $document['document_key'], $key, $bytes);
        if (!is_array($remote) || !isset($remote['id'],$remote['checksum'],$remote['size'],$remote['state'])
            || $remote['state'] !== 'staged' || $remote['checksum'] !== $expected
            || (int)$remote['size'] !== strlen($bytes) || (int)$remote['id'] < 1) {
            throw new \RuntimeException('Remote stage verification failed');
        }

        return $this->ledger->recordStaged(
            $scope,
            $legacyId,
            $key,
            $expected,
            strlen($bytes),
            $remote['id']
        );
    }

    /**
     * Reads only the exact committed immutable version belonging to scope.
     * Never falls back to the original PostgreSQL Large Object store.
     */
    public function read(Scope $scope, $legacyId)
    {
        $version = $this->ledger->latestCommittedVersion($scope, $legacyId);
        $bytes = $this->remote->download(
            $scope,
            $version['document_key'],
            $version['operation_key']
        );
        if (!is_string($bytes)
            || !hash_equals($version['checksum'], hash('sha256', $bytes))
            || strlen($bytes) !== (int)$version['byte_count']) {
            throw new \RuntimeException('Remote document integrity failed');
        }
        return $bytes;
    }

    /**
     * SQL tombstone only. Never delete irreversible remote bytes in the
     * uncommitted SQL business transaction.
     */
    public function delete(Scope $scope, $legacyId)
    {
        return $this->ledger->tombstone($scope, $legacyId);
    }
}
