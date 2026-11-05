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
