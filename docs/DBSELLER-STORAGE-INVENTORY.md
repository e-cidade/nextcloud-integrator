<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Inventário inicial de armazenamento — DBSeller pública

**Fonte exclusivamente pública:** `e-cidade/e-cidade-DBSeller` no commit
`7fab5ac38b19dbd842ca81a259186c613c90f3c1`. Não houve acesso a
servidores, bancos ou documentos de terceiros. Nenhum caminho absoluto
de computador é documentado aqui.

## Interfaces verificadas por leitura dos arquivos públicos

| Arquivo relativo no repositório DBSeller | Mecanismo | Situação no integrador |
|---|---|---|
| `ecidade/std/DBLargeObject.php` | `pg_lo_create`, `pg_lo_open`, `pg_lo_write`, `pg_lo_export`, `pg_lo_unlink` | Fachada PHP coberta pela PoC |
| `ecidade/app/Domain/Configuracao/Helpers/StorageHelper.php` | Cliente E-Storage: upload, mudança, download, base64 | **Não coberto**. Exige adaptação de interface separada |
| `ecidade/DBFileUpload.php` | `move_uploaded_file` para pasta temporária | Precisa rastrear ciclo de vida; arquivo temporário não é necessariamente storage definitivo |

Essa lista **não é um inventário exaustivo** das dezenas de milhares de
arquivos PHP da distribuição. A existência de uma classe não demonstra
quais módulos municipais a executam. Não é correto declarar 100% de
cobertura com base nessa tabela.

## Inventariador não invasivo

`bin/inventory.php` faz uma leitura local de **código-fonte
explicitamente selecionado**, sem conectar à Internet, banco ou ERP:

```text
php bin/inventory.php --source=/caminho/de/um/checkout-publico --public-checkout
```

Produz JSON com os caminhos **relativos** e números de linha de candidatos
`DBLargeObject`, APIs `pg_lo_*`, E-Storage, Laravel Storage,
`bytea` e I/O de arquivos. Não reproduz conteúdo de documentos nem
informações privadas; usa somente arquivos da cópia pública revisada.

Mesmo com o inventário completo, cada ocorrência precisa ser
classificada como arquivo definitivo, temporário, cache, relatório,
exportação ou anexo de negócio, conforme o fluxo real.

## Próximas provas exigidas pela issue #6

1. Selecionar um módulo e fluxo municipal do código **público** que
   crie e recupere documentos.
2. Identificar os pontos reais de leitura e escrita, inclusive E-Storage.
3. Construir **fixtures sintéticas**, sem cópias de bancos municipais.
4. Executar o fluxo da aplicação real em uma instalação descartável,
   com autorização expressa do mantenedor do sistema.
5. Instrumentar PostgreSQL, Nextcloud e filesystem para detectar
   gravações persistentes inesperadas. Contagem de LO, isoladamente,
   não prova ausência de cópias em outro lugar.
6. Registrar a matriz de cobertura e os mecanismos fora do escopo.

**Não executar nenhuma dessas etapas em servidor de cliente.** O
projeto entrega código para que os mantenedores o avaliem em seus
próprios ambientes controlados.
