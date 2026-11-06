<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Public e-Cidade forks: candidate storage callsites

## Provenance and method

This is a **read-only, source-level census**, not an assertion that all matches
are persistent documents or that an ERP installation is homologated. Run on
5 November 2026 using `php bin/inventory.php --source=<checkout> --public-checkout`,
with network access disabled in the PHP runner and only full public checkouts
mounted read-only. `vendor`, `node_modules`, `.git` and caches are excluded.
The scanner counts matching **source lines** (including references in comments
and migrations); a line can match several categories. It does not execute PHP,
parse dataflows, contact PostgreSQL, Nextcloud, ERP or any server, or identify
which paths are active in a specific municipality.

| Public fork / exact source commit | PHP/SQL files scanned | Direct `pg_lo_*` | `DBLargeObject::` | E-Storage text/API | Framework `Storage` | `bytea` | Generic file I/O |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| DBSeller `7fab5ac38b19dbd842ca81a259186c613c90f3c1` | 33,256 | 157 | 78 | 572 | 1 | 11 | 989 |
| Contass `90081bf22993292ad197134fe1617cace968a984` | 35,080 | 205 | 48 | 28 | 7 | 2 | 1,139 |
| CPD `39cce22ed59cda56edbbeb3b746e692ab7d66fcb` | 41,326 | 176 | 68 | 883 | 9 | 12 | 1,465 |

All three reports returned `truncated=false`; respectively 743, 758 and
1,023 files contained at least one candidate. These numbers are **not**
counts of distinct document flows, database columns, records, vulnerabilities
or actual duplicate writes. In particular, E-Storage and `bytea` searches
also match comments and schema/migration definitions. The Contass source pin
includes its upstream Protocolo OID correction; CPD and DBSeller pins refer
to the pre-correction public revisions, since their source PRs require review.

## Classified flow versus unclassified surfaces

| Source / business path | Classification | Current proof and required action |
| --- | --- | --- |
| `*/model/protocolo/ProcessoDocumento.model.php` (DBSeller lives under `ecidade/`) | **Persistent business attachment** using logical OID | Original model `salvar/download/alterar/excluir` traverses shared SPI, PostgreSQL metadata journal and Nextcloud 35 HTTPS in disposable CI. Uses synthetic SQL DAO, not original ERP bootstrap. No native LO/bytea bytes produced *in that fixture*. |
| `std/DBLargeObject.php` and `ecidade/std/DBLargeObject.php` | **Legacy storage facade** | Supported shared SPI bridge. A facade override is not proof that native PostgreSQL calls elsewhere are redirected. |
| `libs/db_stdlib.php` / `ecidade/libs/db_stdlib.php` and legacy utility code | **Direct native LO primitives**; business purpose varies | Explicit per-callsite classification and replacement required. Do not globally intercept `pg_lo_*` or redirect all Large Objects. |
| Attendance `ate1_tarefaanexos001.php` and `ate1_tarefaanexos002.php` (under `resources/legacy/` in Contass) | **Likely persistent attendance attachments**; *not tested* | Direct LO hits in all three distributions. Trace real callers, references, ACL and transaction ownership before selecting an adapter. |
| Procurement `lic3_infolicanexo002.php`, `lic4_liclicitaata001.php`, `model/AcordoDocumento.model.php` | **Potential persistent contract/procurement documents**; *not tested* | Direct LO hits outside the shared facade. Must define scoped document identity, historical reads and return semantics per entrypoint. |
| `app/Domain/Configuracao/Helpers/StorageHelper.php` and `src/Lib/Request/Storage/Curl/Post.php` in DBSeller/CPD | **E-Storage persistent-document candidate** | Requires separate verified `allowed[]`, `file_father`, MIME, revision and authenticated-actor contracts. Not equivalent to OID facade. Never drop these permissions in translation. |
| Contass `app/Services/FileService.php` and `app/Support/Storage/S3UrlPresigner.php` | **Framework/object storage candidates** | Decide whether each disk/path stores durable documents, signed URLs, temporary exports or caches; preserve Laravel semantics. Not certified by Protocolo test. |
| `db/migrations/*` / `integracao_externa/*/db/*` containing `bytea` | **Schema definitions; not demonstrated document payloads** | Inspect business columns and read/write callsites before classifying as durable document storage. A textual `bytea` hit is insufficient. |
| `DBFileUpload.php`, temporary `tmp/` exports and generic `file_put_contents` | **Temporary, output or unknown until traced** | Must not be indiscriminately redirected to persistent Nextcloud storage. The Protocolo fixture cleans up its exports. |

## Blocking observed source semantics

**DBSeller and CPD:** the pinned Protocolo `isStorage()` uses loose
comparisons; the nonempty PostgreSQL boolean string `f` can be interpreted
as true. The shared original-model fixture confirms an undesired E-Storage
attempt during download. CPD also skips the OID tombstone during deletion.
Source fixes are proposed in `e-cidade/e-cidade-DBSeller#2` and
`e-cidade/e-cidade-CPD#1` but require protected-branch review.
The fixture deliberately blocks external E-Storage calls and, for CPD,
demonstrates the deletion bypass before normalizing *test-only* state.
No vendor deployment should use this as a workaround.

**Contass:** a different OID property was assigned during creation.
This was corrected upstream in `e-cidade/e-cidade-Contass#22` and the
integrator source pin updated in `e-cidade/nextcloud-integrator#24`.
The contract now checks immediate `getOID()` against the business SQL row.

## Next acceptance tests

1. Reproduce a full original **ERP request -> bootstrap -> generated DAO ->
   shared SPI -> actual Nextcloud HTTPS -> worker** using synthetic municipal
   records and no existing installation or credentials. The current test
   uses real business-model methods but a synthetic SQL DAO.
2. For each chosen business flow, trace **every** persistent write/read path
   and classify direct `pg_lo_*`, E-Storage, `bytea` and filesystem I/O.
   Verify no persistent duplicate bytes across PostgreSQL LO catalogs,
   relevant business `bytea` columns, E-Storage and permanent file paths.
3. Demonstrate historical-reference read handling, business principal and
   ACL checks (including E-Storage `allowed[]`/`file_father`), worker crash,
   retries, and rollback/restart with source-accurate calls.
4. Reproduce opt-in installation from zero, key rotation, backup/restore,
   measurable p95/p99 and safe rollback that does **not** orphan documents
   already persisted only in Nextcloud.

The presence of many native calls **does not justify** universal interception
or declaring the adapter unusable. It determines the specific work necessary
to certify each selected flow. All vendor manifests remain
`production_ready=false`; this inventory performs no migration, deployment
or server operation.
