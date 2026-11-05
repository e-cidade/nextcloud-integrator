<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Decisao: conclusao da prova de conceito

Data: 5 de novembro de 2026. Escopo da PoC: demonstrar armazenamento documental do contrato DBLargeObject de uma DBSeller real com PostgreSQL 11 e PHP 5.6, usando Nextcloud 35 separado via HTTPS, com dados sinteticos.

## Criterios de aceite atendidos

- Os dois lados do protocolo estao incluidos no pacote: integrador PHP e app Nextcloud ecidadelab.
- O codigo de origem DBSeller e identificado por manifesto com commit e hash; o servidor PostgreSQL nao foi alterado.
- A DBSeller executa seu bootstrap real, grava referencia oid na tabela sintetica de negocio e armazena os bytes no Nextcloud.
- Create, Read, Update, Read, Delete foram exercitados por processos PHP independentes com SHA-256, mesma identidade documental e revisao incrementada.
- A quantidade de Large Objects nativos no PostgreSQL nao aumentou.
- Os metadados de documento sao persistidos e recuperados no Nextcloud.
- Falhas de token, tenant e indisponibilidade Nextcloud foram recusadas sem fallback silencioso ao banco.
- Um arquivo gravado permaneceu recuperavel apos reiniciar os servicos Nextcloud e Nginx.
- Os contratos PHP Contass e CPD passaram em testes sinteticos, com ressalva de nao serem ERPs completos.
- Testes locais de PHP, qualidade estatica, auditoria de dependencias, REUSE e Actionlint aprovados na validacao anterior.

**Conclusao:** encerrar a PoC funcional *nesse escopo delimitado* e iniciar desenvolvimento do MVP sob feature flag. A PoC nao comprova integracao universal ou prontidao para producao.

## Limitacoes expressas

A referencia OID usa o file_id Nextcloud na PoC, inseguro para identificadores historicos e varios municipios. O rollback PostgreSQL nao desfaz a escrita/exclusao remota; a limpeza foi demonstrada manualmente nos testes, sem reconciliador duravel. A autenticacao da PoC usa o usuario bench e tenant fixo, nao controle multitenant real. Chamadas pg_lo_* diretas, E-Storage, bytea, Laravel Storage e fluxos completos dos ERPs Contass/CPD ainda nao estao cobertos. Ha limite de 8 MiB e buffering em memoria.

Estas limitacoes SAO bloqueadores do MVP/piloto, nao motivos para afirmar que o contrato documental DBSeller nao foi demonstrado.

Codigo da PoC pode ser publicado como **experimental**, nunca como release de producao. Consulte MVP-ROADMAP.md.
