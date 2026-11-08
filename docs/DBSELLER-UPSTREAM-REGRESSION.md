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
   integrator workflows, passing the source commit SHA. The caller is proposed
   in `e-cidade/e-cidade-DBSeller/.github/workflows/integrator-compatibility.yml`.
   No token, shared secret, or write permission is required.
2. The integrator's `dbseller-upstream-watch.yml` resolves the current
   `main` SHA once daily and calls **the same** workflows. It is a
   scheduled safety net, not an instant webhook: GitHub may delay or
   skip scheduled events. It also supports manual `workflow_dispatch`.

Both tests fetch the same three original PHP files from the specific upstream
tree, verify their Git blob hashes, and reject mutable ref inputs. The
source preflight also compares the legacy `DBLargeObject` API with the SPI
facade. The manifests and runtime production flags are untouched.

## Executable coverage

| Test | Technology | Checks |
| --- | --- | --- |
| Native source regression | PHP 5.6, PostgreSQL 11 | Source syntax, legacy public API, original generated DAO/model, SQL behavior, storage classification, transaction rollback, ACL denial and logical OIDs |
| Integrated remote regression | Docker, PostgreSQL 11, Nextcloud 35, verified HTTPS | Same candidate DAO/model, shared SPI, outbox and worker, read-after-write, immutable revisions, checksum, actor revocation, municipality isolation, rollback, delete and no new PG large object/bytea for the synthetic path |
| Integrator baseline | Existing pinned suites, PHPUnit, PHPStan and workflow security checks | The known public revisions and all shared core contracts remain independently reproducible |

The historical model blob `4935e0514c8b206853f071d60612647503cedb99`
is explicitly characterized as **legacy-buggy** while the upstream
`e-cidade/e-cidade-DBSeller#2` fix is pending. Any other model blob
must pass the **fixed** semantic contract. A source change is not
automatically approved or repinned merely because CI is green.

The candidate executes as PHP in a *disposable* CI runner: do not use
privileged secrets or production credentials in these workflows.
Only `contents: read` is permitted, Git credentials are not persisted,
Compose exposes no host ports, and all volumes are destroyed.

## Not yet demonstrated (issue #6 remains open)

This is **component-level integration with a real remote backend**, not
full end-to-end municipal certification. Original Protocolo HTTP routing,
ERP bootstrap, full municipal database schema, authenticated business
sessions, E-Storage `allowed[]`/`file_father`, other persistent filesystem
paths, crash injection for the ERP route, and real actor-to-Nextcloud
mapping remain untested. None of these checks should be described as
passed or marked as skipped-success.

A future browser-level Behat/Playwright smoke test is justified only after
a fully licensed, synthetic, reproducible DBSeller installation can boot
and exercise actual Protocolo user interactions. Until then, PHP 5.6
contract and HTTPS integration tests provide more relevant evidence.

## Activation

Merge the integrator changes first, then the DBSeller caller workflow.
The caller points at the integrator's `main` reusable workflows; before
that merge the GitHub Actions `workflow_call` entrypoints do not exist
there. Add the upstream caller check as a required branch check once it
has been observed on `main` and on a test PR. GitHub schedules are not
a guarantee of an immediate reaction to every change; DBSeller push/PR
events are the primary trigger.

All output uses synthetic identifiers. No deployment, municipal data,
migration, secret publication or production activation is performed.
