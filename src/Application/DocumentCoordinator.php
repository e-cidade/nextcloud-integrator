<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace ECidade\Nextcloud\Application;

use ECidade\Nextcloud\Contract\DocumentLedger;
use ECidade\Nextcloud\Contract\RemoteDocuments;
use ECidade\Nextcloud\Domain\OperationKey;
use ECidade\Nextcloud\Domain\Scope;

/**
 * SQL transaction -> reserve -> remote stage -> SQL commit -> worker finalize.
 * New document bytes live ONLY in Nextcloud. Journal is metadata-only.
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

    /** Verify that an ERP bridge and the coordinator use one SQL journal. */
    public function usesLedger(DocumentLedger $ledger)
    {
        return $this->ledger === $ledger;
    }

    /** Must be called INSIDE the e-Cidade business transaction. */
    public function reserve(Scope $scope, $businessKey)
    {
        return $this->ledger->reserve($scope, $businessKey);
    }

    /**
     * Must be called INSIDE the ERP business transaction. The caller may
     * provide a stable operation key for safe retries after an ambiguous
     * network/SQL result. Different bytes for the same key are rejected.
     */
    public function write(Scope $scope, $legacyId, $bytes, $operationKey = null)
    {
        $this->ledger->assertBusinessTransaction();
        if (!is_string($bytes)) {
            throw new \InvalidArgumentException('Document bytes must be a string');
        }
        $key = $operationKey === null ? OperationKey::generate() : OperationKey::validate($operationKey);
        $expected = hash('sha256', $bytes);
        $document = $this->ledger->find($scope, $legacyId);
        if ($document['deleted_at'] !== null) {
            throw new \RuntimeException('Document cannot accept another revision');
        }

        // An idempotent retry must not generate another Nextcloud object,
        // even when the worker has already finalized the first attempt.
        $existing = $this->ledger->findWriteOperation($scope, $legacyId, $key);
        if ($existing !== null) {
            if ($existing['checksum'] !== $expected || (int)$existing['byte_count'] !== strlen($bytes)) {
                throw new \RuntimeException('Idempotency key already contains different document bytes');
            }
            return [
                'operation_key' => $key,
                'revision' => (int)$existing['revision'],
                'state' => $existing['status'] === 'finalized' ? 'finalized' : 'staged',
                'document_key' => $document['document_key'],
            ];
        }
        if ($document['pending_revision'] !== null) {
            throw new \RuntimeException('Document cannot accept another pending revision');
        }

        // A rollback leaves only an inaccessible staged orphan. If the stage
        // request succeeded but the response was lost, repeating the same
        // key produces one remote object and one committed SQL journal row.
        $remote = $this->remote->stage($scope, $document['document_key'], $key, $bytes);
        if (!is_array($remote) || !isset($remote['id'], $remote['checksum'], $remote['size'], $remote['state'])
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
     * SQL-visible read: own staged write within an open transaction, or
     * committed journal write immediately after COMMIT (before worker).
     * Another transaction cannot see an uncommitted journal row and will
     * read the previous immutable version or fail on a new document.
     */
    public function read(Scope $scope, $legacyId)
    {
        $version = $this->ledger->latestVisibleVersion($scope, $legacyId);
        $bytes = $version['state'] === 'pending'
            ? $this->remote->downloadPending($scope, $version['document_key'], $version['operation_key'])
            : $this->remote->download($scope, $version['document_key'], $version['operation_key']);
        if (!is_string($bytes)
            || !hash_equals($version['checksum'], hash('sha256', $bytes))
            || strlen($bytes) !== (int)$version['byte_count']) {
            throw new \RuntimeException('Remote document integrity failed');
        }
        return $bytes;
    }

    /** SQL tombstone only; irreversible remote deletion is deferred. */
    public function delete(Scope $scope, $legacyId)
    {
        return $this->ledger->tombstone($scope, $legacyId);
    }
}
