# Operación

## Arranque local

```bash
cp .env.example .env
cp src/.env.example src/.env
bash scripts/setup-ssl.sh
docker compose up -d
docker compose exec -T app php artisan core:seed --force
```

El entrypoint:

1. instala Composer si falta y genera `APP_KEY`;
2. prepara storage y cachés;
3. ejecuta `core:migrate --force` sobre `commander`;
4. ejecuta `commander:applications:sync`;
5. limpia la caché de aplicación.

No migra automáticamente las bases tenant existentes.

## Servicios Docker

| Servicio | Función |
|---|---|
| `app` | PHP-FPM y comandos sin DDL tenant |
| `scheduler` | `php artisan schedule:work` |
| `provisioner` | asegura usuarios runtime y consume la cola `provisioning` con DDL |
| `nginx` | TLS, wildcard tenant y proxy |
| `db` | MariaDB: `commander` más una base por instancia |
| `db-init` | usuario no privilegiado de Commander |
| `mailpit` | SMTP local de pruebas |
| `commander-web` | perfil opt-in vacío para un futuro cliente web |

MariaDB local permite root sin contraseña para el provisioner. No copies esa configuración a
producción.

## DNS, TLS y URLs

```bash
bash scripts/setup-ssl.sh
bash scripts/dns-wildcard.sh
```

Resuelve `*.<BASE_DOMAIN>` y `*.<TENANT_BASE_DOMAIN>` a `127.0.0.1`. El fichero hosts no admite
wildcards; en ese caso añade `api`, `commander` y cada cliente.

| Recurso | URL local |
|---|---|
| API Commander | `https://commander.snakepc.test/api/v1` |
| tenant Kilkana | `https://{cliente}.kilkana.test/api/v1/kilkana` |
| health | `https://api.snakepc.test/api/v1/` |
| OpenAPI | `https://api.snakepc.test/api/v1/docs.json` |
| Mailpit | `http://localhost:8025` |

Prueba sin DNS:

```bash
curl -k --resolve api.snakepc.test:8443:127.0.0.1 \
  https://api.snakepc.test:8443/api/v1/
```

## Operador y admins tenant

```bash
docker compose exec -T app php artisan core:seed --force
```

En local, el operador por defecto es `admin@snake.com` / `secret1234`. Producción exige una
contraseña configurada. El admin de cada tenant es distinto: provisioning y retry exigen su email
y contraseña explícitos. Un seed tenant nunca copia el operador Commander.

`KILKANA_SEED_DEMO=false` es el valor seguro. Si se activa, configura también
`KILKANA_DEMO_READER_PASSWORD`.

## Catálogo de aplicaciones

```bash
docker compose exec -T app php artisan commander:applications:sync
```

Ejecuta el comando tras añadir, quitar o renombrar un package/overlay descubrible. Es idempotente;
marca código ausente como `missing` y no borra historia. Nunca dependas de un `GET` para hacer el
sync.

## Migraciones

### Commander

```bash
docker compose exec -T app php artisan core:migrate --force
docker compose exec -T app php artisan commander:applications:sync
docker compose exec -T app php artisan core:seed --force
```

Sin `--client` y `--package`, `core:migrate` ejecuta Core + Commander sobre la conexión de control.

### Una instancia

```bash
docker compose exec -T provisioner php artisan core:migrate \
  --client=elmundo --package=Kilkana --force

docker compose exec -T provisioner php artisan core:seed \
  --client=elmundo --package=Kilkana --force
```

Los flags son inseparables. El orden es Core, package compartido y overlay.

### Todas tras un deploy

```bash
docker compose exec -T provisioner php artisan core:migrate --all-tenants --force
```

El comando carga instancias ready y hace preflight de cliente, aplicación, código, base y
credenciales antes del primer DDL. No combina `--all-tenants` con client, package, seed, fresh o
rollback.

### Fresh local

El esquema Commander de esta rama parte limpio y no mantiene compatibilidad con datos anteriores.
Para reconstruir un entorno local:

```bash
docker compose exec -T app php artisan core:migrate --fresh --force
docker compose exec -T app php artisan commander:applications:sync
docker compose exec -T app php artisan core:seed --force
```

`--fresh` destruye todas las tablas del destino. No lo uses en producción. Una evolución después
de desplegar este esquema debe usar una nueva migración forward.

## Provisioning

El flujo normal usa la API Commander, no comandos manuales:

1. despliega el package;
2. sincroniza aplicaciones;
3. crea plan y límites;
4. para grupo, crea antes cuenta y contrato negociado;
5. envía provisioning con credenciales admin;
6. observa hasta `provisioning_status = ready`.

Inspección:

```bash
docker compose ps
docker compose logs --tail=200 provisioner
docker compose exec -T app php artisan queue:failed
docker compose exec -T app php artisan commander:status
docker compose exec -T app php artisan commander:status <instance_id>
```

Sin ID, `commander:status` lista instancias. Con ID muestra estado; `--set`, `--reason`, `--message`
y `--grace-until` permiten operación manual.

Una instancia failed conserva `last_error`. Reinténtala por
`POST /api/v1/instances/{id}/retry` con credenciales admin nuevas; no crees una segunda fila.

## Scheduler

```bash
docker compose logs --tail=200 scheduler
docker compose exec -T app php artisan schedule:list
docker compose exec -T app php artisan commander:refresh
docker compose exec -T app php artisan commander:usage:rollup --prune
docker compose exec -T app php artisan commander:quota:measure
docker compose exec -T app php artisan commander:quota:measure --dry-run
docker compose exec -T app php artisan commander:git:sync
docker compose exec -T app php artisan kilkana:publish-scheduled
```

| Frecuencia | Comando |
|---|---|
| cada minuto | `kilkana:publish-scheduled` |
| cada 10 min | `commander:refresh` |
| cada hora | `commander:usage:rollup --prune` |
| cada hora | `commander:git:sync` |
| cada 6 h | `commander:quota:measure` |

Los recorridos Kilkana reutilizan `SnkKilkanaTenantRunnerService`; un tenant roto no debe impedir
procesar los demás.

## Comprobaciones

```bash
docker compose exec -T app php artisan test
cd src
vendor/bin/pint --test
composer audit
php artisan config:cache
php artisan route:cache
```

Reporta test, formato y audit por separado. SQLite no cubre DDL/privilegios/collation; prueba esos
cambios también con MariaDB.

## Cliente web

`commander-web/` es el SPA del operador: React 19 + TypeScript + Carbon Design System v11, según el
[diseño funcional](design/commander-spa.md). **Corre entero sobre un mock determinista**
(`src/services/api/mock/`) y no llama a la API; el store vive en memoria y se pierde al recargar.

El perfil `spa` no arranca por defecto, así que hay que pedirlo:

```bash
docker compose --profile spa up -d commander-web nginx
```

Queda en `https://commander.snakepc.test/`. `/api/*` sigue llegando a Laravel y `/` al servidor de
Vite. Sin el perfil, `/` responde 404 y la API continúa disponible.

Dentro de `commander-web/` (o con `docker compose exec commander-web`):

```bash
npm run check   # tsc -b --noEmit
npm test        # node --test, lógica de negocio de src/**/model
npm run build   # typecheck + vite build
```

La lógica de negocio vive en `features/<dominio>/model/` justo para poder probarla sin navegador;
los componentes de `pages/` solo pintan.

## MariaDB

```bash
docker compose exec -T db mariadb -uroot commander -e 'SHOW TABLES;'
docker compose exec -T db mariadb -uroot elmundo_kilkana -e 'SHOW TABLES;'
```

No incluyas secretos reales en comandos: quedan en history y en la lista de procesos.

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
```

Requisitos adicionales:

- DNS y certificado wildcard;
- PHP-FPM sin concurrencia interna tipo Octane;
- scheduler permanente o cron `schedule:run`;
- worker exclusivo de provisioning con DDL;
- `retry_after` mayor que el timeout del job;
- backup de Commander, bases tenant y storage antes de cambios relevantes.

## Gotchas

- `CoreUserModel.password` ya tiene cast `hashed`; no uses `Hash::make` al asignarlo.
- El usuario es `Packages\Core\Models\CoreUserModel`, no `App\Models\User`.
- Dominios quedan en route cache; regenera tras cambiarlos.
- Un package nuevo requiere autoload/recache y sync de aplicaciones.
- Un token tenant no autentica en Commander ni viceversa.
- Cambiar un plan de catálogo no propaga límites a instancias hasta provisionar/cambiar plan.
- Una suscripción negociada agrupa billing y puede tener cuotas compartidas; no agrupa bases,
  usuarios ni las cuotas operativas propias de cada instancia.
