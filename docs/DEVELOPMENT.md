<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Development workflow

## Working on the SDK

Prefer small, cohesive PRs. Identify the legacy contract and a
reproducible test before implementing a new adapter.

This repository contains **PHP application code and PHP tests**.
No Bash scripts are required to install, select or test adapters.

```text
php bin/integrator.php list
php bin/integrator.php inspect dbseller
php bin/integrator.php test-unit
php bin/integrator.php scaffold novo-fork dbseller
```

On a computer with Docker but no PHP:

```text
docker compose run --rm cli list
docker compose run --rm cli test-unit
docker compose -f compose.yaml -f compose.lab.yaml run --rm cli verify contass /sources/contass
```

## Quality tooling

Production dependencies are compatible with PHP 5.6.40. Modern developer
dependencies are installed **separately**, never inside municipal runtimes:

```text
composer install
composer install -d vendor-bin/phpunit
composer install -d vendor-bin/phpstan
composer install -d vendor-bin/php-cs-fixer

composer test-unit
composer test:phpunit
composer analyse
composer cs:check
composer audit --locked
```

The tool-specific `composer.lock` files are tracked and independently
audited. PHP-CS-Fixer targets PSR-12 and is run on a modern interpreter;
always run syntax tests on **PHP 5.6 and 7.4** after formatting.

## End-to-end lab

After the isolated PostgreSQL and Nextcloud Docker services are started
in `../legacy`, run the PHP integration executable in the correct
runtime container. Exact commands are listed in the root
[README](../README.md).

DBSeller tests the real legacy bootstrap; Contass and CPD test the
public interface facades with synthetic data, not complete municipal ERPs.

## Commits and reviews

Use Conventional Commits (`feat(adapter): ...`) with matching DCO
`Signed-off-by` and **cryptographically signed** commits.
LibreCode's signing MCP should be used for authored commits.
Do not squash or merge automatically; maintain a linear, reviewed
history and wait for explicit authorization.

All PRs must state scope, limitations, evidence and rollback.
Use native `CODEOWNERS`; do not hardcode reviewers in generators.
Consult [QUALITY](QUALITY.md) and [SECURITY](../SECURITY.md).
