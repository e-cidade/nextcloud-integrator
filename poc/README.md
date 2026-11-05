<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Reproducao e escopo da PoC

**Laboratorio isolado. Nao instalar em prefeituras ou producao.**

O repositorio contem o integrador PHP, os tres modulos de compatibilidade e o app Nextcloud 35 usado nos testes. A aplicacao e-Cidade de terceiros nao e copiada: obtenha DBSeller, Contass ou CPD de sua origem publica, com versao imutavel e SHA verificados pelo manifesto.

Arquivos principais: src/ (DocumentClient, LargeObjectBridge, AdapterRegistry), modules/, nextcloud-app/ecidadelab/, poc/fixtures/real-dbseller-flow.php, poc/fixtures/failure-tests.php e tests/nextcloud-suite.php.

Ambiente: Nextcloud 35, PHP 8.3, PostgreSQL, Redis, HTTPS e app ecidadelab montado em apps/ecidadelab; DBSeller de desenvolvimento PHP 5.6 com PostgreSQL 11 e seus arquivos originais montados read-only; SDK PHP montado em /opt/ecidade-integrator. A fachada apenas substitui std/DBLargeObject.php dentro do contêiner. O serviço Nextcloud pode estar noutro host, contanto que o e-Cidade consiga acessar sua URL HTTPS e confiar no certificado.

Variaveis de configuracao para este laboratorio: ECIDADE_ADAPTER, ECIDADE_INTEGRATOR_ROOT, ECIDADE_POC_CLIENT=1, ECIDADE_NC_BASE_URL, ECIDADE_NC_USER, ECIDADE_NC_TOKEN (segredo), ECIDADE_NC_TENANT=municipio-a, ECIDADE_NC_CA, ECIDADE_NC_FILENAME e ECIDADE_NC_BUSINESS. Nao registrar senhas, app passwords ou dados municipais reais no Git.

Testes: php bin/integrator.php test-unit (PHP 5.6/7.4/8.3); php tests/poc-server-contract.php (PHP 8.3); php tests/nextcloud-suite.php legacy dentro do contêiner DBSeller; php tests/nextcloud-suite.php facade dentro dos contêineres Contass/CPD sintéticos.

Durabilidade: foi validada gravacao com o bootstrap real DBSeller, reinicializacao de Nextcloud e Nginx preservando os volumes, leitura em outro processo PHP, atualizacao com revisao 2, leitura atualizada e exclusao. O PostgreSQL manteve os Large Objects preexistentes sem novos arquivos e sem vinculos de teste residuais.

Limitacoes: instancia bench e municipio-a hardcoded, API experimental com maximo 8 MiB e buffering em memoria, ausencia de autorizacao multitenant, mapeamento historico e reconciliacao transacional persistente. Contass e CPD foram testadas apenas nas fachadas, nao em fluxos de ERP.

Leia docs/POC-EXIT-CRITERIA.md e docs/MVP-ROADMAP.md antes de considerar publicacao, piloto ou homologacao.
