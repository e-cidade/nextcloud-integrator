<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# DBSeller integration: Front 2

Pinned source: `e-cidade/e-cidade-DBSeller@7fab5ac38b19dbd842ca81a259186c613c90f3c1`; verified facade `ecidade/std/DBLargeObject.php` Git blob `a5980256e57e97fdb6b8f6dcdde5844c10cc7d07`.

## Confirmed public entrypoints

| Source | Operations | Classification | Coverage |
| --- | --- | --- | --- |
| `ecidade/std/DBLargeObject.php` | `criaOID`, `escrita`, `writeContent`, `leitura`, `exclusao` | PostgreSQL large-object document storage where used by a business workflow | Experimental facade only |
| `ecidade/app/Domain/Configuracao/Helpers/StorageHelper.php` | `uploadArquivo`, `atualizarArquivo`, `downloadArquivo`, `getContentsBase64` | E-Storage private documents, temporary download, in-memory Base64 | Not covered |
| `ecidade/src/Lib/Request/Storage/Curl/Post.php` | `execute`, `change`, `allowed[]`, `file_father`, signers | Direct E-Storage durable upload/replacement | Not covered |
| `ecidade/src/Lib/File/FileEstorage.php` | `getPath`, `getBase64` | Temporary download, in-memory Base64 | Not covered |
| `ecidade/app/Domain/Configuracao/DocumentosAssinatura/Controller/DocumentosAssinatura.php` | `newSignFile`, `getFile`, `updateSigners`, `toSign` | Real document-signing flow with E-Storage | Candidate flow; not executable through existing facade |
| `ecidade/app/Domain/Configuracao/DocumentosTemplate/ProcessaDocumentoTemplate.php` | DOCX/PDF generation, unlink | Temporary generation/export | Do not redirect blindly |
| `ecidade/DBFileUpload.php` | `move_uploaded_file` | Temporary upload staging until traced | Needs call-chain classification |

This is **not a complete inventory** of direct `pg_lo_*`, `bytea`, or filesystem usage. Use read-only `bin/inventory.php` on the verified public checkout and classify call chains, not just string matches. The manifest verifies one override file, not the whole ERP.

## Blocking shared-core contracts (Front 1)

The shared `DocumentClient` provides `reserve/write/read/inspect/remove` only. It does not carry or enforce E-Storage `allowed[]` principal identity, `file_father`, stable E-Storage IDs, MIME/metadata policy or immutable transactional replacement; the signing controller additionally calls `/files/sign` and `/signers`. No E-Storage override should be enabled until these contracts are safely implemented and authorized. Silently dropping `allowed[]` is a security defect.

## Unmet acceptance gate

Run the pinned DBSeller ERP **actual HTTP/UI municipal flow** in a newly created disposable lab with its own PostgreSQL and Nextcloud, synthetic business records, and no existing installations mounted. Test create/read/update/delete, negative ACL/parent cases, transaction rollback/restart/network failure, MIME/Base64/temp lifecycle. Compare SHA-256 document bytes across Nextcloud, PostgreSQL large objects, relevant `bytea` columns, E-Storage and persistent filesystem before/after each operation; LO count alone does not prove no duplicates. Attach sanitized logs and steps. None of this is demonstrated by this source analysis. Keep `production_ready=false` and issue #6 open.
