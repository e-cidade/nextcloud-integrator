<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Estado de implementação — evidência versus pendências

A PoC documentada em `docs/POC-EXIT-CRITERIA.md` segue
preservada. O MVP é uma **implementação experimental desativada**.

## Código presente

- Contratos e classes com namespaces para referências, versionamento,
  transações, outbox, reconciliação e transporte Nextcloud.
- Novo app Nextcloud v2 com verificação de escopo vinculada à
  conta autenticada e à configuração administrativa; desligado
  por padrão.
- Schema SQL independente de bytes, nunca aplicado automaticamente.
- Inventariador de código-fonte público, sem rede e com saída
  relativa, sem expor paths internos.
- Testes de comportamento e segurança com fakes e PostgreSQL
  isolado/descartável.
- Ponte sintética para interface legada, **não instalada em nenhum ERP**.

## Ainda não demonstrado

- Funcionamento real dos novos endpoints Nextcloud v2 num serviço
  Nextcloud 35 criado especificamente para esse MVP.
- Instalação de prova em aplicação DBSeller completa e fluxos municipais
  reais com dados sintéticos.
- Cobertura de E-Storage, `pg_lo_*` diretos e outras rotas documentais.
- Migração de dados históricos, recuperação de versões legadas e
  desativação/retorno transparente em sistemas existentes.
- Streaming de arquivos grandes, performance, retenção, assinatura,
  segurança/operabilidade e CI remota deste novo incremento.

O tratamento dessas lacunas depende de testes adicionais e da
validação dos mantenedores. Nenhuma issue do MVP deve ser encerrada
somente porque o código experimental compilou ou passou em fakes.
