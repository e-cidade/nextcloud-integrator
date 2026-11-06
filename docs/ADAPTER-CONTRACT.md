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
- No other request may see uncommitted bytes. SQL-visible read-after-write
  is provided by the experimental SPI v1. Forks must validate the semantics against real callsites before activation.
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

## SPI v1 operational boundary (issue #18)

**Status: experimental, opt-in, not frozen and not municipal homologation.**
The contract below is implemented in `ECidade\\Nextcloud\\Contract\\AdapterStorageV1`
by `Application\\AdapterServiceV1`. A manifest `schema: 2` may declare
`storage_capabilities[].spi: 1`; the manifest schema version and SPI version
are independent. The existing production_ready=false vendor manifests make
NO declaration of `storage_capabilities`, so **all SPI calls are denied until
a validated integration explicitly declares persistent document surfaces**.
The generic `capabilities` and `planned_capabilities` fields are inventory,
not authorization to route bytes.

### Dependency injection and authority

Construct the SPI explicitly with the **same** `DocumentCoordinator` and
`DocumentLedger` that back the deployed shared worker and a
`DocumentAccessPolicy` implementation bound to an authenticated ERP session.
An `AdapterContext` contains `Scope(installation, municipality)`, `backend`
(the exact manifest ID), `surface`, `actor`, and a mapping of validated
context attributes. The context's strings **do not** authenticate the actor;
the injected policy MUST compare them to server-side principal/ACL records,
municipal installation, ownership and parent links. Missing or unknown mapping
returns false. No default allow policy or automatic bootstrap exists.

The SPI never executes `BEGIN`, `COMMIT`, `ROLLBACK`, SQL schema migration,
worker start or remote connection configuration. The ERP owns SQL transaction
boundaries and creates its own service session by explicit DI. All metadata
is stored by one shared `DocumentLedger`; all bytes are written by one
`RemoteDocuments` implementation. No module manages its own outbox,
remote client or SQL state.

### Versioned operations / observable matrix

| SPI method | Input | Return on success | SQL transaction | Visibility / failure |
| --- | --- | --- | --- | --- |
| `reserve(context, businessKey)` | Stable business reference <= remaining 120-character key allowance | `{legacy_id, revision}` | Required; caller-owned | Scoped + backend/surface prefix; repeated key returns same reference; historical collision skips native LO OIDs |
| `create(context, legacyId, bytes, operationKey)` | Reserved ID, string bytes, 64-char hex idempotency key | `{legacy_id,revision,state,operation_key}` | Required | First immutable revision; retry with same bytes+key returns same operation |
| `read(context, legacyId)` | Scoped existing logical ID | Exact bytes, checksum/size verified | Not required; uses own SQL snapshot if a transaction exists | Same-transaction staged content and committed SQL staged content are readable *through authorized gateway*; uncommitted versions stay invisible to concurrent SQL readers |
| `update(context, legacyId, bytes, operationKey)` | Existing published ID, new immutable bytes, idempotency key | Same envelope as create | Required | New revision. No overwriting old bytes; pending concurrent revision conflicts |
| `delete(context, legacyId)` | Existing ID | `true` | Required | SQL tombstone; no remote deletion before SQL commit; rollback restores visibility |
| `inspect(context, legacyId)` | Existing ID | `{legacy_id,revision,pending_revision,deleted}` | Not required | Metadata only; no Nextcloud file ID, content, or storage path |

`operationKey` MUST be generated once per intended document write and
retained by the caller for retry; use `Domain\\OperationKey::generate()`. A
network timeout cannot be assumed to mean the stage failed. Reuse the same
key and bytes after retry, or fail. Returned `legacy_id` is a logical OID
allocated in a reserved numeric range but **not** a Nextcloud file ID.
Historical references without a demonstrable scoped mapping fail closed;
the SDK never guesses by numeric equality.

### Error and legacy-return matrix

`Domain\\AdapterFailure::reason()` returns an invariant code:

| Code | Meaning / required legacy handling |
| --- | --- |
| `DENIED` | No mapped principal, revoked actor, ACL or parent link; never retry as a storage error |
| `UNSUPPORTED` | No declared persistent-document capability for this surface/operation or unknown SPI |
| `INVALID` | Bad reference, payload, operation key, or business key |
| `TRANSACTION_REQUIRED` | The ERP has not started its required business SQL transaction |
| `CONFLICT` | Revision mismatch, incompatible create/update or competing operation |
| `NOT_FOUND` | Missing, foreign-tenant, historical, or cross-backend reference; indistinguishable to caller |
| `STORAGE_FAILURE` | SQL/HTTP/integrity issue; only retry where the caller can safely reuse the idempotency key |

The SPI deliberately raises errors rather than inventing `false`, `0`,
empty bytes, HTTP 404 or a legacy exception. The fork-specific translator
must map these into each *actual* legacy return/error contract at its callsite.
`DBLargeObject` cannot be used as a universal interface for E-Storage
(`allowed[]`, `file_father`, `onlyId`), Laravel Storage (disk and path), or
`bytea` (record/column): each needs explicit evidence of a persistent
document and a verified callsite. The SDK rejects unmapped E-Storage
`allowed[]` and `file_father` and additionally requires the injected
policy to verify them authoritatively.

### Capability declaration example (not enabled in real adapters)

```json
{
  "storage_capabilities": [
    {
      "surface": "large-object-facade",
      "usage": "persistent-document",
      "spi": 1,
      "operations": ["reserve", "create", "read", "update", "delete", "inspect"]
    },
    {
      "surface": "cache",
      "usage": "cache",
      "spi": 1,
      "operations": []
    }
  ]
}
```

This fragment belongs inside the existing `module.json`, and is only a
*synthetic example*: no fork may claim a real capability until its documented
storage inventory and ACL mapping are reviewed. The registry verifies
vocabulary/shape; the SPI gates each call by usage and exact operation.
Other candidate mappings: `direct-pg-lo` requires explicit native
callsite changes, never global PG interception; `e-storage` requires
server-verified principals, `allowed[]` and `file_father`;
`laravel-storage` and `filesystem` require persistent-disk/path
classification; `bytea` requires a specific business document column and
no second binary SQL copy. `temporary`, `cache`, `export`, and
`unsupported` must not be redirected through the persistent-document SPI.

### SQL/Nextcloud read-after-write and recovery proof obligations

1. **Inside the owner SQL transaction**: ledger returns its own staged
   revision; the shared coordinator verifies its checksum and uses a separate
   authenticated pending-read capability to obtain bytes.
2. **Concurrent SQL reader before COMMIT**: MVCC hides the staged journal;
   a new document returns not found, or an update returns the previous
   committed revision. The second reader does not receive a pending-read
   capability.
3. **After COMMIT, before worker**: the staged journal row becomes visible;
   an authorized reader can fetch the immutable version immediately; the
   outbox worker still finalizes it asynchronously.
4. **After ROLLBACK**: no SQL reference or pending journal is visible.
   The orphaned remote stage has neither a public content route nor a
   journal-backed read capability; conservative stale-orphan collection
   deletes it only after the configured safety window.
5. **Crash/retry**: worker leases, idempotent remote finalize and SQL
   `finish` preserve already-committed versions. Dead outbox rows require
   explicit operator intervention. A rollback cannot undo an HTTP upload.
6. **Concurrent reserve/write**: a scoped business-key advisory transaction
   lock plus a unique partial SQL index prevents duplicate active references;
   journal row locks serialize revision numbers. Higher SQL isolation levels
   may require retrying the *whole transaction* on serialization failure.

A privileged `pending-content` Nextcloud v2 endpoint has a separate
short-lived HMAC-SHA256 authorization (30-second clock window) covering the
authenticated installation and municipality, document key, operation key,
and timestamp. The HMAC secret is injected separately into the PHP client
and an admin-controlled Nextcloud system setting, never sent over HTTP.
An ordinary download, even for an authenticated service principal, **still
rejects staged bytes**. The HMAC is not a substitute for SQL authorization:
only trusted coordinator code may generate it after a successful scoped
ledger lookup. The HMAC key must not be exposed to untrusted plugin code or
to municipal clients; isolate and rotate it before any deployment.

### Evidence and release gate

- `tests/phpunit/AdapterSpiV1Test.php`: one synthetic SPI suite for
  DBSeller/Contass/CPD fixture manifests plus negative capability, ACL,
  operation, rollback, idempotency and historical-reference cases.
- `tests/pg-ledger-contract.php`: two independent PostgreSQL connections
  prove same-transaction read, concurrent denial, immediate committed read
  and rollback; synthetic database only.
- `tests/nextcloud/ledger-http-contract.php` and
  `tests/nextcloud/http-contract.php`: disposable SQL/HTTPS/Nextcloud 35
  checks for the signed pending-read path and negative HTTP access.
- `.github/workflows/php-compat.yml` continues to lint/test source under
  PHP 5.6, 7.4, and 8.3 separately from PHPUnit 12.

Do **not** freeze SPI v1 or close #18 solely because these tests pass. It
still requires CI on the exact reviewed commit, independent signatures and
return-value review by all three fork teams, and an explicit security review
of pending-read capability and operational key management. The synthetic
contract is **not** evidence of production readiness or municipal homologation.


### Pinned upstream source contract (PHP 5.6/7.4)

The `.github/workflows/fork-source-runtime.yml` matrix fetches only the
pinned public `DBLargeObject.php` from DBSeller, Contass and CPD; the same
`verify-pinned` registry validates the commit and Git blob. It executes the
**unaltered** legacy class on the fork's PHP version against a disposable
PostgreSQL instance and compares its public static signatures and default
arguments to the existing adapter facade. Cases cover native OID allocation,
implicit/existing SQL transaction, exact bytes written, file extraction,
return types, deletion, rollback and rejection outside transactions. No
municipal dumps, customer instances or deployments are involved.

The shared `LargeObjectBridge` now supports explicit `bind()` to the same
`AdapterServiceV1`, `AdapterContext`, and `PgDocumentLedger` instance used by
`DocumentCoordinator`, and verifies that the ledger owns the **exact** global
ERP PostgreSQL connection. With SPI bound, `criaOID()` allocates the SQL
logical OID (never the Nextcloud `file_id`), and `escrita`, `writeContent`,
`leitura`, `exclusao` and Contass `gerarArquivoOid` translate their legacy
return types into shared journal/HTTPS operations. No manifest automatically
binds it; the unbound old `DocumentClient` remains explicitly experimental
and `ECIDADE_POC_CLIENT=1` is required to access its historical lab API.
It must never be deployed as a production fallback.

The three-way source runtime matrix now also exercises each replacement
facade **through** SPI v1, the real synthetic PostgreSQL journal and the
common reconciler, using the fork's actual PHP interpreter and a fake
remote service. `tests/nextcloud/ledger-http-contract.php` separately drives
the DBSeller facade through SPI, PostgreSQL 11, HTTPS and Nextcloud 35,
including concurrent denial, rollback, principal revocation and delete.
These tests do **not** boot a full municipal ERP or cover direct `pg_lo_*`,
Laravel Storage, E-Storage or `bytea` callsites; no production readiness or
complete fork certification is claimed.

For integration, the ERP must inject the SPI explicitly **after** verifying
its pinned source revision and resolving an authenticated `AdapterContext`.
Bind the same `PgDocumentLedger` used by the coordinator and the transaction
connection exposed as `$conn` by the fork. A trusted `DocumentAccessPolicy`
implementation must validate the session, actor, installation and municipality
against ERP-side authority. The manifest must explicitly authorize the
persistent-document surface; the current vendor manifests do not.

For the shared PHP bootstrap, `ECIDADE_SPI_V1=1` permits loading a
non-production adapter **only** when its reviewed manifest explicitly
declares a SPI v1 persistent-document capability. This is a load-time gate,
**not** an automatic connection, ACL grant, endorsement of the manifest, or
production enablement. The vendor manifests intentionally lack this
capability until their actual ERP callsites are validated. The historical
`ECIDADE_POC_CLIENT=1` lab mode is separate and must never be used to enable
the transactional SPI. Tests prove that the SPI flag alone cannot bypass a
missing capability declaration; shared class loading remains PHP 5.6-safe.

A legacy `DBLargeObject` write has no caller-supplied idempotency key and the
bridge therefore generates one per invocation. Uncertain HTTP results can
leave staged orphans, which are safely reconciled or discarded; they cannot
be treated as exactly-once writes across a retry with a new legacy call.
Callsites requiring deterministic replay must use the SPI's supplied
`operationKey` and durable caller intent, not guess or reuse a Nextcloud ID.
Before closing #18, demonstrate the complete ERP bootstrap and at least one
real business flow with synthetic data, plus explicit ACL and parent mapping
for every capability selected for activation.
