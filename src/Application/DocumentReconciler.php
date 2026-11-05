<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace ECidade\Nextcloud\Application;

use ECidade\Nextcloud\Contract\DocumentLedger;
use ECidade\Nextcloud\Contract\RemoteDocuments;
use ECidade\Nextcloud\Domain\OperationKey;
use ECidade\Nextcloud\Domain\Scope;

/**
 * Explicit offline/worker process. No external work occurs on app startup.
 *
 * The worker sees only SQL rows committed by the business transaction.
 * A crash after remote finalize is handled by idempotent retry on lease
 * expiry; a remote failure retains the SQL outbox for later retries.
 */
final class DocumentReconciler
{
    private $ledger;
    private $remote;
    private $scope;

    public function __construct(DocumentLedger $ledger, RemoteDocuments $remote, Scope $scope)
    {
        $this->ledger = $ledger;
        $this->remote = $remote;
        $this->scope = $scope;
    }

    public function runOnce()
    {
        $op = $this->ledger->claimNext($this->scope);
        if ($op === null) {
            return false;
        }
        if (!isset($op['scope']) || !$op['scope'] instanceof Scope
            || !$op['scope']->equals($this->scope)) {
            throw new \RuntimeException('Journal returned an invalid operation scope');
        }
        try {
            $metadata = $this->remote->finalize(
                $op['scope'],
                $op['document_key'],
                $op['operation_key']
            );
            if (!is_array($metadata) || !isset($metadata['state'],$metadata['checksum'],
                $metadata['size'])
                || $metadata['state'] !== 'committed'
                || !hash_equals($op['checksum'], $metadata['checksum'])
                || (int)$metadata['size'] !== (int)$op['byte_count']) {
                throw new \UnexpectedValueException('INTEGRITY_FAILED');
            }
            $this->ledger->finish($this->scope, $op['id']);
            return true;
        } catch (\UnexpectedValueException $error) {
            $this->ledger->retry($this->scope, $op['id'], 'INTEGRITY_FAILED');
            return false;
        } catch (\Exception $error) {
            // Only a sanitized code is persisted. Never store remote
            // response bodies, file metadata or credentials in the journal.
            $this->ledger->retry($this->scope, $op['id'], 'REMOTE_UNAVAILABLE');
            return false;
        }
    }

    /**
     * An explicit and conservative orphan cleanup, scoped per installation.
     * A staged remote file is not eligible until it is >24h old and absent
     * from *committed* SQL journal rows. Call only with a bound service scope.
     */
    public function discardOrphans(Scope $scope, $olderThan, $limit = 100)
    {
        if (!$this->scope->equals($scope)) {
            throw new \RuntimeException('Orphan collector is bound to a different scope');
        }
        if (!is_int($olderThan) || $olderThan > time() - 86400
            || !is_int($limit) || $limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Unsafe orphan cleanup window');
        }
        $staged = $this->remote->listStaged($scope, $olderThan, $limit);
        if (!is_array($staged)) {
            throw new \RuntimeException('Invalid remote staged inventory');
        }
        $deleted = 0;
        foreach ($staged as $entry) {
            if (!isset($entry['document_key'],$entry['operation_key'])) {
                throw new \RuntimeException('Incomplete staged inventory');
            }
            $documentKey = OperationKey::validate($entry['document_key']);
            $operationKey = OperationKey::validate($entry['operation_key']);
            if ($this->ledger->operationExists($scope, $operationKey)) {
                continue;
            }
            $this->remote->abort($scope, $documentKey, $operationKey);
            $deleted++;
        }
        return $deleted;
    }
}
