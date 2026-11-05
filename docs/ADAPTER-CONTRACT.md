<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Shared adapter contract — MVP integration boundary

This contract governs all e-Cidade forks. **Do not implement independent
Nextcloud clients, databases, transactional state machines or workers per fork.**
A fork adapter only translates its legacy API, bootstrap, return values and
errors to the shared document coordinator. Requirements below are normative,
not claims that every behavior is already implemented.

## Source and activation

1. Every module.json v2 manifest identifies a pinned upstream commit and source
   hooks with Git blob fingerprints, supported PHP and actual capabilities.
2. Shared preflight: php bin/integrator.php verify-pinned <id> <public-checkout>.
   It verifies checkout HEAD and declared hook blobs without network access,
   and is identical for DBSeller, Contass and CPD Municipal.
3. A valid source fingerprint **does not** certify a municipal workflow or
   authorize production deployment. All adapters remain opt-in.
4. Shared PHP code must support the oldest declared runtime (currently PHP 5.6).

## Required translation boundaries

| Legacy surface | Adapter responsibility | Shared-core responsibility |
| --- | --- | --- |
| DBLargeObject / OID | Legacy signatures, transaction ownership, return/error semantics | Stable identity, immutable revisions, staged writes, outbox |
| Direct pg_lo_* | Explicitly identified business callsites; never globally intercept native PG calls | Shared coordinator, identity and consistency |
| E-Storage HTTP | onlyId, allowed[], file_father, MIME, temp files, Base64 translation | Authenticated principals and ACLs, parent checks, versioning |
| Laravel Storage / filesystem | Classify persistent disks vs temporary files; preserve read/write semantics | Authorized remote storage, versioning |
| bytea | Identify business document columns; never globally redirect bytea | Mapping and transactional journal |

An adapter must fail closed on unknown identities, ACLs, parent links,
historical references and unsupported operations. Do not silently ignore
allowed[] or persist any second document byte copy in PostgreSQL.

## Transaction and identity requirements

- Nextcloud is the sole persistent store of new document bytes. ERP SQL
  stores references, metadata and shared outbox state.
- Identity is scoped by installation, municipality, backend and legacy ID;
  a Nextcloud file ID is never exposed as the ERP legacy OID.
- The adapter preserves ownership of the ERP SQL transaction. The common
  coordinator stages immutable bytes and records intent in the same SQL
  transaction; reconciliation confirms only committed operations.
- No other request may see uncommitted bytes. Immediate read-after-write
  remains **an unresolved common-core requirement**: each adapter must
  identify actual dependent callsites and cannot invent its own semantics.
- Validate COMMIT, ROLLBACK, retries, concurrency, crashes, restart, network
  failure, historical references and absence of fallback to PG Large Objects.

## Testing and ownership

- Behavioral unit/contract tests belong in tests/phpunit/; use PHPUnit
  data providers for shared negative and compatibility cases. The existing
  PHPUnit workflow automatically discovers tests; no per-file CI commands.
- PHP 5.6 syntax checks are separate from modern PHPUnit 12 execution.
- Real ERP homologation requires disposable environments, synthetic records,
  failure injection and checking all relevant persistent storage mechanisms.
- Core changes belong in src/, database/ and nextcloud-app/. Fork teams
  modify only modules/<fork>/ and fork-specific tests/fixtures. Missing
  common contracts must be reported, not reimplemented independently.

DBSeller is the first full MVP target; Contass and CPD inventory and
compatibility preflight can advance in parallel. None is production-ready
without an independently demonstrated business workflow.
