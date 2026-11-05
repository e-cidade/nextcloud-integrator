<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace ECidade\Nextcloud\Persistence;

use ECidade\Nextcloud\Contract\DocumentLedger;
use ECidade\Nextcloud\Domain\OperationKey;
use ECidade\Nextcloud\Domain\Scope;

/**
 * Metadata-only journal backed by the SAME PostgreSQL transaction as the ERP.
 *
 * This does not run migrations, create PostgreSQL Large Objects or store bytes.
 * The schema must be explicitly provisioned in an ISOLATED test database.
 */
final class PgDocumentLedger implements DocumentLedger
{
    private $connection;

    public function __construct($connection)
    {
        if (!is_resource($connection)
            && !(is_object($connection) && get_class($connection) === 'PgSql\Connection')) {
            throw new \InvalidArgumentException('A PostgreSQL connection is required');
        }
        if (!function_exists('pg_query_params')) {
            throw new \RuntimeException('PostgreSQL extension unavailable');
        }
        $this->connection = $connection;
    }

    private function query($sql, array $params = [])
    {
        $result = @pg_query_params($this->connection, $sql, $params);
        if ($result === false) {
            // Do not log SQL containing installation/tenant or business metadata.
            throw new \RuntimeException('Document journal SQL command failed');
        }
        return $result;
    }

    public function assertBusinessTransaction()
    {
        if (!function_exists('pg_transaction_status')
            || pg_transaction_status($this->connection) !== PGSQL_TRANSACTION_INTRANS) {
            throw new \RuntimeException('An existing business transaction is required');
        }
    }

    private function legacyId($id)
    {
        if (!is_numeric($id) || (int)$id < 1 || (string)(int)$id !== (string)$id
            || (float)$id > 4294967295) {
            throw new \InvalidArgumentException('Invalid 32-bit legacy document reference');
        }
        return (string)$id;
    }

    private function businessKey($key)
    {
        if (!is_string($key) || strlen($key) < 1 || strlen($key) > 120
            || preg_match('/[\x00-\x1f\x7f]/', $key)) {
            throw new \InvalidArgumentException('Invalid business key');
        }
        return $key;
    }

    private function row(Scope $scope, $id, $lock = false)
    {
        $this->legacyId($id);
        $sql = 'SELECT legacy_id::text AS legacy_id, installation, tenant, business_key, document_key, '
            . 'current_revision, pending_revision, deleted_at '
            . 'FROM ecidade_nextcloud.documents '
            . 'WHERE legacy_id = $1::oid AND installation = $2 AND tenant = $3 '
            . ($lock ? 'FOR UPDATE' : '');
        $result = $this->query($sql, [$id, $scope->installation(), $scope->tenant()]);
        $row = pg_fetch_assoc($result);
        if ($row === false) {
            // Same error for missing and cross-tenant references.
            throw new \RuntimeException('Document not found');
        }
        return $row;
    }

    public function reserve(Scope $scope, $businessKey)
    {
        $this->assertBusinessTransaction();
        $key = $this->businessKey($businessKey);
        if (PHP_INT_SIZE < 8) {
            throw new \RuntimeException('32-bit PHP runtime cannot safely allocate OIDs');
        }

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $row = pg_fetch_assoc($this->query(
                'SELECT nextval(\'ecidade_nextcloud.legacy_id_seq\')::text AS id'
            ));
            $id = $row['id'];

            // Do not reuse IDs that exist in the legacy PostgreSQL LO catalog.
            $native = pg_fetch_result($this->query(
                'SELECT EXISTS(SELECT 1 FROM pg_largeobject_metadata WHERE oid=$1::oid)',
                [$id]
            ), 0, 0);
            if ($native === 't') {
                continue;
            }

            $existing = pg_fetch_result($this->query(
                'SELECT EXISTS(SELECT 1 FROM ecidade_nextcloud.documents WHERE legacy_id=$1::oid)',
                [$id]
            ), 0, 0);
            if ($existing === 't') {
                continue;
            }

            $documentKey = OperationKey::generate();
            $this->query(
                'INSERT INTO ecidade_nextcloud.documents '
                . '(legacy_id, installation, tenant, business_key, document_key) '
                . 'VALUES($1::oid, $2, $3, $4, $5)',
                [$id, $scope->installation(), $scope->tenant(), $key, $documentKey]
            );
            return ['legacy_id' => (int)$id, 'document_key' => $documentKey,
                'current_revision' => 0, 'pending_revision' => null];
        }
        throw new \RuntimeException('Could not allocate a unique legacy document reference');
    }

    public function find(Scope $scope, $legacyId)
    {
        return $this->row($scope, $legacyId);
    }

    public function recordStaged(Scope $scope, $legacyId, $operationKey, $checksum, $size, $remoteId)
    {
        $this->assertBusinessTransaction();
        $operationKey = OperationKey::validate($operationKey);
        if (!is_string($checksum) || !preg_match('/^[a-f0-9]{64}$/D', $checksum)
            || !is_int($size) || $size < 0 || !is_numeric($remoteId) || (int)$remoteId < 1) {
            throw new \InvalidArgumentException('Invalid staged document metadata');
        }
        $row = $this->row($scope, $legacyId, true);
        if ($row['deleted_at'] !== null || $row['pending_revision'] !== null) {
            throw new \RuntimeException('Document is deleted or has a pending revision');
        }
        $revision = (int)$row['current_revision'] + 1;
        $this->query(
            'INSERT INTO ecidade_nextcloud.operations '
            . '(legacy_id, operation_key, operation_type, revision, status, '
            . 'checksum, byte_count, remote_file_id) '
            . 'VALUES($1::oid, $2, \'write\', $3, \'staged\', $4, $5, $6)',
            [$legacyId, $operationKey, $revision, $checksum, $size, (string)$remoteId]
        );
        $this->query(
            'UPDATE ecidade_nextcloud.documents SET pending_revision=$2, updated_at=now() '
            . 'WHERE legacy_id=$1::oid',
            [$legacyId, $revision]
        );
        return ['operation_key' => $operationKey, 'revision' => $revision,
            'state' => 'staged', 'document_key' => $row['document_key']];
    }

    public function tombstone(Scope $scope, $legacyId)
    {
        $this->assertBusinessTransaction();
        $row = $this->row($scope, $legacyId, true);
        if ($row['pending_revision'] !== null) {
            throw new \RuntimeException('Cannot delete a document with a pending revision');
        }
        if ($row['deleted_at'] !== null) {
            return true; // Idempotent tombstone.
        }

        // Tombstoning changes SQL visibility; remote deletion is DEFERRED
        // until a separately approved retention policy and confirmation.
        $this->query(
            'UPDATE ecidade_nextcloud.documents SET deleted_at=now(), updated_at=now() '
            . 'WHERE legacy_id=$1::oid',
            [$legacyId]
        );
        $this->query(
            'INSERT INTO ecidade_nextcloud.operations '
            . '(legacy_id, operation_key, operation_type, revision, status) '
            . 'VALUES($1::oid, $2, \'delete\', $3, \'retained\')',
            [$legacyId, OperationKey::generate(), (int)$row['current_revision'] + 1]
        );
        return true;
    }

    /**
     * Atomically claim one committed staged version. Leases are reclaimable
     * after worker crashes; the remote finalize MUST therefore be idempotent.
     */
    public function claimNext(Scope $scope)
    {
        $this->query('BEGIN');
        try {
            $result = $this->query(
                'SELECT o.id, o.legacy_id::text AS legacy_id, o.operation_key, '
                . 'o.revision, o.checksum, o.byte_count, o.remote_file_id, '
                . 'd.installation, d.tenant, d.document_key '
                . 'FROM ecidade_nextcloud.operations o '
                . 'JOIN ecidade_nextcloud.documents d ON d.legacy_id=o.legacy_id '
                . 'WHERE d.installation=$1 AND d.tenant=$2 AND o.operation_type=\'write\' AND '
                . '((o.status=\'staged\' AND o.next_attempt_at <= now()) OR '
                . '(o.status=\'processing\' AND o.claimed_at < now() - interval \'10 minutes\')) '
                . 'ORDER BY o.id FOR UPDATE OF o SKIP LOCKED LIMIT 1',
                [$scope->installation(), $scope->tenant()]
            );
            $row = pg_fetch_assoc($result);
            if ($row === false) {
                $this->query('COMMIT');
                return null;
            }
            $this->query(
                'UPDATE ecidade_nextcloud.operations '
                . 'SET status=\'processing\', attempts=attempts+1, claimed_at=now() WHERE id=$1',
                [$row['id']]
            );
            $this->query('COMMIT');
            $row['scope'] = new Scope($row['installation'], $row['tenant']);
            return $row;
        } catch (\Exception $e) {
            @pg_query($this->connection, 'ROLLBACK');
            throw $e;
        }
    }

    public function finish(Scope $scope, $operationId)
    {
        $this->query('BEGIN');
        try {
            $result = $this->query(
                'SELECT o.id, o.legacy_id::text AS legacy_id, o.revision, o.status '
                . 'FROM ecidade_nextcloud.operations o '
                . 'JOIN ecidade_nextcloud.documents d ON d.legacy_id=o.legacy_id '
                . 'WHERE o.id=$1 AND d.installation=$2 AND d.tenant=$3 FOR UPDATE OF o',
                [$operationId, $scope->installation(), $scope->tenant()]
            );
            $op = pg_fetch_assoc($result);
            if ($op === false) {
                throw new \RuntimeException('Unknown reconciliation operation');
            }
            if ($op['status'] === 'finalized') {
                $this->query('COMMIT');
                return true;
            }
            if ($op['status'] !== 'processing') {
                throw new \RuntimeException('Operation not claimed by reconciler');
            }
            $updated = $this->query(
                'UPDATE ecidade_nextcloud.documents '
                . 'SET current_revision=$2, pending_revision=NULL, updated_at=now() '
                . 'WHERE legacy_id=$1::oid AND pending_revision=$2 AND deleted_at IS NULL '
                . 'AND installation=$3 AND tenant=$4',
                [$op['legacy_id'], $op['revision'], $scope->installation(), $scope->tenant()]
            );
            if (pg_affected_rows($updated) !== 1) {
                throw new \RuntimeException('Document revision changed during reconciliation');
            }
            $this->query(
                'UPDATE ecidade_nextcloud.operations '
                . 'SET status=\'finalized\', completed_at=now(), claimed_at=NULL, error_code=NULL '
                . 'WHERE id=$1',
                [$operationId]
            );
            $this->query('COMMIT');
            return true;
        } catch (\Exception $e) {
            @pg_query($this->connection, 'ROLLBACK');
            throw $e;
        }
    }

    public function retry(Scope $scope, $operationId, $errorCode)
    {
        if (!is_string($errorCode) || !preg_match('/^[A-Z_]{3,48}$/D', $errorCode)) {
            throw new \InvalidArgumentException('Invalid sanitized error code');
        }
        $this->query('BEGIN');
        try {
            // The dead state requires human inspection/retry after 10 failures.
            $updated = $this->query(
                'UPDATE ecidade_nextcloud.operations o '
                . 'SET status=CASE WHEN attempts >= 10 THEN \'dead\' ELSE \'staged\' END, '
                . 'next_attempt_at=now() + (LEAST(3600, power(2, attempts)) * interval \'1 second\'), '
                . 'claimed_at=NULL, error_code=$4 '
                . 'FROM ecidade_nextcloud.documents d '
                . 'WHERE o.id=$1 AND o.status=\'processing\' AND o.legacy_id=d.legacy_id '
                . 'AND d.installation=$2 AND d.tenant=$3',
                [$operationId, $scope->installation(), $scope->tenant(), $errorCode]
            );
            if (pg_affected_rows($updated) !== 1) {
                throw new \RuntimeException('Reconciliation scope or lease mismatch');
            }
            $this->query('COMMIT');
        } catch (\Exception $e) {
            @pg_query($this->connection, 'ROLLBACK');
            throw $e;
        }
    }

    public function latestCommittedVersion(Scope $scope, $legacyId)
    {
        $document = $this->row($scope, $legacyId);
        // A newly staged revision MUST NOT hide the previous committed
        // version: legacy readers may run before background reconciliation.
        if ($document['deleted_at'] !== null
            || (int)$document['current_revision'] < 1) {
            throw new \RuntimeException('Document not published');
        }
        $result = $this->query(
            'SELECT operation_key, checksum, byte_count, remote_file_id, revision '
            . 'FROM ecidade_nextcloud.operations '
            . 'WHERE legacy_id=$1::oid AND revision=$2 '
            . 'AND operation_type=\'write\' AND status=\'finalized\'',
            [$legacyId, $document['current_revision']]
        );
        $op = pg_fetch_assoc($result);
        if ($op === false) {
            throw new \RuntimeException('Committed document is missing its audit record');
        }
        $op['document_key'] = $document['document_key'];
        return $op;
    }

    public function operationExists(Scope $scope, $operationKey)
    {
        $operationKey = OperationKey::validate($operationKey);
        $result = $this->query(
            'SELECT EXISTS(SELECT 1 FROM ecidade_nextcloud.operations o '
            . 'JOIN ecidade_nextcloud.documents d ON d.legacy_id=o.legacy_id '
            . 'WHERE o.operation_key=$1 AND d.installation=$2 AND d.tenant=$3)',
            [$operationKey, $scope->installation(), $scope->tenant()]
        );
        return pg_fetch_result($result, 0, 0) === 't';
    }
}
