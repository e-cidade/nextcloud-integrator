<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# E-Storage DBSeller: contrato de compatibilidade ainda não implementado

**Análise de código-fonte público, não de ambientes municipais.** Origem:
[e-cidade/e-cidade-DBSeller](https://github.com/e-cidade/e-cidade-DBSeller),
revisão `7fab5ac38b19dbd842ca81a259186c613c90f3c1`.

Interfaces públicas verificadas: `ecidade/app/Domain/Configuracao/Helpers/StorageHelper.php`,
`ecidade/src/Lib/Request/Storage/Curl/Post.php`,
`ecidade/src/Lib/Request/Storage/File.php` e
`ecidade/src/Lib/File/FileEstorage.php`.

## Semântica que a integração deve preservar

| Operação no fork | Contrato observável | Critério de compatibilidade |
|---|---|---|
| `uploadArquivo($caminho, $allowed, $onlyId, $metadata, $fileFather)` | Upload privado, nome original, lista de autorizados, metadados e vínculo de arquivo-pai; retorna objeto `data` ou `data->id` | Persistir bytes somente no Nextcloud; preservar ID lógico, ACL efetiva, metadados permitidos e relação pai/filho |
| `atualizarArquivo($id, $caminho, $allowed, $onlyId, $metadata)` | Substituição referenciada por ID antigo, preservando formato de retorno | Versão imutável no Nextcloud, ID lógico estável, autorização e rollback seguros |
| `downloadArquivo($idStorage)` | Retorna caminho **de um arquivo temporário criado localmente** a partir do download | Produzir arquivo temporário de forma segura, evitar exposição entre usuários, remover após consumo; não confundir cache temporário com segunda cópia persistente |
| `getContentsBase64($idStorage)` | Retorna array `documento` (data URI com Base64) e `content_type` | Respeitar conteúdo, MIME real e mesma forma de retorno |
| `getStorageConfig()` | Recupera configuração E-Storage existente do fork | Traduzir configuração explicitamente; não reutilizar token E-Storage como se fosse credencial Nextcloud |

O objeto de upload do E-Storage também admite campos
`visibility`, `url`, `signers`, `signers_signed` e
`signersRequired`. A fachada `StorageHelper` examinada não expõe
todos esses argumentos diretamente; **não** se deve presumir que toda
outra chamada do E-Storage passa por essa fachada.

## Pontos impeditivos para um override transparente

1. **Autorização:** `allowed[]` contém IDs do domínio E-Storage, não
   necessariamente UIDs Nextcloud. Ignorar a lista, ou enviá-la como
   metadados não verificados, enfraqueceria a segurança do documento.
   O serviço precisa de um mapeamento explícito, com recusa em caso de
   usuário/grupo não reconhecido.
2. **Identidade:** `data->id` é utilizado pelo ERP e não pode ser
   confundido com o `file_id` interno do Nextcloud. Deve manter
   escopo por instalação e município.
3. **Substituição/rollback:** a API antiga atualiza por um mesmo ID.
   Uma nova versão deve permanecer pendente até confirmação local;
   o documento anterior precisa continuar recuperável.
4. **Arquivo-pai:** `file_father` pode representar uma relação
   empresarial relevante. É necessário preservar e validar a mesma
   identidade/escopo, sem confiar em referência recebida por HTTP.
5. **MIME e formatos:** download retorna caminho temporário;
   Base64 retorna array com Data URI. O contrato Nextcloud atual
   devolve bytes, logo a compatibilidade exige tradução explícita.
6. **Metadados e privacidade:** enviar todos os campos originais
   indiscriminadamente ao Nextcloud pode duplicar dados pessoais ou
   fiscais desnecessários. É necessária uma lista de metadados
   permitidos por tipo, com auditoria e política de retenção.

## Aceite mínimo por mantenedor

- Matriz de usuários/grupos e testes negativos de `allowed[]`.
- Upload privado, retorno integral e opção `onlyId` validada.
- Atualização com revisão imutável e leitura de revisão anterior em caso
  de falha/rollback.
- Download de caminho temporário com exclusão segura, e retorno
  Base64 e MIME compatíveis.
- Relação pai/filho com acesso ao pai verificado.
- Testes com uma aplicação DBSeller integral, em ambiente descartável
  e dados sintéticos.
- Nenhum documento novo armazenado persistentemente no PostgreSQL,
  e nenhuma alteração em dados históricos.

**Decisão:** não habilitar um override E-Storage enquanto essas regras
não estiverem demonstradas. O adaptador modular existente deve permitir
acrescentar essa capacidade sem duplicar o núcleo de armazenamento.
