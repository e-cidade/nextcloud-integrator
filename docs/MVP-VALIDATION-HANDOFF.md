<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Entrega para validação — sem instalação automática

Este repositório entrega **código-fonte e testes** para avaliação das
empresas que mantêm distribuições do e-Cidade. Não é um produto para
executar em infraestrutura municipal neste estágio.

## Política de segurança da entrega

- Nenhuma ação do repositório realiza deploy, reinicia servidores,
  modifica bancos municipais ou se conecta a infraestrutura de clientes.
- Credenciais, hostnames, endereços internos, caminhos de estações de
  trabalho, dados pessoais e documentos reais não devem ser
  publicados em issues, pull requests, logs ou exemplos.
- O schema SQL em `database/001_opt_in_mvp_schema.sql` **não é uma
  migration automaticamente executada**. Ele deve ser aplicado somente
  a um banco de teste recém-criado e descartável.
- O aplicativo Nextcloud v2 está **desabilitado por padrão** com
  `ecidadelab_mvp_enabled=false`; o código da PoC continua separado.
- O adaptador MVP não faz parte do manifesto de nenhum fork.
  Seu código sintético contém verificações extras de origem e banco
  descartável para impedir ativação acidental.
- O repositório não disponibiliza ferramentas de migração de produção.

## Testes de qualidade sem serviços

```text
php bin/integrator.php test-unit
php tests/poc-server-contract.php       # requer PHP 8.3
php vendor-bin/phpunit/vendor/bin/phpunit -c phpunit.xml.dist
php vendor-bin/phpstan/vendor/bin/phpstan analyse -c phpstan.neon.dist
```

## Teste SQL reproduzível, isolado

A suíte `tests/compose.synthetic.yml` usa banco PostgreSQL efêmero
em tmpfs, rede `internal: true`, **nenhuma porta publicada**, credenciais
sintéticas e descarte completo ao final. O schema pode ser executado apenas em bancos de teste isolados e descartáveis durante a validação; sua aplicação a instalações existentes exige procedimento de implantação próprio e homologado.

```text
# Executar somente no ambiente descartável explicitamente preparado.
MVP_SYNTHETIC_PASSWORD=<senha_de_teste> docker compose -f tests/compose.synthetic.yml config
MVP_SYNTHETIC_PASSWORD=<senha_de_teste> docker compose -f tests/compose.synthetic.yml up --build --abort-on-container-exit --exit-code-from runner
MVP_SYNTHETIC_PASSWORD=<senha_de_teste> docker compose -f tests/compose.synthetic.yml down --volumes --remove-orphans
```

Quando houver um checkout com projeto Compose existente, utilizar
`-p <identificador-unico-de-teste>` para impedir colisão de recursos.
Nenhum desses comandos deve ser executado em host de produção.

## Teste HTTP Nextcloud descartável (sem implantação)

O workflow `.github/workflows/nextcloud-http-contract.yml` cria um
Nextcloud 35 oficial, em rede Docker interna, **sem portas publicadas**,
com duas contas fictícias. Os dados são mantidos somente em um volume
Docker novo e exclusivo daquele teste. Um restart demonstra
persistência; o workflow destrói contêiner, rede e volume ao final.

As fases da suíte PHP em `tests/nextcloud/http-contract.php`
demonstram: bloqueio por padrão; gravação e confirmação imutável;
checksum; acesso negado antes da confirmação; idempotência; recusa
da mesma chave com outros bytes; isolamento entre dois municípios;
limpeza provisória conservadora; reinicialização; revogação de conta.

Uma segunda etapa do mesmo workflow usa o SDK PHP **real** com
PostgreSQL 11 e Nextcloud 35, por um proxy HTTPS com certificado
gerado para aquele teste e verificado pelo cliente. Essa etapa
demonstra 26 verificações de consistência, isolamento municipal,
reconciliação, falhas e ausência de bytes no PostgreSQL.

Mesmo assim, o **ERP integral não foi iniciado**: as transações
empresariais são sintéticas. Não reproduzir em serviços reais.
A execução deve ser realizada exclusivamente pelo job descartável,
que destrói seus próprios volumes, certificados e containers.

## Configuração do app Nextcloud — exemplo fictício

O lado Nextcloud v2 procura configuração de administrador equivalente a:

```php
'ecidadelab_mvp_enabled' => false,
'ecidadelab_principals' => [
    'synthetic-service-a' => [
        'installation' => 'installation-a',
        'tenant' => 'municipio-a',
    ],
],
```

O valor `false` é o padrão seguro; a habilitação requer homologação e autorização explícitas. O serviço já passou
por testes HTTP de autenticação, escopo, persistência após reinício
e revogação de conta em Nextcloud 35.0.2 inteiramente descartável.
Isso não autoriza ativá-lo em instalações existentes ou em um piloto
sem homologação integrada com o ERP, PostgreSQL e cliente HTTPS.

## Critérios de aceite para mantenedores

A empresa deverá confirmar, em contexto autorizado e sintético,
assinaturas e semântica das interfaces do fork, detecção de dados
duplicados, impossibilidade de acesso cruzado, integridade e
recuperação após falha, performance e rollback. Registre os resultados
no PR/issue sem expor nomes internos de máquinas ou dados reais.

**Nenhuma linha da PoC/MVP prova automaticamente conformidade
fiscal, LGPD, retenção, validade de assinatura ou prontidão operacional.**
