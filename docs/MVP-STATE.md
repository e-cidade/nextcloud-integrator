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
- API HTTP v2 exercitada contra Nextcloud 35.0.2 real, isolado e
  descartável: duas contas/municípios, recusa por padrão, 28 verificações
  documentais, recuperação após reinicialização e revogação de conta.
- Cliente PHP real integrado ao PostgreSQL 11 e Nextcloud 35 por
  **HTTPS com validação de certificado sintético**, em rede interna,
  com 26 verificações: commit/rollback, versões imutáveis,
  recuperação de worker, falha de rede, isolamento de município
  e ausência de bytes documentais no PostgreSQL. A suíte roda no
  mesmo workflow `nextcloud-http-contract.yml` em serviços
  exclusivamente descartáveis.
- Ponte sintética para interface legada, **não instalada em nenhum ERP de produção**.
- Gate E2E do Protocolo na DBSeller original: instalação PHP 5.6, banco público
de demonstração PostgreSQL 16, login HTTP autenticado e RPC original para
criar/listar/baixar/atualizar/excluir documentos, com Nextcloud 35 HTTPS,
hashes e reconciliação. Executado somente em containers descartáveis.

## Ainda não demonstrado

- Navegação integral de navegador/UX do Protocolo original; o teste E2E
  executa RPC HTTP autenticado e uma seleção de departamento controlada.
- Certificação de outras superfícies e módulos municipais da DBSeller,
  além do fluxo de documento do Protocolo testado com fixtures sintéticas.
- Cobertura de E-Storage, `pg_lo_*` diretos e outras rotas documentais.
- Migração de dados históricos, recuperação de versões legadas e
  desativação/retorno transparente em sistemas existentes.
- Streaming de arquivos grandes, performance, retenção, assinatura,
  segurança e operabilidade. A execução da CI deve ser conferida no
  commit correspondente antes do merge; testes locais não substituem
  uma execução de CI bem-sucedida.

O tratamento dessas lacunas depende de testes adicionais e da
validação dos mantenedores. Nenhuma issue do MVP deve ser encerrada
somente porque o código experimental compilou ou passou em fakes.
