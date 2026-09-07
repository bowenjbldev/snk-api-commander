# Contexto para agentes

Snake API Commander es el backend Laravel y el control plane de una plataforma SaaS
multi-tenant. Antes de cambiar código, lee [el índice documental](docs/README.md), el
[modelo de Commander](docs/commander-model.md) y el documento del área afectada.

## Glosario

| Término | Significado |
|---|---|
| cuenta | pagador, contrato y facturación; no es un tenant |
| cliente | tenant operativo identificado por un subdominio |
| aplicación | producto vendible, por ejemplo Kilkana |
| package | implementación Laravel de una aplicación |
| instancia | par único cliente-aplicación y unidad operativa |
| overlay | diferencias de un cliente sobre un package compartido |
| Commander | control plane y única base de control |
| tenant | base aislada de una instancia, con usuarios y tokens propios |
| provisioner | único worker con permisos DDL sobre bases tenant |
| dominio de plataforma | host técnico de la API, gobernado por `TENANT_BASE_DOMAIN` |
| dominio público | URL del frontend de un producto; no selecciona la base de la API |

## Mapa de lectura

| Tarea | Lectura obligatoria |
|---|---|
| Core, routing, packages u overlays | [Arquitectura](docs/architecture.md) y [Flujos](docs/flows.md) |
| provisioning, estados o cuotas | [Flujos](docs/flows.md) y [Base de datos](docs/database.md) |
| modelos o migraciones Commander | [Modelo de Commander](docs/commander-model.md) y [Base de datos](docs/database.md) |
| Kilkana | [Producto Kilkana](docs/products/kilkana/README.md) y [su modelo de datos](docs/products/kilkana/database/README.md) |
| dominios propios, DNS, TLS o SEO multi-host | [Diseño de dominios](docs/design/custom-domains.md), [Arquitectura](docs/architecture.md) y [Operación](docs/operations.md) |
| Commander Web | [Diseño del SPA](docs/design/commander-spa.md) |
| Docker, comandos, tests o deploy | [Operación](docs/operations.md) |
| capacidad, Octane o benchmarks | [Rendimiento](docs/performance.md) |

## Invariantes

- Una aplicación sirve todos los clientes. Cliente = subdominio; producto = segmento tras
  `/api/v1`.
- El control plane local usa `commander.snakepc.test`. La API tenant usa
  `{cliente}.commander.test/api/v1/{producto}`.
- Kilkana es un package seleccionado por `/kilkana`, no un dominio de API.
- Cada par cliente-aplicación tiene una base propia `{cliente}_{producto}`.
- Todo cliente pertenece a una cuenta. Cuenta agrupa contrato y facturación, nunca datos tenant.
- El catálogo de aplicaciones es code-first y se sincroniza con
  `commander:applications:sync`; un GET no escribe.
- Un plan pertenece a una aplicación. El precio contractual no se recalcula al cambiar el plan
  operativo de una instancia.
- Los modelos `Commander*` usan siempre la conexión `commander`.
- El producto compartido vive en `src/packages/<Paquete>`; un overlay opcional en
  `src/packages/<Cliente>/<Paquete>` contiene solo diferencias.
- El overlay gana rutas exactas y el package compartido resuelve el resto.
- Una implementación necesita `routes/api.php` para ser descubrible.
- Solo una instancia con `provisioning_status = ready` enruta.
- Las acciones operativas usan `commander_instances.id`, no nombres ni slugs globales.
- Retry reutiliza la instancia fallida y exige credenciales admin nuevas.
- La conexión tenant, configuración runtime y contexto activo se restauran en `finally`.
- El provisioner es el único proceso con DDL; el usuario runtime tenant solo tiene CRUD.
- Un token pertenece a la base que lo contiene. No se añaden bypasses por token o ID.
- El tenant sale exclusivamente del `Host`; no se confía en `X-Forwarded-Host`.
- La conmutación global admite PHP-FPM y workers secuenciales de Octane, no corrutinas
  concurrentes dentro del proceso.

## Convenciones

- La lógica vive en `src/packages`, no en `src/app`.
- Namespace compartido Kilkana: `Packages\Kilkana`. Sus clases conservan el prefijo histórico
  `SnkKilkana*`.
- Clases de overlay: `Packages\<Cliente>\<Paquete>` y prefijo N2 cliente+package.
- La configuración propia va namespaced. Los overrides globales se aplican durante la resolución y
  se restauran.
- Una tabla nueva ordena PK, FKs, negocio y timestamps. No copies la excepción
  `kilkana_settings.id`.
- Una migración aplicada se evoluciona con otra forward migration.
- Commander se migra sin flags. Tenant exige `--client` y `--package` juntos.
- `CoreUserModel.password` usa `setPasswordAttribute()`; no apliques `Hash::make` antes de
  asignarlo.
- El usuario es `Packages\Core\Models\CoreUserModel`, no `App\Models\User`.
- Valida host, base y usuario en toda frontera antes de interpolarlos en DDL.
- Commander Web usa exclusivamente tokens, spacing, tipografía y breakpoints de Carbon. Los charts
  resuelven colores mediante `tokenScale()`.

## Flujo de trabajo

1. Ejecuta `git branch --show-current` y `git status --short`.
2. Preserva cambios ajenos y lee completos el código afectado y sus callers.
3. Corrige la regla en la capa que concentra el comportamiento.
4. Añade una prueba que falle sin el cambio cuando haya lógica no trivial.
5. Ejecuta comprobaciones proporcionales.
6. Actualiza el documento canónico si cambia arquitectura, contrato, esquema, flujo u operación.

No añadas Markdown dispersos. Salvo este archivo, el README de entrada y archivos específicos de
herramientas, la documentación del proyecto vive en `docs/`.

## Comprobaciones

```bash
docker compose exec -T app php artisan test
cd src
vendor/bin/pint --test
composer audit
```

Cuando apliquen:

```bash
docker compose exec -T app php artisan config:cache
docker compose exec -T app php artisan route:cache
docker compose exec -T provisioner php artisan core:migrate --all-tenants --force
```

SQLite no valida DDL, grants, collation ni `information_schema`; esas fronteras requieren una
prueba adicional sobre MariaDB. Reporta tests, formato, build y audit por separado.

## Riesgos que no se deben ocultar

- Route cache puede dejar rutas nuevas fuera del contenedor `app`.
- Octane conserva código y configuración hasta que sus workers se recargan.
- El enqueue no usa outbox; pending y failed deben seguir siendo visibles y reintentables.
- Deprovisioning puede dejar recursos si falla el worker.
- Algunas igualdades entre relaciones se garantizan en la API y no mediante FKs simples.
- Los límites de plan son plantillas y no reescriben instancias existentes.
- La baja solo conoce el storage `media/{database}`.
- Metering y rollups son el primer límite al sostener millones de peticiones diarias.

Los detalles pertenecen a [Arquitectura](docs/architecture.md), [Flujos](docs/flows.md),
[Base de datos](docs/database.md), [Operación](docs/operations.md) y
[Rendimiento](docs/performance.md).
