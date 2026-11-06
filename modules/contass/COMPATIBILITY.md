<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Contass: pinned Protocolo business-model contract

Upstream: `e-cidade/e-cidade-Contass@90081bf22993292ad197134fe1617cace968a984`.
`model/protocolo/ProcessoDocumento.model.php` is verified against Git blob
`2eb3782e84400d40bae177d598445e26140ddf93`.

The common `tests/upstream/protocolo-model-contract.php` loads the **original
unchanged** PHP model on PHP 7.4. It exercises `salvar`, `download`, metadata
`alterar` and `excluir`, with a synthetic `cl_protprocessodocumento` DAO,
actual disposable PostgreSQL 11 transaction, shared SPI, journal and worker.
The same business-method contract is also exercised with verified HTTPS and
Nextcloud 35 in CI. The original `require_once('std/DBLargeObject.php')` is
resolved through an isolated temporary file instead of changing the source.

The older Contass model assigned the newly reserved OID to `$this->iOi`
instead of the declared `$this->iOid` used by the getter. This was corrected
in the source PR `e-cidade/e-cidade-Contass#22`, merged as `d6c651f`.
The pinned revision above includes the correction. The shared business
model test now asserts `getOID()` immediately after `salvar()`, in addition
to the saved business SQL reference and later reads/deletions.

This is **not** a full ERP bootstrap or a real generated DAO test. It does
not validate Laravel Storage, persistent file disks, direct `pg_lo_*`,
`bytea`, historical OIDs, session/ACL authoritative mapping, UI routes or
migration. The manifest remains `production_ready=false` without activated
persistent storage capabilities.
