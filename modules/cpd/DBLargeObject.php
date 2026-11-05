<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
// CPD Municipal: same upstream Large Object contract as DBSeller.
if (getenv('ECIDADE_ADAPTER') !== 'cpd') {
    throw new RuntimeException('Wrong CPD adapter');
}
require_once getenv('ECIDADE_INTEGRATOR_ROOT').'/src/StandardLargeObjectFacade.php';
