<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace ECidade\Nextcloud\Contract;

use ECidade\Nextcloud\Domain\AdapterContext;

/**
 * Public SPI v1. ERP transaction ownership is never transferred.
 * No method implicitly opens a transaction or starts a worker.
 * Operation keys are stable across retries and must not enter public URLs.
 */
interface AdapterStorageV1
{
    public function reserve(AdapterContext $context, $businessKey);
    public function create(AdapterContext $context, $legacyId, $bytes, $operationKey);
    public function read(AdapterContext $context, $legacyId);
    public function update(AdapterContext $context, $legacyId, $bytes, $operationKey);
    public function delete(AdapterContext $context, $legacyId);
    public function inspect(AdapterContext $context, $legacyId);
}
