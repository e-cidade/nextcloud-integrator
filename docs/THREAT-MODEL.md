<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Threat model: e-Cidade document integration

## Protected assets

Municipal records, PDFs, business metadata, source identity,
authorizations, user tokens, Nextcloud files and the integrity of
the existing business references in PostgreSQL.

## Trust boundaries

1. Legacy ERP → adapter PHP: potentially unsupported runtimes and
   untrusted document names; a legacy bootstrap may mask exceptions.
2. Adapter → Nextcloud API: authenticated HTTPS with checksums; the
   laboratory tenant is supplied in a header and is not verified
   against a real municipal identity.
3. PostgreSQL → Nextcloud: independent persistence systems without
   shared atomic transactions.
4. Maintainer → module registry: a bootstrap hook can execute PHP;
   only audited modules tied to immutable source blobs may load.
5. CI → repository: untrusted pull requests, dependency graph,
   Actions and artifacts must have minimal permissions.

## Known blocking threats

| Threat | Consequence | Required mitigation |
|---|---|---|
| Caller selects another municipality header | Cross-tenant disclosure | Bind tenant to authenticated service identity and server-side ACL |
| PostgreSQL rollback after remote PUT | Orphan document / wrong visibility | Durable state machine and reconciler |
| Remote DELETE before SQL rollback | Irrecoverable document loss | Immutable versions and deferred delete |
| Legacy OID collides with Nextcloud file ID | Wrong document retrieval | Scoped lookup by installation/tenant/backend/ID |
| `pg_lo_*` bypasses PHP facade | Silent duplicate storage or missing reads | Runtime coverage, instrumentation and localized adapters |
| Unreviewed module executes PHP | Arbitrary code execution | Module review, source signature, least privilege and registry gating |
| Old runtime exposed to public | Exploitable EOL software | Segregated compatibility testing and supported deployment strategy |
| Wrong metadata or incomplete uploads | Corrupt/legal evidence | Hash, size, retention, signature and immutable audit trail |
| Credentials in GitHub log/artifact | Credential compromise | Gitleaks, secret storage and data scope gate |

## Release blockers

No multi-tenant deployment until: full storage path inventory,
verified municipal identity and permissions, robust transaction
recovery, object ID mapping, history migration, signed/retention tests
and controlled failure injection pass in a real municipal workflow.

**A passing synthetic contract test is not approval for production.**
