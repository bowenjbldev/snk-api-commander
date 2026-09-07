# Snake API Commander

Backend Laravel 13 y control plane de una plataforma SaaS multi-tenant. Un único despliegue sirve
todos los clientes y cada instancia de producto conserva su propia base de datos.

```text
https://{cliente}.{TENANT_BASE_DOMAIN}/api/v1/{producto}/...
        └ tenant                              └ package
```

Por ejemplo:

```text
https://diario-norte.commander.test/api/v1/kilkana/public/posts
```

`commander.test` es el dominio local de la API tenant. Kilkana es un producto seleccionado por
la ruta; `kilkana.test` puede ser el dominio público de un frontend, pero no gobierna la
resolución de la API.

## Arquitectura en una mirada

```text
commander
├── cuentas, clientes, aplicaciones, contratos y observabilidad
└── operador Commander y sus tokens

diario_norte_kilkana
├── usuarios y tokens de Diario Norte
└── datos editoriales de Kilkana
```

El producto compartido vive en `src/packages/<Paquete>`. Una personalización opcional vive en
`src/packages/<Cliente>/<Paquete>` y contiene únicamente diferencias.

## Documentación

Empieza por [el índice de documentación](docs/README.md). Los documentos principales son:

| Documento | Contenido |
|---|---|
| [Arquitectura](docs/architecture.md) | routing, packages, overlays, aislamiento y procesos |
| [Modelo de Commander](docs/commander-model.md) | cuentas, clientes, aplicaciones, contratos e instancias |
| [Base de datos](docs/database.md) | conexiones, tablas Commander y topología tenant |
| [Flujos](docs/flows.md) | resolución, provisioning, cuotas, estados y baja |
| [Operación](docs/operations.md) | Docker, comandos, migraciones y deploy |
| [Rendimiento](docs/performance.md) | Octane, FrankenPHP, capacidad y benchmarks |
| [Kilkana](docs/products/kilkana/README.md) | producto editorial y modelo de datos |
| [Diseños](docs/design/) | SPA Commander y dominios propios |

Los agentes deben empezar por [AGENTS.md](AGENTS.md).

## Estructura

```text
docs/                      documentación canónica
src/packages/Core/         infraestructura multi-tenant
src/packages/Commander/    control plane
src/packages/Kilkana/      producto editorial compartido
src/packages/<Cliente>/    overlays o productos exclusivos
commander-web/             SPA React/Carbon, todavía conectado a un mock
scripts/                   operación, seguridad y benchmarks
docker-compose.yml         servicios locales
```

## Arranque local

```bash
cp .env.example .env
cp src/.env.example src/.env
bash scripts/setup-ssl.sh
docker compose up -d
docker compose exec -T app php artisan core:seed --force
```

URLs locales:

- Commander: `https://commander.snakepc.test/api/v1`;
- tenants: `https://{cliente}.commander.test/api/v1/{producto}`;
- health: `https://api.snakepc.test/api/v1/`;
- OpenAPI: `https://api.snakepc.test/api/v1/docs.json`.

## Comprobaciones

```bash
docker compose exec -T app php artisan test
cd src
vendor/bin/pint --test
composer audit
```

Consulta [Operación](docs/operations.md) antes de migrar tenants, provisionar manualmente o
preparar un despliegue.
