<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# MVP - plano de implementacao apos PoC

Objetivo do primeiro MVP: um fluxo documental real e opt-in na DBSeller, recuperavel sob falhas, sem copiar novos arquivos no PostgreSQL. Contass e CPD permanecem adaptadores experimentais ate homologacao propria.

## Fase 1 - modelo de referencia e transacoes

Substituir o atalho Nextcloud file_id = legacy oid por mapeamento estavel e escopado: instalacao, municipio, backend e identificador legado. Definir estados persistentes de reserva, escrita, confirmacao, revisao e exclusao, com idempotencia e compensacao duravel. Testar queda do processo e indisponibilidade em todos os pontos entre gravacao remota, commit SQL e leitura/exclusao. Impedir perdida de documento confirmado e acesso a ID incorreto.

## Fase 2 - seguranca e implantacao

Remover usuario bench e tenant hardcoded, autenticar servico e autorizar municipio/ator com escopo real no Nextcloud. Segredos fora do repositorio e logs; rotacao, auditoria, limites, streaming e observabilidade. Documentar implantacao no mesmo servidor ou em servidor HTTPS remoto e rollback seguro.

## Fase 3 - cobertura funcional DBSeller

Inventariar os acessos a DBLargeObject, pg_lo_* diretos, E-Storage, bytea e filesystem. Adaptar apenas interfaces efetivamente usadas, preservando um nucleo compartilhado. Homologar ao menos um fluxo municipal real de criacao, leitura, alteracao e exclusao com dados sinteticos, incluindo referencias historicas e caminho de migracao.

## Fase 4 - validacao e publicacao operacional

Executar CI real no GitHub, ativar revisoes e regras de branch protection conforme a governanca e-Cidade, corrigir todas as falhas, validar carga e latencia p95/p99 e documentar instalacao e recuperacao. Nenhum deploy municipal sem auditoria de seguranca, aceite e plano de reversao.

## Fora do primeiro MVP

Certificacao simultanea de Contass e CPD e migracao total dos acervos municipais. Expandir fornecedores antes de estabilizar a primeira implantacao ampliaria o risco e repetiria engenharia ainda nao validada.
