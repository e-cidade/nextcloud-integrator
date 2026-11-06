<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Architecture and compatibility boundaries

The e-Cidade → Nextcloud integration has a **single document service**,
a small shared PHP transport layer and versioned adapters.

```text
DBSeller / Contass / CPD / new fork
  |  module.json: upstream, SHA, hooks, capabilities
  +-- PHP class override or framework bootstrap
       |
       LargeObjectBridge (legacy method/return translation)
       |
       AdapterServiceV1 (explicit DI, scoped authorization)
       |
       DocumentCoordinator + PgDocumentLedger (ERP transaction, outbox)
       |
       NextcloudMvpClient (HTTPS; staged immutable versions)
       |
       Nextcloud 35 test API (not enabled in vendor deployments)
       |
       Nextcloud persists document bytes; SQL stores references only
```

## What remains in e-Cidade

The existing business references and legacy OID-typed foreign keys.
The intent is **zero new document bytes** in PostgreSQL; removing business
keys is a separate data-model migration and is not a requirement.

## Adapter contract

Manifest v2 recognizes `php-file-override`, `source-signature`
and `php-bootstrap`; a module is tied to immutable upstream Git blobs.
No fork-specific conditionals belong in the shared transport.

The `DBLargeObject` contract is demonstrated against three public forks;
the underlying application bootstrap is tested only for DBSeller.
Different mechanisms (direct `pg_lo_*`, Laravel Filesystem, E-Storage,
`bytea`) need **specific adapters and runtime validation**.

## Critical design constraints

The Nextcloud internal `file_id` is **not** a safe universal legacy OID.
A durable `installation+municipality+backend+legacy_id` index is required.
External object writes and PostgreSQL commits are not atomic; immutable
objects, a state machine, idempotency and reconciliation must come before
production deployment.

The current application API and metadata headers are laboratory-only.
Production tenancy must bind each service identity to an authorized
municipality and actor, rather than trusting arbitrary HTTP headers.

See [docs/THREAT-MODEL.md](THREAT-MODEL.md) for security gates and
[docs/QUALITY.md](QUALITY.md) for executable verification.
