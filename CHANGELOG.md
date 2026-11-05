<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Changelog

All notable changes to this project will be documented here.
Follow SemVer and Conventional Commits; do not generate fake release
notes from unmerged work.

## Unreleased

- PoC encerrada no escopo do contrato documental DBSeller; MVP iniciado.
- App Nextcloud 35 e fixtures de ponta a ponta incluídos no mesmo repositório.
- CRUD, metadados, falhas fechadas e durabilidade após reinício comprovados.

### Added

- Integration contract tests for the PHP client, transactional PostgreSQL metadata and verified HTTPS transport to Nextcloud.

- Shared PHP Nextcloud document client and adapter registry.
- DBSeller, Contass and CPD proof-of-contract adapters.
- PHP 5.6/7.4 compatibility tests and synthetic Nextcloud integration.
- Isolated PHPUnit, PHPStan, PHP-CS-Fixer toolchains and Composer audits.
- Security, DCO, signing, licensing and GitHub workflow quality gates.

### Known limitations

- No production-grade municipal authorization, rollback reconciliation,
  historical migration or universal legacy storage coverage.
