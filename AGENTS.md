# Contexto para agentes

Este repositorio es el backend Laravel y el control plane de una plataforma SaaS multi-tenant.
Antes de cambiar código, lee [`docs/README.md`](docs/README.md) y después el documento de tu área.

## Lectura por tarea

| Tarea | Lectura obligatoria |
|---|---|
| Core, routing, paquetes u overlays | [`docs/architecture.md`](docs/architecture.md) y [`docs/flows.md`](docs/flows.md) |
| provisioning, de-provisioning, estado o cuotas | [`docs/flows.md`](docs/flows.md) y [`docs/database.md`](docs/database.md) |
| migraciones o modelos Commander | [`docs/database.md`](docs/database.md) |
| Kilkana | [`docs/business.md`](docs/business.md) y [`docs/database/kilkana/README.md`](docs/database/kilkana/README.md) |
| comandos, Docker, tests o deploy | [`docs/operations.md`](docs/operations.md) |

## Invariantes

- Una app sirve todos los clientes. Cliente = subdominio; producto = segmento tras `/api/v1`.
- SnakePC es el ámbito local/portfolio y `commander.snakepc.test` su control plane. La dirección
  futura de Kilkana es `{cliente}.kilkana.test`, sin `commander.kilkana.test`; aún no está
  implementada porque hoy todo deriva de un único `BASE_DOMAIN`. Lee
  [`docs/architecture.md`](docs/architecture.md#ámbitos-de-dominio-snakepc-y-kilkana) antes de
  cambiar dominios.
- Cada par `(cliente, producto)` provisionado tiene una BD propia `{cliente}_{producto}`.
- Todo cliente pertenece obligatoriamente a una `commander_account`: el alta normal crea una
  cuenta 1:1 y un grupo reutiliza una cuenta 1:N. El cliente sigue siendo subdominio, tenant y
  frontera de BD; no agregues datos tenant ni cuotas dentro de la cuenta.
- `commander` es la única base de control; los modelos `commander_*` están fijados a esa conexión.
- Producto compartido: `src/packages/<Paquete>`. Overlay opcional:
  `src/packages/<Cliente>/<Paquete>` con solo diferencias.
- El overlay gana sus rutas exactas; cualquier ruta no personalizada cae en el compartido.
- Si no existe implementación compartida ni específica, el alta responde `422`. Commander nunca
  genera ni clona código de producto.
- Una implementación necesita `routes/api.php` para ser descubrible. Un overlay de config puede
  existir sin rutas solo cuando ya hay producto compartido.
- Providers compartidos sí; providers de overlay no se cargan globalmente.
- `provisioned_at` no nulo es la frontera para enrutar. Pending no sirve tráfico.
- Las acciones de instancia usan `commander_packages.id` numérico, no `name` ni slug global.
- La conexión tenant, la config runtime y `CoreActivePackageSupport` se restauran en `finally`.
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

- `commander_packages` mezcla catálogo y ciclo de vida; no inventes estados implícitos nuevos.
- Enqueue sin outbox puede dejar pending; de-provisioning puede dejar recursos si falla el worker.
- El único nullable de catálogo permite una carrera de duplicados.
- La suscripción vigente se garantiza en código, no con constraint parcial de BD.
- Git Insights puede duplicar actividad del código compartido por instancia.
- De-provisioning solo conoce el storage `media/{database}`.

Los detalles y las condiciones de mejora están documentados en
[`docs/architecture.md`](docs/architecture.md), [`docs/database.md`](docs/database.md) y
[`docs/flows.md`](docs/flows.md). No ocultes uno de estos riesgos con un parche local.
