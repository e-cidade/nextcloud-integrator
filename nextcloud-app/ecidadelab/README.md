<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Nextcloud 35 - aplicativo ecidadelab (laboratorio)

**Nao instalar em producao.**

O namespace interno desta aplicacao e OCA\EcidadeLab\Controller. O SDK PHP remoto usa ECidade\Nextcloud. Sao programas separados, conectados por HTTPS.

A API OCS tem exatamente seis operacoes: criar, reservar, inspecionar, baixar, substituir conteudo e excluir. O prefixo de API e /ocs/v2.php/apps/ecidadelab/api/v1/documents. Armazena bytes com a Files API do Nextcloud, e metadados de negocio, tenant, hash, tamanho e revisao pela Files Metadata API.

Limites de laboratorio: somente usuario bench e tenant municipio-a; cabecalhos de tenant nao constituem ACL de producao; dados inteiros em memoria, limite 8 MiB; nenhuma transacao atomica com PostgreSQL; exclusao imediata e substituicao sem versionamento de negocio imutavel.

O endpoint de benchmark FilesApiController e as operacoes batch foram deliberadamente excluidos do pacote publicavel. Seguranca multitenant, revisoes imutaveis, streaming e reconciliacao devem ser implementados no MVP.
