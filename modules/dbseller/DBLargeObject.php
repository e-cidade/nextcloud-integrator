<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
// DBSeller: source verified at preflight by module.json manifest.
if (getenv('ECIDADE_ADAPTER') !== 'dbseller') {
    throw new RuntimeException('Wrong DBSeller adapter');
}
require_once getenv('ECIDADE_INTEGRATOR_ROOT').'/src/StandardLargeObjectFacade.php';
