# Operación

> Estado: implementado. Este documento es el runbook vigente. Las pruebas, decisiones y cifras de
> capacidad viven en [Rendimiento](performance.md).

## Arranque local

```bash
cp .env.example .env
cp src/.env.example src/.env
bash scripts/setup-ssl.sh
docker compose up -d
docker compose exec -T app php artisan core:seed --force
```

El entrypoint instala Composer si hace falta, genera `APP_KEY`, prepara storage, migra Commander,
sincroniza el catálogo de aplicaciones y limpia la caché. No migra automáticamente bases tenant
ya existentes.

## Servicios Docker

| Servicio | Función |
|---|---|
| `app` | API HTTP, Octane sobre FrankenPHP en worker mode |
| `db` | MariaDB: base `commander` y una base por instancia |
| `db-init` | asegura el usuario de runtime de Commander |
| `nginx` | TLS, wildcard tenant y proxy |
| `scheduler` | ejecuta el schedule de Commander y Kilkana |
| `queue` | consume la cola general almacenada en Commander |
| `provisioner` | único proceso con credenciales DDL tenant |
| `mailpit` | SMTP local de pruebas |
| `commander-web` | SPA React/Carbon opt-in, actualmente contra un mock |
| `fpm` | perfil opt-in de comparación PHP-FPM, HTTPS `:8444` |
| `octane-swoole` | perfil opt-in de comparación Swoole, HTTPS `:8445` |

MariaDB local permite root sin contraseña para el provisioner. No copies esa configuración a
producción.

### Perfil HTTP

El perfil local parte de doce workers, reciclados cada 500 peticiones, con 6 CPU y 2 GiB. Se
ajusta con `OCTANE_WORKERS`, `APP_CONTAINER_CPUS`, `APP_CONTAINER_MEMORY` y
`APP_CONTAINER_PIDS`.

Los workers conservan la aplicación en memoria. En local, `app` usa el watcher nativo de
FrankenPHP sobre PHP, configuración, rutas, Composer y `.env`. En producción, después de
regenerar cachés:

```bash
php artisan octane:reload
```

La recarga de workers no limpia `route:cache` ni `config:cache`. Una variable de Compose
requiere recrear el contenedor.

Nginx rechaza el acceso directo a scripts PHP. Compruébalo con:

```bash
bash scripts/check-php-exposure.sh
```

## DNS, TLS y URLs

```bash
bash scripts/setup-ssl.sh
bash scripts/dns-wildcard.sh
```

| Recurso | URL local |
|---|---|
| Commander | `https://commander.snakepc.test/api/v1` |
| API tenant Kilkana | `https://{cliente}.commander.test/api/v1/kilkana` |
| Health | `https://api.snakepc.test/api/v1/` |
| OpenAPI | `https://api.snakepc.test/api/v1/docs.json` |
| Mailpit | `http://localhost:8025` |

`BASE_DOMAIN=snakepc.test` gobierna el control plane y
`TENANT_BASE_DOMAIN=commander.test` el dominio de API de todos los productos. Kilkana se elige
mediante `/api/v1/kilkana`; no es el dominio de la API.

Sin DNS local:

```bash
curl --resolve demo.commander.test:443:127.0.0.2 \
  https://demo.commander.test/api/v1/kilkana/public/posts
```

Al cambiar un dominio, regenera certificado, recrea Nginx, recachea rutas/configuración y recarga
Octane. Los enlaces firmados antiguos incluyen el host anterior.

## Operador y administradores tenant

```bash
docker compose exec -T app php artisan core:seed --force
```

El operador local por defecto es `admin@snake.com` / `secret1234`. Producción exige una
contraseña explícita. El administrador de cada tenant es distinto y se recibe durante
provisioning o retry.

`CoreUserModel` cifra la contraseña mediante `setPasswordAttribute()`. Asigna el texto en claro
a ese modelo una sola vez; no lo envuelvas en `Hash::make`.

El contenido demo está desactivado por defecto:

```text
KILKANA_SEED_DEMO=false
# El corrector por contexto del editor (TypeSafe AI). Sin clave, el endpoint falla en silencio
# y el panel se queda con Hunspell. Los workers la leen al arrancar: cambiarla pide recargarlos.
KILKANA_TYPESAFE_API_KEY=
```

Consulta [La demo de Kilkana](products/kilkana/demo.md) antes de resembrar contenido.

## Catálogo de aplicaciones

```bash
docker compose exec -T app php artisan commander:applications:sync
```

Ejecuta el comando tras añadir, retirar o renombrar un package u overlay descubrible. Es
idempotente, marca código ausente como `missing` y no borra historia. Ningún GET sincroniza el
catálogo.

## Migraciones

### Commander

```bash
docker compose exec -T app php artisan core:migrate --force
docker compose exec -T app php artisan commander:applications:sync
docker compose exec -T app php artisan core:seed --force
```

### Una instancia

```bash
docker compose exec -T provisioner php artisan core:migrate \
  --client=elmundo --package=Kilkana --force

docker compose exec -T provisioner php artisan core:seed \
  --client=elmundo --package=Kilkana --force
```

`--client` y `--package` son inseparables. El orden es Core, package compartido y overlay.

### Todas las instancias

```bash
docker compose exec -T provisioner php artisan core:migrate --all-tenants --force
docker compose exec -T provisioner php artisan kilkana:sync-themes
docker compose exec -T provisioner php artisan kilkana:sync-permissions
```

El preflight valida todas las instancias antes del primer DDL. `--all-tenants` no se combina con
client, package ni rollback, y solo siembra con `--fresh`. Las migraciones no sincronizan
catálogos replicados: temas y permisos son comandos separados.

### Todos los periódicos desde cero (local)

```bash
docker compose exec -T -e KILKANA_SEED_DEMO=1 provisioner \
  php artisan core:migrate --all-tenants --fresh --seed --force
```

Vacía la base de cada periódico listo, la rehace y la siembra: el registro de actividad, las
piezas y las cuentas vuelven a nacer. Sin `KILKANA_SEED_DEMO=1` se siembra el catálogo pero no
las cuentas conocidas ni el contenido editorial. En producción se rechaza.

### Fresh local

```bash
docker compose exec -T app php artisan core:migrate --fresh --force
docker compose exec -T app php artisan commander:applications:sync
docker compose exec -T app php artisan core:seed --force
```

`--fresh` destruye las tablas del destino. No se usa en producción.

Para sembrar el perfil de demo:

```bash
docker compose exec -T -e KILKANA_SEED_DEMO=1 provisioner \
  php artisan core:seed --client=demo --package=Kilkana --force
```

La variable y un perfil que reclame el cliente son dos puertas independientes. Sin ambas no se
escribe contenido editorial ni se crean cuentas conocidas. El perfil las abre por separado:
`demo` trae el archivo y las cuentas; `accounts` solo las cuentas —el administrador local
`admin@snake.com` / `secret1234` y el lector de demo—, que es como Diario Norte nace vacío y sin
abrir pero con quien entrar.

## Provisioning

El flujo normal usa la API Commander:

1. desplegar el package;
2. sincronizar aplicaciones;
3. crear plan y límites;
4. crear antes cuenta y contrato si es un grupo;
5. enviar provisioning con credenciales admin;
6. observar hasta `provisioning_status = ready`.

Inspección:

```bash
docker compose ps
docker compose logs --tail=200 provisioner
docker compose exec -T app php artisan queue:failed
docker compose exec -T app php artisan commander:status
docker compose exec -T app php artisan commander:status <instance_id>
```

Una instancia `failed` conserva `last_error`. Retry reutiliza el mismo ID y exige nuevas
credenciales; no se crea otra fila.

## Scheduler y colas

```bash
docker compose exec -T app php artisan schedule:list
docker compose logs --tail=200 scheduler
docker compose logs --tail=200 queue
```

| Frecuencia | Comando |
|---|---|
| cada minuto | `kilkana:publish-scheduled` |
| cada minuto | `kilkana:newsletter-send-due` |
| cada 10 minutos | `commander:refresh` |
| cada hora | `commander:usage:rollup --prune` |
| cada hora | `commander:git:sync` |
| cada 6 horas | `commander:quota:measure` |
| diario | `sanctum:prune-expired --hours=24` sobre Commander |
| 04:10 | `kilkana:purge-trash` |
| 04:40 | `kilkana:purge-variants` |

El contenedor `queue` consume trabajos de negocio en `commander.jobs`; cada trabajo Kilkana
conmuta después a su tenant. El provisioner consume únicamente la cola `provisioning`.

Los recorridos multi-tenant aíslan el fallo de una instancia y restauran conexión y configuración
en `finally`.

## Rendimiento

Los comandos de carga, condiciones de comparación, resultado de 100.657 peticiones y regresiones
de aislamiento están en [Rendimiento](performance.md). No se mezclan cifras históricas con este
runbook.

## Comprobaciones

Backend:

```bash
docker compose exec -T app php artisan test
cd src
vendor/bin/pint --test
composer audit
```

Rutas y configuración:

```bash
docker compose exec -T app php artisan config:cache
docker compose exec -T app php artisan route:cache
docker compose exec -T provisioner php artisan core:migrate --all-tenants --force
```

SQLite no valida DDL, grants, collation ni `information_schema`; esas fronteras se prueban
también contra MariaDB. Reporta tests, formato y audit por separado.

## Commander Web

`commander-web/` es un SPA React 19 + TypeScript + Carbon. Está implementado, pero su adaptador
de datos usa todavía un mock determinista en memoria y no llama al backend real.

```bash
docker compose --profile spa up -d commander-web nginx
```

Queda en `https://commander.snakepc.test/`. Sin el perfil, la raíz responde 404 y la API
continúa disponible.

```bash
cd commander-web
npm run check
npm test
npm run build
```

Consulta [Diseño de Commander Web](design/commander-spa.md).

## MariaDB

```bash
docker compose exec -T db mariadb -uroot commander -e 'SHOW TABLES;'
docker compose exec -T db mariadb -uroot elmundo_kilkana -e 'SHOW TABLES;'
```

No pongas secretos reales en comandos: quedan en history y en la lista de procesos.

## Deploy

Secuencia mínima:

```bash
git pull --ff-only
cd src
composer install --no-dev --optimize-autoloader
php artisan core:migrate --force
php artisan commander:applications:sync
php artisan config:cache
php artisan route:cache
```

Después, desde el provisioner:

```bash
php artisan core:migrate --all-tenants --force
php artisan kilkana:sync-themes
php artisan kilkana:sync-permissions
```

Finaliza recargando Octane:

```bash
php artisan octane:reload
```

El runtime HTTP no debe recibir credenciales DDL tenant. El deploy requiere backups de Commander,
bases tenant y storage, scheduler permanente, worker general, worker de provisioning y
`retry_after` mayor que el timeout del job.

## Gotchas

- El contenedor `app` puede conservar `bootstrap/cache/routes-v7.php`; una ruta nueva no aparece
  hasta limpiar o regenerar la route cache dentro del contenedor.
- Octane conserva código y configuración en memoria; una suite verde no demuestra que los workers
  HTTP ya hayan recargado.
- Editar `docker/php/commander.ini` requiere recrear los servicios que lo montan, no solo
  reiniciarlos.
- Un token tenant no autentica en Commander ni viceversa.
- Un package nuevo necesita autoload, cachés y sincronización de aplicaciones.
- Cambiar un plan no propaga sus límites hasta provisionar o cambiar de plan una instancia.
- Una suscripción negociada comparte contrato y quizá cuota, nunca bases, usuarios ni tokens.
