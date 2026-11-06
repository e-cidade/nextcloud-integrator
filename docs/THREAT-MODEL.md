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
2. Adapter → Nextcloud API: authenticated HTTPS with checksums and a
   server-admin-defined installation/tenant mapping for the service UID;
   request headers are corroborating claims, not the authority. The ERP
   actor/ACL binding remains the injected `DocumentAccessPolicy`'s duty.
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
| Staged-document access capability stolen | Uncommitted bytes disclosed | Short-lived scoped HMAC + independent authenticated service principal, separate secrets, ERP-side authorization, no URL tokens |
| Pending-read HMAC key rotated unsafely | Temporary inability to read committed SQL-pending versions | Coordinate key replacement on Nextcloud and SDK; fail closed during the transition, never reuse the service app password |

## Pending-read security boundary

The privileged `pending-content` request requires both a dedicated service
principal (mapped on the Nextcloud server to exactly one installation and
municipality) **and** a separate HMAC key. Its 30-second proof binds that
installation, municipality, immutable document key, operation key and current
time; the signature is sent in headers, not URLs. The SQL-facing gateway
must first authorize the ERP actor and find an SQL-visible operation. A
valid signature alone does not grant another municipality access; the
synthetic HTTP suite explicitly tests a different principal with a correctly
signed proof and expects denial. Ordinary downloads never expose staged
content.

The HMAC key is a privileged global test capability, not a per-user ACL or
an authorization substitute. Keep it in isolated secret configuration, use a
separate random value of at least 32 bytes, do not expose it to municipal
PHP templates or logs, and coordinate SDK/Nextcloud key rotation. The current
MVP endpoint supports a single active key, so rotation can briefly interrupt
pending reads and must fail closed rather than falling back to unsafely
published content. Per-actor authorization and any zero-downtime rotation
procedure still require deployment-specific security review before a rollout.

## Release blockers

No multi-tenant deployment until: full storage path inventory,
verified municipal identity and permissions, robust transaction
recovery, object ID mapping, history migration, signed/retention tests
and controlled failure injection pass in a real municipal workflow.

**A passing synthetic contract test is not approval for production.**
