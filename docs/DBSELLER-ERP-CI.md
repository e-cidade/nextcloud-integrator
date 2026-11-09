<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# DBSeller vendor ERP in GitHub Actions

## Why another gate?

The original Protocolo model, generated DAO and RPC already have PHP 5.6,
PostgreSQL 11, shared SPI and Nextcloud 35 HTTPS tests. That suite explicitly
replaces the ERP bootstrap, municipal schema and authentication with synthetic
seams. The complementary job runs the **actual upstream Docker installation**, authenticates
through the vendor's original HTTP login against its published example SQL,
and exercises the original Protocolo RPC with the shared Nextcloud 35 HTTPS
adapter in the same disposable application.

## What is executed

The reusable `.github/workflows/dbseller-erp-bootstrap.yml` pins the exact
DBSeller Git SHA (including a fork PR head, attested against the requested SHA).
It downloads the full source, verifies its installer assets as Git blobs and
verifies the compressed SQL archive. It builds the unmodified vendor Dockerfiles
(`docker/php56-apache`, `docker/database`), decompresses the **publicly
shipped example database** inside an ephemeral checkout, runs
`ecidade/docker/install.sh`, and checks outcomes rather than trusting the
script's success message.

Checks include PHP 5.6, Composer vendor autoload, the generated ERP config, the original missing prerequisite XML copied
from its vendor `.dist` file, the original `libs/db_acessa.php` promoted from
its vendor `.dist` for version comparison, a narrowly writable Apache log directory,
PostgreSQL 16 with LATIN1 encoding, at least 100 application tables, presence
of the Protocolo/user tables, the real Apache login page, and an authenticated
session using the vendor-documented demo account. It compares native PG large
object counts before/after the login smoke. The script prints aggregate schema
metadata only: never dump rows, installer logs, password values or session
cookies to GitHub Actions logs or artifacts.

The source install uses a randomized database password, no host ports and
disposable containers. Composer needs outbound HTTPS during installation, so
the web container temporarily has an egress network; the job removes it before
HTTP testing. The original vendor Dockerfiles and composer dependencies are
NOT reproduced by a synthetic replacement. Unknown/private dependencies and
vendor-installer failures must **fail CI**, not silently be skipped. The
installer's `|| true` and `|| echo` paths are followed by independent
schema, dependency and HTTP checks.

## Triggering and limits

The integrator runs this on its own PR/push, and the DBSeller repository
calls this reusable workflow with the exact source SHA on every PR/main push.
Existing native PostgreSQL/SPI and Nextcloud HTTPS workflows remain separate
required compatibility checks. Workflow inputs may be specified for an
explicit manual public revision test.

**This is a real vendor ERP Protocolo document-lifecycle gate.** After an
original PHP 5.6 login and department authorization using the restored
PostgreSQL 16 example database, it executes the original authenticated RPC
for document create/list/download/metadata update/delete against Nextcloud 35
HTTPS. Assertions cover SHA-256 persistence on create/update, exact downloaded
bytes, SQL metadata/outbox, remote reconciliation, deletion, and no additional
native PostgreSQL large objects or `bytea` field for this selected flow.

The test inserts a disposable Protocolo business fixture, uses a temporary PDF,
and simulates selecting the module/window after authenticating through the
actual ERP. It does **not** exercise full browser navigation, municipality-
specific production ACL mapping, E-Storage `allowed[]` and `file_father`,
other module storage paths or an exhaustive filesystem audit. This is a
**single-flow lab proof**, not a production cutover or universal ERP coverage;
`production_ready=false` remains required. The independent PostgreSQL 11
synthetic SPI contract is still separate and complementary.

Because the vendor ships default demo credentials and unpinned apt/Composer
sources, this is **only** for GitHub-hosted disposable runners, never a public
application endpoint or a municipal data restore. Do not publish the dump or
the generated Laravel keys and do not use customer backups.
