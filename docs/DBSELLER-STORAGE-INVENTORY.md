<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Inventário inicial de armazenamento — DBSeller pública

**Fonte exclusivamente pública:** `e-cidade/e-cidade-DBSeller` no commit
`e309c84546486bf54449e91eb079d19e94ae497a`. Não houve acesso a
servidores, bancos ou documentos de terceiros. Nenhum caminho absoluto
de computador é documentado aqui.

## Interfaces verificadas por leitura dos arquivos públicos

| Arquivo relativo no repositório DBSeller | Mecanismo | Situação no integrador |
|---|---|---|
| `ecidade/std/DBLargeObject.php` | `pg_lo_create`, `pg_lo_open`, `pg_lo_write`, `pg_lo_export`, `pg_lo_unlink` | Fachada PHP coberta pela PoC |
| `ecidade/app/Domain/Configuracao/Helpers/StorageHelper.php` | Cliente E-Storage: upload, mudança, download, base64 | **Não coberto**. Exige adaptação de interface separada |
| `ecidade/DBFileUpload.php` | `move_uploaded_file` para pasta temporária | Precisa rastrear ciclo de vida; arquivo temporário não é necessariamente storage definitivo |
| `ecidade/prot4_processodocumento.RPC.php` | RPC original de Protocolo: `salvarDocumento`, `carregarDocumentos`, `download`, `excluirDocumento` | Coberto tanto no contrato sintético quanto em um teste HTTP autenticado com o ERP PHP 5.6 original, banco de demonstração PostgreSQL 16, DAO original e Nextcloud 35 HTTPS |
| `ecidade/routes/api/patrimonial/protocolo.php` / `app/Domain/Patrimonial/Protocolo/Controller/Processo/ProcessoDocumentoController.php` | API Laravel para download via `StorageHelper` | Superfície **E-Storage distinta**; não é comprovada pelo fluxo OID/RPC |

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

## Nova evidência: DAO gerado original

O teste `tests/upstream/protocolo-generated-dao-contract.php` executa o
DAO gerado original `ecidade/classes/db_protprocessodocumento_classe.php`
em conjunto com o modelo original, ambos verificados por hash, com
PostgreSQL 11 sintético e o SPI compartilhado. Foi observado que o modelo
envia `"false"` como string para `p01_estorage`, e o DAO a
converte em SQL `TRUE` por coerção booleana do PHP. O documento foi
armazenado pela fachada OID, mas identificado como E-Storage no registro.
No código DBSeller, a exclusão ainda executa o tombstone do OID.
A correção da classificação, gravação booleana e criação com `'f'`
foi incorporada em `e-cidade/e-cidade-DBSeller#2` e repinada no manifesto.
O desvio histórico permanece como evidência, não como comportamento aceito na revisão atual.

Esse experimento utiliza **o DAO real**, mas um esquema simplificado e
sessão/auditoria simuladas: não equivale ao bootstrap completo do ERP.
O manifesto permanece `production_ready=false`.

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

## Prova do fluxo Protocolo e limites

A CI executa a instalação real e descartável da DBSeller, com PHP 5.6,
PostgreSQL 16 e o banco de demonstração original. Após autenticação HTTP
real, cria um processo de teste, aciona `salvarDocumento`, `download`,
`carregarDocumentos` e `excluirDocumento` na rota RPC original e verifica
uma atualização de metadados. O fluxo passa pelo DAO original e pela fachada
`DBLargeObject` adaptada ao SPI compartilhado, com Nextcloud 35 HTTPS.
O teste confere hash SHA-256, outbox, leitura e exclusão; também compara
contagens de Large Objects antes/depois e confirma ausência de coluna
`bytea` na tabela do documento selecionado. O arquivo local usado como
entrada e a cópia temporária de download são removidos no final.

**Cobertura não demonstrada:** a ausência de cópias permanentes em todos os
diretórios do ERP, outras rotas `pg_lo_*`, `bytea`, E-Storage com `allowed[]`
e `file_father`, navegação completa de navegador e autorização de instalações
municipais reais. A classificação dessas superfícies continua necessária,
mas elas não impedem a evidência específica do ciclo de Protocolo.
A contagem de LO não é prova universal da ausência de duplicação.
O teste adicional pesquisa cópias com o mesmo tamanho e SHA-256 no web root
do ERP enquanto o documento existe e depois da exclusão; exclui apenas os
arquivos conhecidos de entrada e download transitórios. Esse scanner reforça
a evidência para **este fluxo**, sem provar ausência de conteúdo transformado
em outros sistemas ou storage externo.

### Matriz de caminhos para o fluxo documentado

| Ação do Protocolo | Código público executado | Classificação dos dados |
| --- | --- | --- |
| Criar anexo (`salvarDocumento`) | `prot4_processodocumento.RPC.php` → `ProcessoDocumento::salvar/incluir` → `DBLargeObject::criaOID/escrita` → DAO `cl_protprocessodocumento::incluir` | Documento definitivo no Nextcloud; OID, hash e outbox como metadados PostgreSQL; PDF de entrada somente temporário |
| Listar (`carregarDocumentos`) | RPC → consulta do processo/DAO | Metadados relacionais, sem nova gravação de bytes |
| Baixar (`download`) | RPC → `ProcessoDocumento::download` → `DBLargeObject::leitura` | Lê o documento definitivo no Nextcloud; entrega cópia temporária para download |
| Alterar descrição (`salvarDocumento` com ID) | RPC → `ProcessoDocumento::alterar` → DAO `alterar` | Atualiza metadados; OID e SHA-256 preservados |
| Excluir (`excluirDocumento`) | RPC → `ProcessoDocumento::excluir` → DAO `excluir` + `DBLargeObject::exclusao` | Remove referência do ERP e marca exclusão no armazenamento remoto |
| Fora deste fluxo | `StorageHelper`/E-Storage, chamadas `pg_lo_*` diretas, Laravel Storage e outros I/O de arquivos | Exigem testes próprios; não são certificados por esta homologação |

**Não executar nenhuma dessas etapas em servidor de cliente.** O
projeto entrega código para que os mantenedores o avaliem em seus
próprios ambientes controlados.
