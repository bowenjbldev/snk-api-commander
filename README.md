# Snake API Commander

Backend Laravel 13 y panel de operación para una plataforma SaaS multi-tenant. Un único
despliegue sirve todos los clientes, pero cada instancia de producto tiene su propia base de
datos.

El direccionamiento es estable:

```text
https://{cliente}.{BASE_DOMAIN}/api/v1/{paquete}/...
        └ cliente                         └ producto
```

Por ejemplo, `https://elmundo.snakepc.test/api/v1/kilkana/public/posts` usa la instancia de
Kilkana de El Mundo y la base `elmundo_kilkana`.

El código común de un producto vive en `src/packages/<Paquete>`; una personalización opcional
vive en `src/packages/<Cliente>/<Paquete>`. Si el cliente no tiene personalización se usa el
producto compartido. Si no existe ninguna implementación, Commander rechaza el alta.

## Documentación

La documentación canónica está en [`docs/`](docs/README.md):

| Documento | Contenido |
|---|---|
| [Arquitectura](docs/architecture.md) | Componentes, descubrimiento de paquetes, resolución de tenants, overlays y límites de seguridad. |
| [Negocio](docs/business.md) | Kilkana como SaaS, planes objetivo y diferencias entre la visión comercial y lo implementado. |
| [Base de datos](docs/database.md) | Topología, tablas Core/Commander y mapa del esquema Kilkana. |
| [Flujos](docs/flows.md) | Request tenant, provisioning, fallback, de-provisioning, cuotas y despliegues. |
| [Operación](docs/operations.md) | Puesta en marcha, Docker, migraciones, seeders, scheduler y validación. |
| [Commander UI](docs/commander-ui.md) | SPA React, contrato visual, páginas, estado y correspondencia con la API. |

Los agentes deben empezar por [`AGENTS.md`](AGENTS.md).

## Estructura

```text
commander/                 SPA React + TypeScript + Carbon
docs/                      documentación canónica
src/
├── packages/Core/         infraestructura compartida
├── packages/Commander/    control plane y API del operador
├── packages/Kilkana/      producto Kilkana compartido
└── packages/<Cliente>/    overlays o productos exclusivos
scripts/                   utilidades de entorno y simulación
docker-compose.yml         app, scheduler, provisioner, nginx, MariaDB y Commander
```

## Arranque local

```bash
cp .env.example .env
cp src/.env.example src/.env
bash scripts/setup-ssl.sh
docker compose up -d
docker compose exec -T app php artisan core:seed --force
```

El panel queda en `https://commander.<BASE_DOMAIN>` y el health check en
`https://api.<BASE_DOMAIN>/api/v1/`. En el entorno local por defecto, el operador sembrado es
`admin@snake.com` / `secret1234`; no uses ese secreto en producción.

## Comprobaciones principales

```bash
docker compose exec -T app php artisan test
cd commander && npm test && npm run build
```

Consulta [Operación](docs/operations.md) antes de migrar tenants, provisionar manualmente o
preparar un despliegue de producción.
