<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace ECidade\Nextcloud\Contract;

use ECidade\Nextcloud\Domain\AdapterContext;

/**
 * Bound to the authenticated ERP session/installation via dependency injection.
 * Return true ONLY after resolving principals, ACLs, parent references and
 * operation permissions from trusted sources; missing mappings deny access.
 * No permissive default implementation is provided by the SDK.
 */
interface DocumentAccessPolicy
{
    public function allows(AdapterContext $context, $operation, $legacyId);
}
