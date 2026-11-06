<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# CPD Municipal: verified scope and integration boundary

Upstream pin: `e-cidade/e-cidade-CPD@39cce22ed59cda56edbbeb3b746e692ab7d66fcb`.
The manifest's `std/DBLargeObject.php` Git blob is
`a5980256e57e97fdb6b8f6dcdde5844c10cc7d07`.
The CPD override delegates to the existing shared `StandardLargeObjectFacade`;
it does not implement another storage service.

## Confirmed source-level observations

- `FrontController.php` dispatches API/v4/web/REST traffic separately and falls
  through to `app.php`. `index.php` uses `modification(...)` for legacy
  libraries. This is not proof that every CPD document path uses the override.
- `std/Modification.php` can return cached modified files. Validate the *loaded*
  file in any disposable integration fixture, not just the source file on disk.
- `std/DBLargeObject.php` exposes `criaOID`, `escrita`, `writeContent`,
  `leitura`, `exclusao` and uses native `pg_lo_*` inside the original
  implementation. The override covers only calls through this class.
- `DBFileUpload.php` moves incoming bytes to `tmp/` and returns the temporary
  path. It is a staging step, **not** evidence of durable document storage.
  Do not redirect it to Nextcloud or classify it as a duplicate persistent copy.

## Pinned original Protocolo business-model test

The pinned public `model/protocolo/ProcessoDocumento.model.php` is also
verified by source blob `b2b62a1cfc49bc402f5a10a4b063219ec7f230e6`.
The common `tests/upstream/protocolo-model-contract.php` exercises its
unchanged `salvar()`, `download()`, metadata `alterar()` and `excluir()` on
PHP 5.6 against PostgreSQL 11, shared SPI/journal and reconciler. The same
model is exercised against disposable Nextcloud 35 over trusted HTTPS in CI.
Only its database DAO is replaced by a small synthetic PostgreSQL DAO: this
**does not** boot the full CPD ERP, UI, original generated DAO or municipal
schema. Capability declarations and production deployment remain disabled.

**Observed correctness defect:** PostgreSQL can return `p01_estorage=false`
as the string `f`. The pinned `ProcessoDocumento::isStorage()` uses a loose
comparison, and `excluir()` skips `DBLargeObject::exclusao` in that case.
The test first demonstrates the omission: the synthetic business record is
deleted but the committed document remains readable. It then rolls back and
explicitly normalizes the boolean on the **fixture object only** with
`setStorage(false)` before asserting the correct tombstone path. This is a
measured blocker in the original CPD source, not a production workaround.
The vendor must correct the boolean interpretation and cover it with its
own pinned source tests before deployment. The SDK must not silently delete
remote documents merely because an ERP record disappears.

## Generated CPD DAO regression evidence

The pinned `classes/db_protprocessodocumento_classe.php` source, verified
by Git blob `9e130f3a86377bc17b8e7833c6b54a5d5d782939`, was
executed **unchanged** with the original Protocolo model against synthetic
PostgreSQL 11 and the shared SPI. The original model sends the string
`"false"` to `p01_estorage`; the real DAO persists it as SQL
`TRUE`. On deletion, the original CPD model skips the Large Object
bridge and leaves a document readable without any business SQL row.
This is a reproducible loss-of-tombstone defect, **not** an acceptable
compatibility behavior. The updated source PR `e-cidade/e-cidade-CPD#1`
contains both strict parsing of PostgreSQL boolean text and a real boolean
assignment at creation. The proposed fixed source passes the same isolated
PHP 5.6/generated-DAO test, but the branch requires human review before
merge. Do not activate the CPD adapter until the upstream fix is merged,
its fingerprint is repinned and full original-bootstrap/ACL paths are proven.

## Not yet established

A complete inventory of direct `pg_lo_*`, `bytea`, persistent filesystem,
and other document paths across the distribution requires a full-tree scan of
the pinned checkout. Direct native functions cannot be replaced globally in
PHP. Calls found outside `DBLargeObject` require explicit, reviewed integration
points in the CPD distribution and a new source signature in this manifest.

The shared `LargeObjectBridge` sends document operations while the SQL
transaction is open. A successful HTTP call does **not** prove that a later SQL
ROLLBACK hides the document, nor that a COMMIT and remote failure are atomic.
This is a blocking dependency on Front 1; do not mark `production_ready` true.

## Reproduce source verification

Use a **separate, disposable checkout**, without municipal configuration,
data or credentials:

```sh
git clone https://github.com/e-cidade/e-cidade-CPD.git cpd-synthetic
git -C cpd-synthetic checkout --detach 39cce22ed59cda56edbbeb3b746e692ab7d66fcb
php modules/cpd/verify-upstream.php cpd-synthetic
```

The verifier checks the pinned Git blob, entrypoints and shared method names.
It does **not** run application create/read/update/delete or prove absence of
persistent duplication. Those require a disposable PostgreSQL + Nextcloud
fixture, a selected real municipal document workflow, and SQL/outbox assertions
before this front can be accepted.

## Acceptance cases for Front 1 and CPD end-to-end test

1. Create then read within an uncommitted SQL transaction; after ROLLBACK,
   the document must never become visible to another request.
2. COMMIT after create/update/delete; retries and worker restart must converge
   to exactly one current document identity, with historical versions immutable.
3. Fail Nextcloud communication before/after SQL COMMIT; reconcile deterministically
   and never acknowledge success that has not become durable.
4. Check direct `pg_lo_*` callers, `bytea`, permanent filesystem paths,
   and SQL OID references against the selected municipal workflow.
5. Verify PHP 5.6 syntax and legacy return/error semantics in the **real** CPD
   invocation, including transactional errors and missing OIDs.

The pinned revision and Git blob checks now use the common adapter registry (verify-pinned). The CPD-specific verifier checks only bootstrap entrypoints and legacy signatures, not a separate source-pinning implementation.
