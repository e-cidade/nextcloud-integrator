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

O contrato funcional detalhado, incluindo `allowed[]`,
`file_father`, MIME, retorno `onlyId`, Base64 e arquivos temporários,
está em [ESTORAGE-ADAPTER-CONTRACT.md](ESTORAGE-ADAPTER-CONTRACT.md).
Essas regras ainda **não** são implementadas pelo núcleo documental.

Essa lista **não é um inventário exaustivo** das dezenas de milhares de
arquivos PHP da distribuição. A existência de uma classe não demonstra
quais módulos municipais a executam. Não é correto declarar 100% de
cobertura com base nessa tabela.

## Evidência adicional: documento de processo (Protocolo)

A revisão pública contém `ecidade/model/protocolo/ProcessoDocumento.model.php`.
Os métodos de negócio `salvar`, `download` e `excluir` usam a fachada
`DBLargeObject` quando `p01_estorage` representa armazenamento legado. O
campo `p01_documento` do registro do processo é uma referência OID, que deve
continuar sendo identidade lógica do ERP, não o `file_id` do Nextcloud.

Uma suíte sintética exercita o **modelo original** em PHP 5.6 com DAO de teste,
PostgreSQL 11 e o SPI compartilhado, também sobre HTTPS/Nextcloud 35 na CI.
Não utiliza o bootstrap integral, o DAO original ou uma base municipal. O
resultado cobre o método de negócio selecionado, mas não prova que todas as
rotas HTTP do ERP chegam a essa classe.

Foi identificada uma exceção relevante: `isStorage()` do modelo original faz
comparações frouxas, podendo tratar o texto PostgreSQL `f` como verdadeiro e
consultar E-Storage antes de recorrer à fachada OID. O teste usa um bloqueio
sintético para essa tentativa, sem rede. Corrigir ou autorizar explicitamente
o comportamento no fork é condição para homologação, não um detalhe de teste.

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
