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
- API HTTP v2 executada contra Nextcloud 35.0.2 real, isolado e
  descartável: autorização de duas contas/municípios, 28 verificações
  documentais, recusa por padrão, persistência após reinicialização
  e revogação de uma conta. Teste reproduzível no workflow
  `nextcloud-http-contract.yml`. O tráfego é HTTP apenas dentro
  do contêiner de teste; o transporte PHP HTTPS completo ainda
  precisa de validação integrada.
- Ponte sintética para interface legada, **não instalada em nenhum ERP**.

## Ainda não demonstrado

- Teste integrado da aplicação DBSeller com o cliente PHP real sobre
  HTTPS, PostgreSQL e Nextcloud v2 na mesma transação empresarial.
- Instalação de prova em aplicação DBSeller completa e fluxos municipais
  reais com dados sintéticos.
- Cobertura de E-Storage, `pg_lo_*` diretos e outras rotas documentais.
- Migração de dados históricos, recuperação de versões legadas e
  desativação/retorno transparente em sistemas existentes.
- Streaming de arquivos grandes, performance, retenção, assinatura,
  segurança/operabilidade e CI remota do teste HTTP v2, que depende
  dos resultados de GitHub Actions para a revisão publicada.

O tratamento dessas lacunas depende de testes adicionais e da
validação dos mantenedores. Nenhuma issue do MVP deve ser encerrada
somente porque o código experimental compilou ou passou em fakes.
