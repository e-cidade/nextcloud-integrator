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
    public function find(Scope $scope, $legacyId);
    public function recordStaged(Scope $scope, $legacyId, $operationKey, $checksum, $size, $remoteId);
    public function tombstone(Scope $scope, $legacyId);
    public function claimNext(Scope $scope);
    public function finish(Scope $scope, $operationId);
    public function retry(Scope $scope, $operationId, $errorCode);
    public function operationExists(Scope $scope, $operationKey);
    public function latestCommittedVersion(Scope $scope, $legacyId);
}
