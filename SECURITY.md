<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Security policy

This repository is a **laboratory proof of concept**, not production-ready
document storage for municipalities. All published adapters are
`production_ready=false`.

## Reporting vulnerabilities

Report suspected vulnerabilities through GitHub's **private vulnerability
reporting** feature for this repository when available, or contact the
maintainers privately through an established LibreCode channel. Do not open
public issues exposing credentials, municipal records or proof-of-concept
exploits containing real data.

Security issues require private triage, reproducible regression tests,
authorized fixes, and coordinated disclosure. Never publish an advisory or
merge a sensitive fix without maintainer approval.

## Trust boundaries

- PHP runtimes as old as 5.6/7.4 are supported **only for source compatibility
  in isolated tests**, not endorsed for internet-facing deployment.
- The Nextcloud app currently trusts a laboratory service credential and
  static tenant context; production municipality authorization is unfinished.
- SQL transactions do not span the Nextcloud object API. Recoverable
  reconciliation is not yet implemented.
- Documents and audit metadata must not be logged or copied to the legacy DB.
- Only reviewed adapter modules can execute PHP bootstrap hooks.
- No production data should be uploaded to CI, test fixtures or an isolated test
  environment without explicit authorization and an anonymization plan.

See [docs/THREAT-MODEL.md](docs/THREAT-MODEL.md) and
[docs/OPERATIONS.md](docs/OPERATIONS.md) before even considering pilot use.
