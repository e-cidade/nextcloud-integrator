<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Contribuir com um módulo para outro fork e-Cidade

A integração é **PHP**. Não crie scripts Bash, microserviços ou cópias
do cliente Nextcloud para cada fornecedor. Cada fork deve implementar
somente suas diferenças de contrato e instalação.

## 1. Registre a origem

Identifique repositório, commit completo, versão PHP/PostgreSQL e
mecanismos documentais: `DBLargeObject`, `pg_lo_*` direto,
E-Storage, Laravel Storage, `bytea`, arquivos locais.
Um arquivo com interface idêntica não garante que o fork esteja
integralmente coberto.

## 2. Gere um módulo PHP

```bash
php bin/integrator.php scaffold prefeitura-x dbseller
```

Sem PHP no host: `docker compose run --rm cli scaffold prefeitura-x dbseller`.

O comando cria `modules/prefeitura-x/module.json` e a fachada de
exemplo. O módulo nasce com `tested=false`, `production_ready=false`
e hash provisório inválido. Nunca é habilitado por engano.

## 3. Declare os hooks que realmente precisa

O manifesto v2 oferece:
- `php-file-override`: caminho e Git SHA da fonte + arquivo PHP de
  substituição somente dentro do contêiner.
- `source-signature`: trava a versão de uma fonte sem substituir arquivo.
- `php-bootstrap`: carrega inicialização PHP específica do fork.

Para Laravel/E-Storage, é possível começar com `source-signature`
e `php-bootstrap`; mas **ainda será necessário escrever o driver ou
registrador PHP correspondente e provar sua cobertura**. Um bootstrap
vazio não certifica integração.

Exemplo de hooks (hash ilustrativo, sem valor real):

```json
{
  "hooks": [
    {
      "type": "source-signature",
      "source": {
        "path": "config/filesystems.php",
        "git_blob": "0000000000000000000000000000000000000000"
      }
    },
    {
      "type": "php-bootstrap",
      "script": "register.php"
    }
  ]
}
```

O SHA original é obtido com
`git -C /caminho/do/fork hash-object config/filesystems.php`.
O código PHP do módulo deve delegar ao núcleo compartilhado,
sem repetir transporte, autenticação ou versão documental.

## 4. Verifique e teste

```bash
php bin/integrator.php verify prefeitura-x /caminho/do/fork
php bin/integrator.php test-unit
php bin/integrator.php plan prefeitura-x
```

Os testes estão em `tests/*.php`. Para contratos remotos, use
`tests/nextcloud-suite.php` no ambiente Docker do laboratório.
Teste gravação, leitura em outro processo, atualização, exclusão,
hash, autorização e indisponibilidade do Nextcloud. Inspecione o
PostgreSQL para detectar novas gravações de documentos, inclusive
vias `pg_lo_*` diretas.

**Testes negativos devem falhar com código de saída diferente de zero.**
O executor PHP da DBSeller define `TEST=1` para não deixar o
tratador legado de erros mascarar um teste falho.

## 5. Abra um pull request

O PR deve conter somente o adaptador, seus testes e documentação.
Inclua revisão Git imutável, suporte PHP, interfaces identificadas,
resultados dos testes e lacunas ainda conhecidas.

Nunca inclua credenciais, `.env`, documentos reais, dados municipais ou
dumps de produção. O manifesto deve manter `production_ready=false`
até uma certificação funcional, transacional e de segurança.

O projeto possui modelo de PR em
`.github/PULL_REQUEST_TEMPLATE/adapter.md` e CI
`.github/workflows/adapter-contracts.yml`.
