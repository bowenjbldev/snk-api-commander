# Contexto para agentes

Este repositorio es el backend Laravel y el control plane de una plataforma SaaS multi-tenant.
Antes de cambiar código, lee [`docs/README.md`](docs/README.md),
[`docs/commander-model.md`](docs/commander-model.md) y después el documento de tu área.

## Lectura por tarea

| Tarea | Lectura obligatoria |
|---|---|
| Core, routing, paquetes u overlays | [`docs/architecture.md`](docs/architecture.md) y [`docs/flows.md`](docs/flows.md) |
| provisioning, de-provisioning, estado o cuotas | [`docs/flows.md`](docs/flows.md) y [`docs/database.md`](docs/database.md) |
| migraciones o modelos Commander | [`docs/commander-model.md`](docs/commander-model.md) y [`docs/database.md`](docs/database.md) |
| Kilkana | [`docs/business.md`](docs/business.md) y [`docs/database/kilkana/README.md`](docs/database/kilkana/README.md) |
| dominios propios, DNS, TLS o SEO multi-host | [`docs/domains.md`](docs/domains.md), [`docs/architecture.md`](docs/architecture.md) y [`docs/operations.md`](docs/operations.md) |
| SPA Commander, pantallas o experiencia de operador | [`docs/design/commander-spa.md`](docs/design/commander-spa.md) |
| comandos, Docker, tests o deploy | [`docs/operations.md`](docs/operations.md) |

## Invariantes

- Una app sirve todos los clientes. Cliente = subdominio; producto = segmento tras `/api/v1`.
- El control plane local usa `commander.snakepc.test` (`BASE_DOMAIN=snakepc.test`). La API de
  todos los productos usa `{cliente}.commander.test/api/v1/{producto}`
  (`TENANT_BASE_DOMAIN=commander.test`). Kilkana es un package: se selecciona con `/kilkana`,
  no con el dominio. El dominio público de un periódico es independiente del dominio de la API.
- Cada par `(cliente, producto)` provisionado tiene una BD propia `{cliente}_{producto}`.
- Cuenta = pagador/contrato; cliente = tenant/subdominio; aplicación = producto; instancia = par
  cliente-aplicación. Todo cliente pertenece a una cuenta: el alta normal crea una 1:1 y un grupo
  reutiliza una 1:N. No agregues datos tenant ni cuotas dentro de la cuenta.
- El catálogo de aplicaciones es code-first y se actualiza con `commander:applications:sync`,
  nunca como efecto lateral de un GET. Un package es implementación, no contrato ni instancia.
- Un plan pertenece a una aplicación. Una suscripción pertenece a cuenta+aplicación y sus ítems
  asignan el plan operativo de cada instancia. El precio negociado no se recalcula al cambiar plan.
- `commander` es la única base de control; los modelos `commander_*` están fijados a esa conexión.
- Producto compartido: `src/packages/<Paquete>`. Overlay opcional:
  `src/packages/<Cliente>/<Paquete>` con solo diferencias.
- El overlay gana sus rutas exactas; cualquier ruta no personalizada cae en el compartido.
- Si no existe implementación compartida ni específica, el alta responde `422`. Commander nunca
  genera ni clona código de producto.
- Una implementación necesita `routes/api.php` para ser descubrible. Un overlay de config puede
  existir sin rutas solo cuando ya hay producto compartido.
- Providers compartidos sí; providers de overlay no se cargan globalmente.
- Solo `commander_instances.provisioning_status = ready` enruta. Pending/failed/deprovisioned no.
- Las acciones usan `commander_instances.id` numérico, no nombre ni slug global.
- Un fallo conserva instancia y `last_error`; retry reutiliza el mismo ID y exige credenciales
  admin explícitas.
- La conexión tenant, la config runtime y `CoreActiveInstanceSupport` se restauran en `finally`.
- El worker `provisioner` es el único con DDL. El usuario runtime solo tiene CRUD sobre una BD.
- Un token pertenece a la base que lo contiene; no añadas bypasses de aislamiento por token/id.
- La conmutación global soporta PHP-FPM y workers secuenciales, no concurrencia Octane/Swoole.

## Convenciones de código y BD

- La lógica vive en `src/packages`, no en `src/app`.
- Namespace compartido de Kilkana: `Packages\Kilkana`. Sus clases aún conservan el prefijo
  histórico `SnkKilkana*`; no hagas un rename lateral sin una tarea explícita.
- Clases de overlay: namespace `Packages\<Cliente>\<Paquete>` y prefijo N2 cliente+paquete.
- Config propia siempre namespaced. Solo Core cambia config global en boot; overrides globales de
  producto se aplican dentro del resolutor y deben restaurarse.
- Tabla de negocio nueva: `$table->id()`, prefijo lógico del producto, todas las FKs tras la PK,
  negocio después y timestamps al final. No copies la excepción `kilkana_settings.id`.
- Una migración ya aplicada no se reescribe para evolucionar producción: crea una forward migration.
- Commander se migra sin flags. Tenant requiere `--client` y `--package` juntos. Tras deploy usa
  `--all-tenants` con su preflight.
- No uses `Hash::make` al asignar `CoreUserModel.password`: el cast `hashed` ya lo hace.
- El modelo de usuario es `Packages\Core\Models\CoreUserModel`, no `App\Models\User`.
- Valida nombres de host, BD y usuario en toda frontera; no interpolar entrada sin validar en DDL.
- SPA (`commander-web/`): color, espaciado, tipografía y breakpoints salen **siempre** de Carbon
  —`var(--cds-*)`, `$spacing-*`, `type-style()`, `breakpoint-up()`—, nunca un hex, un px ni un
  `@media` a mano. Hoy la app va en `g100` fijo y un hex de tema oscuro parece correcto, pero se
  rompe en silencio el día que haya conmutador claro/oscuro; el token ya trae los dos valores.
  `@carbon/charts` es la excepción y tiene su propio cauce: pinta con D3 y necesita un color
  que pueda parsear, así que su `color.scale` se arma con `tokenScale()` (`shared/lib/theme.ts`),
  que resuelve el token contra el documento. Nunca un hex suelto en las opciones del chart.
  Deuda conocida: quedan `@media` a mano en `styles/index.scss` que deberían ser `breakpoint-up()`.

## Flujo de trabajo

1. Ejecuta `git branch --show-current` y `git status --short`; preserva cambios ajenos.
2. Lee completo el código afectado y todos sus callers antes de editar.
3. Haz el cambio mínimo en la capa que concentra el comportamiento.
4. Añade o ajusta una prueba que falle sin el cambio cuando haya lógica no trivial.
5. Ejecuta las comprobaciones proporcionales.
6. Actualiza el documento canónico si cambia arquitectura, contrato, esquema, flujo u operación.

No añadas Markdown dispersos. Salvo este fichero y el `README.md` de entrada, la documentación
vive en `docs/`.

## Comprobaciones

Backend:

```bash
docker compose exec -T app php artisan test
cd src
vendor/bin/pint --test
composer audit
```

Migraciones y rutas cuando apliquen:

```bash
docker compose exec -T app php artisan config:cache
docker compose exec -T app php artisan route:cache
docker compose exec -T provisioner php artisan core:migrate --all-tenants --force
```

Los tests SQLite no validan permisos DDL, MariaDB, collation ni `information_schema`; prueba esos
cambios contra MariaDB. Reporta test, build y lint por separado: uno verde no implica los demás.

## Riesgos conocidos

- **El contenedor `app` cachea rutas en `bootstrap/cache/routes-v7.php`**, que NO está
  bind-mounteado: una ruta nueva no aparece en `route:list` hasta correr `php artisan route:clear`
  dentro de `app`. El contenedor `provisioner` sí la ve, y **esa discrepancia entre los dos es la
  pista** — desde fuera parece que la ruta no se registró.

- Enqueue sin outbox puede dejar pending/failed; de-provisioning puede dejar recursos si falla el
  worker. No ocultes esos estados creando otra instancia.
- Las igualdades cross-relation (métrica/plan/aplicación y cuenta/contrato/cliente) se garantizan en
  la API, no todas mediante constraints simples.
- Los límites de plan son plantillas: editarlos no reescribe instancias existentes.
- De-provisioning solo conoce el storage `media/{database}`.

Los detalles y las condiciones de mejora están documentados en
[`docs/architecture.md`](docs/architecture.md), [`docs/database.md`](docs/database.md) y
[`docs/flows.md`](docs/flows.md). No ocultes uno de estos riesgos con un parche local.
