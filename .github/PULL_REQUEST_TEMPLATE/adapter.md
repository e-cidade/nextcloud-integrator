<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

## Fork e versão suportados

- Repositório do fork:
- Commit completo:
- PHP / PostgreSQL:
- Mecanismos de armazenamento encontrados:

## Implementação

- [ ] O adaptador utiliza o cliente comum; não duplica acesso ao Nextcloud
- [ ] As fachadas preservam assinaturas, retornos e erros esperados
- [ ] `module.json` inclui Git blob imutável e matriz de capacidades
- [ ] Nenhuma senha, documento real ou dump municipal incluído
- [ ] Canais não cobertos (`pg_lo_*`, E-Storage, bytea, filesystem) declarados em `gaps`

## Evidência dos testes

- [ ] Teste estático `tests/registry-unit.php`
- [ ] `verify` contra commit da distribuição
- [ ] Gravação e leitura por processo independente com SHA-256
- [ ] Exclusão, erros, rollback, permissões e isolamento municipal
- [ ] Ausência de novas cópias documentais no PostgreSQL
- [ ] Regressão do módulo municipal real (descrever abaixo)

**Escopo certificado:**

**Escopo ainda NÃO certificado:**

**Riscos de migração e rollback:**
