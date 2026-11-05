<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Operations, rollout and rollback

**Not production-ready.** This guide defines constraints for a future pilot.

## Security prerequisites

Provision a service identity bound to the municipality, actor and legal
access boundaries. Keep credentials outside Git and application logs.
Enforce Nextcloud TLS verification, per-tenant access and audit trails.

## Deployment model

Pin a supported fork version and execute
`php bin/integrator.php verify <module> <source>` before any
read-only overlay or framework integration. Prefer a feature flag
and a single supported storage route. Unknown versions must fail closed.

## Data and transactions

Never persist document bytes in both e-Cidade and Nextcloud as the
steady state. Temporary files are permissible only with restricted
permissions, documented retention and cleanup.

The current Proof of Concept does **not** include a distributed
transaction coordinator. Nextcloud object write can succeed before
PostgreSQL rolls back. Pilot deployment requires a durable
reconciler, idempotent retries, object versioning, deletion after commit
and exercises for interruption at every failure window.

## Recovery and rollback

A feature flag rollback must preserve access to documents written
after activation. Turning off the integration alone is not a complete
rollback when PostgreSQL contains only logical references. Maintain an
explicit read compatibility and migration-back plan, if permitted by
the data owner.

## Monitoring

Track request p50/p95/p99, error class, retry rates, authorization
denials, upload/download size, object checksum and orphan reconciliation,
without logging file contents or personally identifying metadata.

## Environment restrictions

PHP 5.6 and 7.4 are end-of-life, used only for legacy compatibility
testing. Municipal production requires an environment-specific risk
assessment, maintained runtime strategy and explicit authorization.
