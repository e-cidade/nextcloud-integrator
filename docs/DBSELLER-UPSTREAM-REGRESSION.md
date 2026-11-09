<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# DBSeller upstream regression automation

## Purpose and triggers

A DBSeller change must be validated against the **exact public Git commit**,
without silently moving the production manifest, installing a server, or
replacing the shared SPI. There are two complementary triggers:

1. DBSeller `push` and `pull_request` invoke the public, reusable
   integrator workflows, passing the source commit SHA and, for fork-based pull requests, the PR
   number. The fetcher resolves GitHub's public PR head ref and verifies it
   matches the supplied exact SHA (fail closed if the PR moved). The caller is implemented
   in `e-cidade/e-cidade-DBSeller/.github/workflows/nextcloud-integrator-compatibility.yml`.
   No token, shared secret, or write permission is required.
2. The integrator's `dbseller-upstream-watch.yml` resolves the current
   `main` SHA once daily and calls **the same** workflows. It is a
   scheduled safety net, not an instant webhook: GitHub may delay or
   skip scheduled events. It also supports manual `workflow_dispatch`.

Both tests fetch the same four original PHP files from the specific upstream
tree, verify their Git blob hashes, and reject mutable ref inputs. The
source preflight also compares the legacy `DBLargeObject` API with the SPI
facade. The manifests and runtime production flags are untouched.

## Executable coverage

| Test | Technology | Checks |
| --- | --- | --- |
| Native source regression | PHP 5.6, PostgreSQL 11 | Source syntax, legacy public API, original generated DAO/model, SQL behavior, storage classification, transaction rollback, ACL denial and logical OIDs |
| Integrated remote regression | Docker, PostgreSQL 11, Nextcloud 35, verified HTTPS | Same candidate DAO/model, shared SPI, outbox and worker, read-after-write, immutable revisions, checksum, actor revocation, municipality isolation, rollback, delete and no new PG large object/bytea for the synthetic path |
| Original Protocolo HTTP RPC (synthetic contract) | PHP HTTP server in disposable Docker; original RPC/model/generated DAO, synthetic session/bootstrap, PostgreSQL 11 and Nextcloud 35 HTTPS | Create, read, list, change metadata and delete; no persistent native PG LO/bytea; reject unauthenticated requests and unsupported E-Storage mode |
| Original DBSeller ERP Protocolo E2E | Vendor PHP 5.6 Apache, restored vendor PostgreSQL 16 example schema and real HTTP login/session, Nextcloud 35 verified HTTPS | Original authenticated RPC CRUD, SHA-256 preserved after metadata update, same document bytes, SQL outbox and reconciliation, no new native PG LO in selected flow |
| Integrator baseline | Existing pinned suites, PHPUnit, PHPStan and workflow security checks | The known public revisions and all shared core contracts remain independently reproducible |

The current pinned DBSeller source is the merged revision `e309c84546486bf54449e91eb079d19e94ae497a` with
model blob `251ac8e5087c1e29280189eccd89ab452e54ca00`, including the corrected OID inclusion and mandatory document SHA-256 persistence.
The historical `4935e0514c8b206853f071d60612647503cedb99`
model remains explicitly **legacy-buggy**; new revisions must pass the
fixed semantic contract, including insertion with the textual PostgreSQL `f`. A source change is not
automatically approved or repinned merely because CI is green.

The candidate executes as PHP in a *disposable* CI runner: do not use
privileged secrets or production credentials in these workflows.
Only `contents: read` is permitted, Git credentials are not persisted,
Compose exposes no host ports, and all volumes are destroyed.

## Demonstrated flow and remaining scope

The full original DBSeller ERP now boots from its public source and example
database, authenticates a user via the vendor's actual HTTP login, and runs
the Protocolo document lifecycle through its original RPC and DAO with the
shared Nextcloud HTTPS adapter. This is an end-to-end **single-flow laboratory**
test, rather than the earlier synthetic route-only contract.

The test creates one temporary process and stages one PDF. It simulates the
post-login module/window choice, rather than driving the complete browser UI.
Production actor/tenant mapping, E-Storage `allowed[]`/`file_father`, other
persistent filesystem and direct `pg_lo_*` paths, exhaustive storage checks
and crash injection for this original ERP request are still not certified.
Passing this gate does not activate production integration or establish
universal municipal-document coverage. Browser-level automation may be
added later for user-interface behavior, but is not needed to prove this
original RPC flow.

## Activation

The integrator and DBSeller caller workflows have both been merged.
The upstream PR and main push checks have been observed running successfully.
Enable the caller check as a required branch check through the repository's
reviewed ruleset; this is not automatically changed by source-code CI. GitHub schedules are not
a guarantee of an immediate reaction to every change; DBSeller push/PR
events are the primary trigger.

All output uses synthetic identifiers. No deployment, municipal data,
migration, secret publication or production activation is performed.
