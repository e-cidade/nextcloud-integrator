<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Integrador e-Cidade → Nextcloud (PHP)

**Estado:** PoC documental concluída em laboratório; **MVP em desenvolvimento, não apto para produção**.

A PoC possui código nos dois lados da conexão: SDK PHP e app Nextcloud 35
em [nextcloud-app/ecidadelab/](nextcloud-app/ecidadelab/). O escopo e
evidências estão em [docs/POC-EXIT-CRITERIA.md](docs/POC-EXIT-CRITERIA.md);
o plano de MVP em [docs/MVP-ROADMAP.md](docs/MVP-ROADMAP.md) e a
reprodução em [poc/README.md](poc/README.md). A publicação do código
experimental não autoriza implantação municipal.

Este é um projeto **PHP** com um único cliente documental e módulos de
compatibilidade para versões/forks diferentes do e-Cidade. Não há scripts Bash
na API, no CLI ou na suíte de testes do integrador. O Docker Compose serve
apenas para levantar os serviços e executar PHP em hosts sem PHP instalado.

## Estrutura

```text
src/
  DocumentClient.php           # HTTPS e operações Nextcloud compartilhadas
  LargeObjectBridge.php        # lógica comum de OID legado
  AdapterRegistry.php          # seleção e validação de módulos
  StandardLargeObjectFacade.php
modules/
  dbseller/                    # classe pública DBSeller
  contass/                     # método adicional gerarArquivoOid()
  cpd/                         # reutiliza o contrato DBSeller
bin/integrator.php             # CLI PHP, publicado como binário Composer
tests/*.php                    # testes PHP (unitários e integração)
adapter.schema.json            # contrato dos módulos v2
nextcloud-app/ecidadelab/       # app servidor Nextcloud 35 (somente lab)
poc/fixtures/                  # provas DBSeller e cenários de falha
composer.json                  # pacote PHP/PSR-4
compose.yaml                   # apenas infraestrutura opcional do CLI
```

Cada módulo tem `module.json` e os arquivos específicos da distribuição.
O núcleo só reconhece **tipos de hook**, nunca nomes de fornecedores.
Os hooks v2 implementados são `php-file-override`, `source-signature`
e `php-bootstrap`. O mesmo cliente documental é reutilizado em todos.

## CLI nativo, em uma máquina com PHP

```bash
php bin/integrator.php list
php bin/integrator.php inspect contass
php bin/integrator.php scaffold novo-fork dbseller
php bin/integrator.php verify novo-fork /caminho/do/fork
php bin/integrator.php plan novo-fork
php bin/integrator.php select novo-fork
php bin/integrator.php test-unit
```

`scaffold` cria um módulo **não aprovado** e o seletor o recusa até
revisão. `verify` compara o Git blob do arquivo de origem com o
manifesto; `detect` sugere adaptadores para um código já conhecido, mas
não ativa nada automaticamente.

## CLI sem instalar PHP no host (Docker)

Em qualquer máquina com Docker Compose:

```bash
cd nextcloud-integrator

docker compose run --rm cli list
docker compose run --rm cli test-unit
docker compose run --rm cli scaffold novo-fork contass
docker compose run --rm cli select dbseller

# Fonte dos três forks públicos montada só para o laboratório:
docker compose -f compose.yaml -f compose.lab.yaml run --rm cli detect /sources/contass
docker compose -f compose.yaml -f compose.lab.yaml run --rm cli verify contass /sources/contass
```

`compose.yaml` apenas executa `bin/integrator.php` dentro de um
contêiner PHP. Não contém lógica documental. Usa rede desabilitada,
filesystem raiz read-only e usuário sem privilégios. Para verificar
outros forks, monte o respectivo repositório em modo read-only na
configuração Compose local, não publique documentos ou segredos.

## Testes de integração reais, todos escritos em PHP

O orquestrador PHP `tests/nextcloud-suite.php` executa processos PHP
independentes e confirma o resultado de **cada** operação. Para executar
na base de demonstração local, com Nextcloud, PHP legado e PostgreSQL
já iniciados:

```bash
cd /caminho/do/laboratorio-dbseller

# DBSeller: bootstrap.php real e classe substituída via Docker:
docker compose run --rm --no-deps -e TEST=1 php \
  php /opt/ecidade-integrator/tests/nextcloud-suite.php legacy

# Contass: fachada em PHP 7.4 (não o ERP inteiro):
docker compose -f compose.yaml -f compose.compat74.yaml --profile compat \
  run --rm --no-deps compat74 \
  php /opt/ecidade-integrator/tests/nextcloud-suite.php facade

# CPD: fachada em PHP 5.6 (não o ERP inteiro):
docker compose run --rm --no-deps -e ECIDADE_ADAPTER=cpd php \
  php /opt/ecidade-integrator/tests/nextcloud-suite.php facade
```

Os scripts de teste validam SHA-256, recuperação em processo novo,
exclusão e ausência de novos Large Objects no PostgreSQL. No cenário
DBSeller também verificam os vínculos de negócio, rollback,
compensação e falhas fechadas.

**Não confundir testes de contrato Contass/CPD com homologação das
aplicações completas.** A DBSeller testada carrega o bootstrap real,
mas não um formulário municipal completo.

## MVP experimental — código para avaliação

O MVP está em desenvolvimento **sem instalação automática**, sem deploy e
sem acesso a ambientes de empresas. O código adicionado permanece desligado
por padrão: as novas rotas Nextcloud v2 exigem habilitação explícita e
identidade de serviço mapeada pelo administrador; a ponte PHP MVP está
bloqueada para uso fora de bancos de teste sintéticos. A PoC e seus testes
continuam presentes.

A arquitetura e seus namespaces estão descritos em
[docs/MVP-ARCHITECTURE.md](docs/MVP-ARCHITECTURE.md), as verificações e
limitações em [docs/MVP-STATE.md](docs/MVP-STATE.md), e as instruções de
entrega aos fornecedores em
[docs/MVP-VALIDATION-HANDOFF.md](docs/MVP-VALIDATION-HANDOFF.md).

O inventariador PHP `bin/inventory.php` analisa **somente código-fonte
público explicitamente selecionado**, sem conectar à rede ou ao banco.
O levantamento inicial de mecanismos não cobertos está em
[docs/DBSELLER-STORAGE-INVENTORY.md](docs/DBSELLER-STORAGE-INVENTORY.md).

Os testes SQL utilizam `tests/compose.synthetic.yml`: apenas banco
descartável, rede interna e nenhuma porta exposta. Os testes de contrato
do novo Nextcloud v2 ainda não substituem uma homologação HTTP real;
revisões pendentes, autorização municipal e recuperação após falhas
permanecem critérios de aceite do produto.

## Para quem mantém um fork

Leia [CONTRIBUTING.md](CONTRIBUTING.md). O fluxo é criar um módulo,
registrar commit e hashes do código suportado, implementar sua fachada ou
bootstrap e acrescentar testes PHP. Um pull request deve alterar o módulo
e seus testes, sem copiar código do cliente comum.

O pacote PHP tem entrypoint `bin/integrator.php`, autoload PSR-4 e
`composer.json`. O CI executa os testes com interpretadores PHP 5.6,
7.4 e 8.3; esses interpretadores antigos são exclusivamente de
compatibilidade/testes.

## O que falta para produção

A integração não intercepta `pg_lo_*` nativos fora das fachadas;
não implementa driver Laravel/Flysystem, E-Storage e `bytea` em todos
os módulos. Existem implementações experimentais para referência independente,
escopo de autenticação e reconciliação de COMMIT/ROLLBACK, mas ainda
não estão conectadas nem homologadas em um ERP completo. Permanecem
pendentes a compatibilidade de referências históricas, migração,
streaming, retenção e testes HTTP integrais do app v2.

Os três módulos têm `production_ready=false`. O aplicativo
Nextcloud existente é um laboratório com tenant e usuário fixos.
Não usar em sistemas municipais produtivos.

## Governança e qualidade de engenharia

Este SDK segue padrões da LibreCode documentados em
[docs/QUALITY.md](docs/QUALITY.md), com a lista de verificações aplicadas,
seus resultados e limites em [docs/QUALITY-AUDIT.md](docs/QUALITY-AUDIT.md).
Consultar também [SECURITY.md](SECURITY.md),
[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md),
[docs/DEVELOPMENT.md](docs/DEVELOPMENT.md),
[docs/OPERATIONS.md](docs/OPERATIONS.md) e
[docs/THREAT-MODEL.md](docs/THREAT-MODEL.md).

O repositório institucional LibreCodeCoop/.github é a fonte central de
políticas e templates compartilhados. Os resultados locais constam do relatório de auditoria. Verifique
os status reais de GitHub Actions para a revisão publicada.
