# Operación

## Requisitos y configuración

Para el entorno completo solo se necesita Docker. Hay dos ficheros de entorno:

| Fichero | Dueño | Contenido principal |
|---|---|---|
| `.env` | Docker Compose | puertos, `BASE_DOMAIN`, usuario Commander y credencial del provisioner |
| `src/.env` | Laravel | app key, conexiones, queue, mail, seed demo y rate limits |

Créelos desde sus ejemplos y mantén el mismo `BASE_DOMAIN`:

```bash
cp .env.example .env
cp src/.env.example src/.env
bash scripts/setup-ssl.sh
docker compose up -d
```

El entrypoint instala Composer si falta, genera `APP_KEY`, crea el enlace de storage y ejecuta
`core:migrate --force` sobre `commander`. No migra bases tenant al arrancar.

## Servicios Docker

| Servicio | Función |
|---|---|
| `app` | PHP-FPM y comandos no privilegiados |
| `scheduler` | `php artisan schedule:work` |
| `provisioner` | asegura usuarios runtime y consume solo la cola `provisioning` con DDL |
| `nginx` | vhost wildcard y proxy a Laravel/Commander |
| `commander-web` | Vite dev server del panel |
| `db` | MariaDB: `commander` más una BD por instancia |
| `db-init` | crea/actualiza el usuario no privilegiado de Commander |
| `mailpit` | SMTP de pruebas y UI en el puerto 8025 |

El MariaDB local permite root sin contraseña para que el provisioner cree bases. Esa configuración
es solo de desarrollo y no debe copiarse a producción.

## DNS y TLS locales

El sistema necesita subdominios reales porque el host forma parte del routing y de las firmas.

```bash
bash scripts/setup-ssl.sh
bash scripts/dns-wildcard.sh
```

El DNS recomendado resuelve `*.<BASE_DOMAIN>` a `127.0.0.1`. Si no puedes instalar un resolver
wildcard, añade al hosts de Windows/Linux al menos `api`, `commander` y cada slug de cliente.

Para probar sin DNS:

```bash
curl -k --resolve api.snakepc.test:8443:127.0.0.1 \
  https://api.snakepc.test:8443/api/v1/
```

Rutas locales habituales:

| Recurso | URL |
|---|---|
| Commander | `https://commander.snakepc.test` o `:8443` |
| health Core | `https://api.snakepc.test/api/v1/` |
| OpenAPI UI | `https://api.snakepc.test/api/v1/docs` |
| spec OpenAPI | `https://api.snakepc.test/api/v1/docs.json` |
| Mailpit | `http://localhost:8025` |

Para que Laravel envíe a Mailpit en vez de escribir al log, configura en `src/.env`:

```env
MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
```

## Admins y seeders

El operador de Commander y el admin de cada tenant son identidades distintas.

```bash
# Commander: roles, permisos y operador
docker compose exec -T app php artisan core:seed --force

# Tenant manual: roles y seeders Core + producto + overlay
docker compose exec -T provisioner php artisan core:seed \
  --client=elmundo --package=Kilkana --force
```

En desarrollo, el operador cae a `admin@snake.com` / `secret1234` si no se configura otra cosa.
En producción, `CORE_ADMIN_PASSWORD` es obligatorio. El provisioning crea el admin tenant con el
email y la contraseña recibidos; un seed tenant nunca copia el operador.

`KILKANA_SEED_DEMO=false` es el valor seguro. Solo al ponerlo a `true` se crean contenido y lector
demo; `KILKANA_DEMO_READER_PASSWORD` debe estar definido para ese caso.

El seeder de Kilkana restaura también los snapshots de layouts y settings. Tras un `--fresh`,
ejecuta seed o la portada puede quedar sin composición.

## Migraciones

### Control plane

```bash
docker compose exec -T app php artisan core:migrate --force
docker compose exec -T app php artisan core:seed --force
```

Sin `--client` y `--package`, el destino es `commander` y se ejecutan Core + Commander.

### Una instancia

```bash
docker compose exec -T provisioner php artisan core:migrate \
  --client=elmundo --package=Kilkana --force

docker compose exec -T provisioner php artisan core:seed \
  --client=elmundo --package=Kilkana --force
```

Los dos flags son inseparables. El destino se deriva como `elmundo_kilkana` y el orden es Core,
producto compartido y overlay.

### Todas las instancias tras un deploy

```bash
docker compose exec -T provisioner php artisan core:migrate \
  --all-tenants --force
```

El comando hace preflight de todas las filas vivas antes del primer DDL. No admite combinar
`--all-tenants` con `--client`, `--package`, `--seed`, `--fresh` ni `--rollback`.

### Operaciones destructivas

`--fresh` elimina todas las tablas de la base elegida. Úsalo solo sobre un destino local resuelto y
nunca con variables o globs ambiguos:

```bash
docker compose exec -T provisioner php artisan core:migrate \
  --client=demo --package=Kilkana --fresh --force
```

Confirma primero la base derivada y ejecuta después `core:seed`. Para producción, una evolución de
esquema debe ser una migración forward, no un `fresh`.

## Provisioning normal

El camino normal no es ejecutar migraciones a mano:

1. despliega el código del producto;
2. abre Commander y sincroniza Packages;
3. crea un plan;
4. usa **Provision instance** con cliente y credenciales del admin;
5. observa la cola `provisioning` hasta que `provisioned_at` tenga valor.

Comandos de inspección:

```bash
docker compose ps
docker compose logs --tail=200 provisioner
docker compose exec -T app php artisan queue:failed
docker compose exec -T app php artisan commander:status
```

No reintentes ni borres una fila pending sin comprobar si el job sigue reservado, falló o llegó a
crear recursos en MariaDB.

## Scheduler y pipelines manuales

```bash
docker compose logs --tail=200 scheduler
docker compose exec -T app php artisan schedule:list
docker compose exec -T app php artisan commander:refresh
docker compose exec -T app php artisan commander:usage:rollup --prune
docker compose exec -T app php artisan commander:quota:measure
docker compose exec -T app php artisan commander:git:sync
docker compose exec -T app php artisan kilkana:publish-scheduled
```

Los comandos Kilkana recorren todas sus instancias válidas. No los ejecutes dentro de una conexión
tenant ya mutada ni añadas una copia nueva del bucle: reutiliza `SnkKilkanaTenantRunnerService`.

## Desarrollo backend

Desde `src`:

```bash
composer install
composer test
vendor/bin/pint --test
composer audit
php artisan route:list
php artisan config:cache
php artisan route:cache
```

O desde Docker:

```bash
docker compose exec -T app php artisan test
docker compose exec -T app php artisan route:list
```

Los tests usan SQLite mediante `COMMANDER_DB_DRIVER=sqlite` y `TENANT_DB_DRIVER=sqlite`; las rutas
de provisioning saltan DDL real en ese driver. Para cambios de privilegios, nombres de base,
collation o `information_schema`, añade además una prueba con MariaDB.

Si falta `src/.env`, Laravel puede mostrar warnings de entorno aunque los tests pasen. Copia el
ejemplo antes de interpretar esos warnings como fallos funcionales.

## Desarrollo Commander

```bash
cd commander
npm install
npm run dev
npm test
npm run build
npm run lint
npm audit
```

Vite usa `/api/v1` same-origin y lo proxifica al backend. Para un build desplegado fuera del mismo
origen, define `VITE_API_BASE=https://commander.<dominio>/api/v1`.

No confundas validaciones: `npm test` comprueba comportamiento, `npm run build` TypeScript/bundle y
`npm run lint` la deuda de estilo. Reporta cada resultado por separado.

## Simulación local

`scripts/simulate.sh` genera clientes, planes, instancias y datos de observabilidad para explorar
Commander:

```bash
bash scripts/simulate.sh
bash scripts/simulate.sh traffic <cliente> <slug> 100
bash scripts/simulate.sh pipeline
bash scripts/simulate.sh volume 100000
bash scripts/simulate.sh year 365
bash scripts/simulate.sh report
```

`volume` y `year` escriben grandes volúmenes directamente en MariaDB. Son herramientas de demo,
no comandos de producción.

## Acceso directo a MariaDB

En local, el cliente instalado en la imagen es `mariadb`:

```bash
docker compose exec -T db mariadb -uroot commander -e 'SHOW TABLES;'
docker compose exec -T db mariadb -uroot elmundo_kilkana -e 'SHOW TABLES;'
```

No pongas secretos reales en el comando: quedan visibles en history y en la lista de procesos.

## Despliegue

Secuencia mínima:

```bash
git pull --ff-only
cd src
composer install --no-dev --optimize-autoloader
php artisan core:migrate --force
php artisan config:cache
php artisan route:cache
```

Después, desde el proceso o contenedor del provisioner —el único con credenciales DDL—:

```bash
php artisan core:migrate --all-tenants --force
```

Además:

- wildcard DNS `*.<dominio>` hacia el servidor;
- certificado wildcard mediante DNS-01;
- vhost nginx wildcard hacia el único FPM;
- proceso permanente para `schedule:work` o cron `schedule:run`;
- worker exclusivo de la cola `provisioning` con credenciales DDL;
- `retry_after` mayor que el timeout de provisioning;
- backup de `commander`, de todas las bases tenant y del storage antes de migraciones relevantes.

## Gotchas

- `Hash::make` sobre `CoreUserModel.password` produce doble hash: el modelo ya castea `hashed`.
- El usuario es `Packages\Core\Models\CoreUserModel`, no `App\Models\User`.
- `BASE_DOMAIN` queda dentro de la caché de rutas; recachea tras cambiarlo.
- Un producto u overlay nuevo necesita autoload actualizado y reinicio/recache del proceso.
- Añadir código en Commander no crea una instancia: la fila solo enruta tras provisioning.
- Un token tenant no autentica al operador, y uno de Commander no autentica en un tenant.
- Los procesos concurrentes tipo Octane no están soportados por la conmutación global de conexión.
