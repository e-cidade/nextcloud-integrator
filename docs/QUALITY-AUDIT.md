<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Auditoria de padrões LibreCode — e-Cidade / Nextcloud Integrator

Data: 2026-11-03. Escopo: apenas o pacote PHP em `integrator/`.

## Referências institucionais efetivamente examinadas

- `LibreCodeCoop/suitecrm-mcp`: `composer.json`, `.php-cs-fixer.dist.php`,
  `phpstan.neon.dist`, workflows de PHPUnit, PHPStan, PHP Style, DCO,
  Conventional Commits, Composer Audit, Docker Smoke e REUSE, Dependabot e REUSE.toml.
- `LibreCodeCoop/akaunting-mcp`: PHPUnit isolado, PHPStan, formatter,
  `scope.yml`, `secrets.yml`, Actionlint, Zizmor, Shellcheck e
  validação de commits.
- `LibreCodeCoop/.github`: `governance.config.json`,
  `docs/repository-governance.md`,
  `docs/cross-repository-automation.md`,
  `docs/dependency-update-policy.md` e `workflow-templates/`.

GitHub público: https://github.com/LibreCodeCoop/.github .
As práticas foram adaptadas, não copiadas indiscriminadamente:
este projeto mantém compatibilidade PHP 5.6/7.4 enquanto as ferramentas
de QA modernas estão isoladas no `vendor-bin/`.
A existência do catálogo da organização significa que futuras alterações
centralizáveis devem ser consolidadas em `LibreCodeCoop/.github`, com PR
de sincronização para consumidores, em vez de divergir localmente.

## Evidência de execução local — aprovada

| Verificação | Evidência |
|---|---|
| PHPUnit | 20 testes, 50 asserções, PHP 8.5 com Git disponível |
| PHPStan | Nível 4, zero erros |
| PHP-CS-Fixer | PSR-12, 0 arquivos pendentes no dry-run |
| PHP legado | Unitários e syntax PASS em PHP 5.6.40 e 7.4.33 |
| PHP contemporâneo | Unitários e syntax PASS em PHP 8.3.36 |
| Manifestos | 3 adapters schema v2 + Composer root/dev manifests válidos |
| Origem das versões | SHA Git por origem e rejeição de fonte desconhecida |
| CI policy | 27 referências `uses` externas com hash SHA-40 e anotação |
| Actionlint | 15 workflows sintaticamente válidos |
| REUSE | Conforme REUSE 3.3 em árvore distribuível limpa: 78/78 arquivos |
| Gitleaks | Nenhuma chave encontrada em árvore distribuível limpa |
| Composer Audit | Nenhum advisory nas 3 toolchains bloqueadas |
| Root Composer | Zero pacotes externos; auditoria sem dependências aplicáveis |
| Public source scope | PASS, sem dump, arquivo .env ou artefatos laboratoriais |
| Signed-commit checker | Parser GitHub `verification.verified` testado offline |
| DCO/Conventional | PHPUnit de Git temporário com sucesso e rejeição |
| DBSeller | Bootstrap original + gravação/leitura/rollback/limpeza contra Nextcloud PASS |
| Contass | PHP 7.4, testes de fachada sintética, não ERP completo, PASS |
| CPD | PHP 5.6, testes de fachada sintética, não ERP completo, PASS |
| Invariantes PG | 6 Large Objects históricos antes/depois, 0 vínculos de teste restantes |

## Preparado, mas ainda NÃO validado no GitHub

- Os 15 workflows não foram executados pela plataforma GitHub neste SDK:
  **não existe repositório Git separado publicado** ainda.
- `zizmor.yml`, `reuse.yml`, `secrets.yml`, `signed-commits.yml` e
  os demais só poderão ter seus resultados remotos verificados em um PR.
- Verificação criptográfica de assinatura exige respostas reais da API GitHub
  para os commits do PR, não apenas o parser PHP.
- Gitleaks sobre histórico Git e DCO no PR exigem histórico do repositório;
  a execução local cobriu apenas fixtures temporárias e arquivos atuais.
- CODEOWNERS e regras organizacionais de branch protection precisam
  ser aplicadas/verificadas na configuração remota; um arquivo local não
  aplica regras de revisão.
- O verificador REUSE local inspecionou uma cópia limpa da distribuição,
  excluindo `vendor-bin/*/vendor`, caches e logs — conforme a estrutura
  esperada de um checkout Git sem dependências instaladas.

## Decisões e exclusões justificadas

- Sem `ShellCheck`: o SDK não versiona scripts Bash de projeto.
- Sem `ci-required` artificial: a política LibreCode estabelece que
  `needs` não agrega checks de workflows independentes. Um agregador
  precisa ser desenvolvido e validado **antes** de proteger branches com ele.
- GitHub Actions com acesso mínimo (`contents: read`) e referências
  externas por SHA completo; sem publicação por push em PR não confiável.
- Dependabot para Composer e GitHub Actions; Renovate somente para Docker,
  evitando PRs redundantes. Atualizações major e merge requerem revisão.
- Licença escolhida para esta PoC: AGPL-3.0-or-later, igual aos MCPs
  auditados, mas projetos futuros precisam decidir a licença por contexto.
- Docker Compose é infraestrutura; CLI, quality scripts e testes do
  integrador são implementados em PHP.
- Não realizar merge, publicação de releases ou deploy automaticamente.

## Pendências bloqueantes do produto (fora do alcance da qualidade CI)

1. Chamadas diretas `pg_lo_*` não são interceptadas.
2. Escritas Nextcloud não são revertidas por `ROLLBACK` PostgreSQL.
3. Autorização multitenant real e vínculo de usuário/município ausentes.
4. IDs históricos e migração ainda sem estratégia certificada.
5. Contass/CPD ainda não passaram por fluxos completos dos ERPs.
6. E-Storage, Laravel/Flysystem, `bytea`, arquivos grandes e streaming pendentes.

Portanto, **checks de qualidade local aprovados não equivalem a produto
pronto para produção ou integração universal do e-Cidade**.

## Próxima fase institucional

Recomenda-se versionar a Skill transversal de criação de projetos no
repositório organizacional `LibreCodeCoop/.github` com a mesma fonte
de templates/workflows já existente. A Skill não deve ser mantida
neste integrador PHP; ela poderá ser criada e validada separadamente,
em PR revisável e com testes contra pelo menos dois perfis de linguagem.
