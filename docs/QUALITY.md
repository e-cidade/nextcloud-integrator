<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Quality gates and their sources

The baseline was compared against current
`LibreCodeCoop/suitecrm-mcp`, `LibreCodeCoop/akaunting-mcp`
and the shared governance catalog `LibreCodeCoop/.github`.

## Mandatory gates

| Gate | Source | Behavior |
|---|---|---|
| PHP compatibility | `php-compat.yml` | PHP 5.6, 7.4, 8.3 syntax and unit contracts |
| PHPUnit | `phpunit.yml` | Offline behavior, module and security regressions |
| PHPStan | `phpstan.yml` | Level 4, no ignored baseline errors |
| PHP-CS-Fixer | `php-style.yml` | PSR-12, no file rewrites in CI |
| Composer Audit | `composer-audit.yml` | Strict manifests and root/dev-tool lock advisories |
| Docker Smoke | `docker-smoke.yml` | No-network, read-only, nonroot PHP CLI |
| REUSE | `reuse.yml` | SPDX licensing and license copies |
| DCO | `dco.yml` | Each human-authored commit has author-matching sign-off |
| Conventional Commits | `commits.yml` | Each human-authored commit has valid semantic subject |
| Signed Commits | `signed-commits.yml` | GitHub verifies each PR commit cryptographically |
| Actionlint | `actionlint.yml` | Workflow YAML/expressions |
| Zizmor | `zizmor.yml` | GitHub Actions security analysis |
| Secrets | `secrets.yml` | Gitleaks content and Git history |
| Public Scope | `scope.yml` | Deny committed secrets, dumps and lab artifacts |
| Workflow Policy | `workflow-policy.yml` | Full SHA Actions pins and verified version comments |

`ShellCheck` is **not applicable** to this PHP-only repository,
because no project shell scripts are maintained.

Do not introduce a fake `ci-required` aggregator: the workflows
above are independent, and `needs` cannot see other workflows.
If a protected-branch aggregate is later required, implement a
real coordinator with verified fail/skip behavior, per
`LibreCodeCoop/.github/docs/repository-governance.md`.

## Local verification

```text
php bin/integrator.php test-unit
php vendor-bin/phpunit/vendor/bin/phpunit -c phpunit.xml.dist
php vendor-bin/phpstan/vendor/bin/phpstan analyse -c phpstan.neon.dist
php vendor-bin/php-cs-fixer/vendor/bin/php-cs-fixer fix --dry-run --config=.php-cs-fixer.dist.php
php tools/check-workflow-policy.php
php tools/check-scope.php
```

Local PHP version compatibility and integrated storage tests must be
repeated after any bridge/transport change.

## GitHub governance

Commit signatures, DCO, conventional messages, Code Owner approval,
resolved conversations, protected default branch and non-fast-forward
prevention must be configured in the target GitHub repository.
Creating workflow files **does not** apply or enforce branch rulesets.

The LibreCode organizational workflow catalog and rulesets remain
owned by `LibreCodeCoop/.github`. Do not copy organization policy
into the application or enable remote governance changes without
explicit review and authorization.

## Current verification level

PHPUnit, PHPStan, PHP-CS-Fixer and cross-version syntax are local
laboratory results. Workflow files are **prepared**, not previously
executed through GitHub Actions for this SDK because the SDK is not
yet published as a standalone repository. Report any check not
executed rather than asserting a green GitHub status.
