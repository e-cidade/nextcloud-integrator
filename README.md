<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Integração e-Cidade com Nextcloud

Integração em PHP para permitir que distribuições do **e-Cidade** armazenem e recuperem documentos no **Nextcloud**, sem manter uma segunda cópia dos arquivos no PostgreSQL.

O projeto concentra a lógica de armazenamento em um núcleo compartilhado e utiliza **adaptadores por distribuição** para lidar com diferenças entre os forks. Assim, cada mantenedor pode acrescentar compatibilidade sem criar outra integração completa.

**Estado do projeto:** prova de conceito concluída; MVP experimental em desenvolvimento. **Não está pronto para uso em produção** e não instala ou modifica automaticamente nenhum ambiente do e-Cidade.

## Como funciona

```text
e-Cidade
  └── Adaptador da distribuição
        └── Núcleo PHP compartilhado
              └── API do aplicativo Nextcloud
                    └── Arquivos no Nextcloud
```

O objetivo é manter os **bytes dos documentos no Nextcloud** e preservar no e-Cidade apenas as referências e os metadados transacionais necessários ao funcionamento da aplicação.

A compatibilidade depende dos caminhos de leitura e escrita usados por cada distribuição. Um adaptador validado para uma interface não significa que todos os documentos daquele fork já estejam cobertos.

## Organização

| Diretório | Responsabilidade |
|---|---|
| `src/` | Núcleo PHP, contratos, comunicação e regras documentais |
| `modules/` | Adaptadores específicos das distribuições |
| `nextcloud-app/` | Aplicativo que recebe operações documentais no Nextcloud |
| `bin/` | CLI para inspecionar e preparar adaptadores |
| `tests/` | Testes automatizados e cenários sintéticos |
| `docs/` | Arquitetura, desenvolvimento, segurança e homologação |

## Primeiros passos

Com PHP disponível, é possível explorar os módulos pelo CLI:

```bash
php bin/integrator.php list
php bin/integrator.php inspect dbseller
```

Para iniciar um adaptador para outra distribuição:

```bash
php bin/integrator.php scaffold meu-fork dbseller
php bin/integrator.php verify meu-fork /caminho/do/checkout-publico
```

O comando `scaffold` cria uma estrutura inicial, mas **não aprova nem ativa** o adaptador. O `verify` confere se o código de origem corresponde à revisão declarada pelo módulo.

Também é possível executar o CLI em um contêiner isolado:

```bash
docker compose run --rm cli list
```

Consulte [CONTRIBUTING.md](CONTRIBUTING.md) para implementar um adaptador e enviá-lo por pull request.

## Documentação

- [Arquitetura](docs/ARCHITECTURE.md) — organização do núcleo e dos adaptadores.
- [Desenvolvimento](docs/DEVELOPMENT.md) — preparação do ambiente e execução de testes.
- [Estado do MVP](docs/MVP-STATE.md) — capacidades demonstradas, limitações e pendências.
- [Validação por mantenedores](docs/MVP-VALIDATION-HANDOFF.md) — critérios para avaliação com dados sintéticos.
- [Segurança](SECURITY.md) — cuidados e comunicação de vulnerabilidades.

Requisitos de compatibilidade ficam nos manifestos de cada módulo e nos arquivos de dependências. Evidências de testes e mudanças específicas são registradas na documentação técnica, nos pull requests e na integração contínua.

## Contribuição e licença

Contribuições para novos adaptadores, testes e documentação são bem-vindas. Cada módulo deve declarar a origem do código suportado e demonstrar sua compatibilidade, reutilizando o núcleo compartilhado.

Este projeto é distribuído sob a licença **AGPL-3.0-or-later**.
