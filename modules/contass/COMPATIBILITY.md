<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Contass: pinned Protocolo business-model contract

Upstream: `e-cidade/e-cidade-Contass@28e2a797dd0d14d3f7ea918d3ce06add154ec381`.
`model/protocolo/ProcessoDocumento.model.php` is verified against Git blob
`00b9a9d35703f8ebbe1076310d77f88bf24633fc`.

The common `tests/upstream/protocolo-model-contract.php` loads the **original
unchanged** PHP model on PHP 7.4. It exercises `salvar`, `download`, metadata
`alterar` and `excluir`, with a synthetic `cl_protprocessodocumento` DAO,
actual disposable PostgreSQL 11 transaction, shared SPI, journal and worker.
The same business-method contract is also exercised with verified HTTPS and
Nextcloud 35 in CI. The original `require_once('std/DBLargeObject.php')` is
resolved through an isolated temporary file instead of changing the source.

The original Contass model writes the newly reserved OID to `$this->iOi`,
not `$this->iOid` (the getter's property). The test therefore derives the
actual OID from the saved synthetic business SQL row. A freshly loaded model
returns the OID correctly, but the discrepancy must be resolved with the
fork maintainer before relying on immediate `getOID()` after `salvar()`.

This is **not** a full ERP bootstrap or a real generated DAO test. It does
not validate Laravel Storage, persistent file disks, direct `pg_lo_*`,
`bytea`, historical OIDs, session/ACL authoritative mapping, UI routes or
migration. The manifest remains `production_ready=false` without activated
persistent storage capabilities.
