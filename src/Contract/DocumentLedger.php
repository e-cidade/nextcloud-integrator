<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace ECidade\Nextcloud\Contract;

use ECidade\Nextcloud\Domain\Scope;

/**
 * The business transaction owns reserve/stage/tombstone writes.
 * Implementations MUST require an existing SQL transaction for those writes.
 */
interface DocumentLedger
{
    /** Fail before any remote I/O if the business SQL transaction is absent. */
    public function assertBusinessTransaction();
    public function reserve(Scope $scope, $businessKey);
    /** Stable allocation by scoped business key (serializes concurrent retries). */
    public function reserveOnce(Scope $scope, $businessKey);
    public function find(Scope $scope, $legacyId);
    public function recordStaged(Scope $scope, $legacyId, $operationKey, $checksum, $size, $remoteId);
    /** Return a visible write operation for idempotency, or null. */
    public function findWriteOperation(Scope $scope, $legacyId, $operationKey);
    public function tombstone(Scope $scope, $legacyId);
    public function claimNext(Scope $scope);
    public function finish(Scope $scope, $operationId);
    public function retry(Scope $scope, $operationId, $errorCode);
    public function operationExists(Scope $scope, $operationKey);
    public function latestCommittedVersion(Scope $scope, $legacyId);
    /**
     * Latest version visible under this SQL connection's snapshot, including
     * its own pending writes, but never another transaction's uncommitted row.
     * Returns state 'pending' or 'committed' with integrity metadata.
     */
    public function latestVisibleVersion(Scope $scope, $legacyId);
}
