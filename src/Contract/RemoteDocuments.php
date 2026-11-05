<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace ECidade\Nextcloud\Contract;

use ECidade\Nextcloud\Domain\Scope;

/**
 * Nextcloud operations are idempotent by operationKey, not legacy OID.
 * The remote store must NOT expose staged content in ordinary reads.
 */
interface RemoteDocuments
{
    /**
     * Stage immutable bytes. Repeating an operation key with equal checksum
     * returns the same remote ID; different bytes MUST fail.
     * @return array ['id' => int, 'checksum' => sha256, 'size' => int, 'state' => 'staged']
     */
    public function stage(Scope $scope, $documentKey, $operationKey, $bytes);

    /** Promote staged immutable bytes to committed, idempotently. */
    public function finalize(Scope $scope, $documentKey, $operationKey);

    /** Read an immutable committed version. Staged versions MUST fail. */
    public function download(Scope $scope, $documentKey, $operationKey);

    /** Abort an uncommitted staged version. Committed versions MUST fail. */
    public function abort(Scope $scope, $documentKey, $operationKey);

    /** @return array operation keys staged before timestamp, max $limit */
    public function listStaged(Scope $scope, $beforeUnixTimestamp, $limit);
}
