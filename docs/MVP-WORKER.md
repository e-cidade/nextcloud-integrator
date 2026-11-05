<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Reconciliation worker — operational contract

The MVP worker is a **manual, opt-in** command. It never runs during ERP
bootstrap, does not apply schema migrations and does not activate any adapter.
Only run it against an explicitly approved disposable environment until the
remaining MVP safety gates and municipality-level homologation are complete.

The entrypoint is `bin/mvp-worker.php`. It requires
`ECIDADE_MVP_EXPERIMENTAL=1`, `ECIDADE_MVP_INSTALLATION`,
`ECIDADE_MVP_TENANT`, `ECIDADE_MVP_NEXTCLOUD_URL`,
`ECIDADE_MVP_USER`, `ECIDADE_MVP_TOKEN`, `ECIDADE_MVP_CA`
and the libpq `PGHOST`, `PGPORT`, `PGDATABASE`, `PGUSER`,
`PGPASSWORD` environment variables. Never put their values into Git,
command-line arguments, process output or logs. Use secret injection and a
database role restricted to the metadata journal.

For a single bounded pass, invoke `php bin/mvp-worker.php --max=100`.
The worker claims only operations belonging to its configured installation
and municipality. Each claimed operation is finalized remotely and then
acknowledged in PostgreSQL. On a remote error, the outbox preserves a
sanitized failure code and bounded exponential retry; crashed leases are
reclaimable. A pass stops when there is no immediately successful work and
**does not** prove the queue is empty. Schedule repeated passes externally
only after explicit approval; never run two configurations against the same
business transaction connection.

Operational checks must inspect counts of `staged`, `processing`,
`finalized`, and `dead` operations by authorized scope without logging
document keys, business references, tokens, document bytes, or raw HTTP
responses. Dead operations require operator investigation, not silent
discard. Staged orphan cleanup is a separate, conservative operation.

## Unresolved correctness boundaries

A SQL COMMIT is not atomic with a Nextcloud finalize. The durable outbox
provides recovery, not distributed atomicity. Reads of a newly committed
journal row may fail until reconciliation; guaranteeing immediate
post-commit visibility requires an adapter-level transaction boundary
contract. A staged version must never be made readable merely to hide this
delay. Historical OID allocation cannot be certified against ERP-created
future identifiers without a shared allocator or explicit collision guard.
The transport currently buffers documents under an 8 MiB experimental
limit and is **not** a streaming solution. These limitations prohibit MVP
production activation.
