<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# DBSeller vendor ERP in GitHub Actions

## Why another gate?

The original Protocolo model, generated DAO and RPC already have PHP 5.6,
PostgreSQL 11, shared SPI and Nextcloud 35 HTTPS tests. That suite explicitly
replaces the ERP bootstrap, municipal schema and authentication with synthetic
seams. This complementary job runs the **actual upstream Docker installation**
and attempts a real authenticated HTTP login against its published example SQL.

## What is executed

The reusable `.github/workflows/dbseller-erp-bootstrap.yml` pins the exact
DBSeller Git SHA (including a fork PR head, attested against the requested SHA).
It downloads the full source, verifies its installer assets as Git blobs and
verifies the compressed SQL archive. It builds the unmodified vendor Dockerfiles
(`docker/php56-apache`, `docker/database`), decompresses the **publicly
shipped example database** inside an ephemeral checkout, runs
`ecidade/docker/install.sh`, and checks outcomes rather than trusting the
script's success message.

Checks include PHP 5.6, Composer vendor autoload, the generated ERP config,
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

**This is a real vendor ERP bootstrap/authentication gate, not yet a full
ERP-to-Nextcloud Protocolo CRUD test.** Its PostgreSQL 16 database is
independent of the PostgreSQL 11 synthetic SPI contract. Passing both jobs
does not establish that real ERP login/session, business schema, SQL journal,
authorized actor mapping and Nextcloud document operations run in a *single*
request. That remains the issue #6 acceptance gate. Do not turn on production
integration or close the issue on the basis of this job.

Because the vendor ships default demo credentials and unpinned apt/Composer
sources, this is **only** for GitHub-hosted disposable runners, never a public
application endpoint or a municipal data restore. Do not publish the dump or
the generated Laravel keys and do not use customer backups.
