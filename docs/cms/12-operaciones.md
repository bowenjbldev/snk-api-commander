# 12 · Operaciones (runbook del CMS)

## Por qué este documento

El CMS es el panel del operador, y como tal no basta con que exista el código: alguien tiene que
levantarlo, mantenerlo vivo y diagnosticarlo cuando algo deja de responder. Este documento es el
runbook operativo: la secuencia exacta para arrancar el stack desde cero, activar el paquete `Cms`,
preparar su base de datos, correr la SPA, registrar y dar de baja despliegues reales, y los comandos
de mantenimiento que el panel necesita. La última sección recoge los *gotchas* que de verdad nos
mordieron durante el desarrollo, con su causa y su arreglo concreto.

A diferencia de un despliegue de cliente —que corre el paquete `Core` y es autónomo—, el servicio
`app` del compose principal corre con `API_PACKAGE=Cms`: es el plano de control. Si este cae, los
despliegues de los clientes siguen sirviendo su API; lo único que se pierde es la capacidad del
operador de verlos y gobernarlos. Tenerlo presente cambia la prioridad de cada incidencia.

Documentos hermanos útiles: el contrato de control remoto y la máquina de estados están en los
documentos de control plane; el aprovisionamiento de despliegues vivos lo cubre `scripts/subscription.sh`,
que aquí solo operamos.

---

## 1. Levantar el stack (docker compose)

El stack base lo define `docker-compose.yml` en la raíz del repo. Sus tres servicios son:

- `app` — la aplicación Laravel (imagen `snake-laravel-api-app`), con alias de red `api-app`.
- `nginx` — termina TLS y enruta por `Host` (puertos `${HTTP_PORT:-80}` y `${HTTPS_PORT:-443}`).
- `db` — MariaDB (`MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=yes`, **solo local**).

En este entorno local el `.env` de la raíz mapea los puertos a valores no privilegiados, de modo que
nginx publica HTTPS en `:8443`:

```
HTTP_PORT=8080
HTTPS_PORT=8443
BASE_DOMAIN=snake.test
APP_DOMAIN=api.snake.test
COMPOSE_FILE=docker-compose.yml:docker-compose.clients.yml
```

`COMPOSE_FILE` está activado porque ya hay (o habrá) despliegues de clientes; lo añade
`subscription.sh init`. El fichero `docker-compose.clients.yml` lo regenera ese script por completo —
**no se edita a mano**.

Arranque y comprobación:

```bash
# Construir y levantar el stack base (app + nginx + db)
docker compose up -d --build

# Si aún no existe el cert wildcard self-signed que nginx necesita
bash scripts/setup-ssl.sh

# Estado de los contenedores
docker compose ps

# Logs del plano de control
docker compose logs -f app
```

El plano de control responde por HTTPS en `https://localhost:8443`. La SPA en dev no lo llama
directamente: usa el proxy de Vite (ver §4).

---

## 2. Activar el paquete (`API_PACKAGE=Cms`)

Un despliegue sirve **un solo paquete**, fijado por la variable `API_PACKAGE`. Para que el servicio
`app` sea el plano de control y exponga la API de administración del CMS, el `.env` de `src/` debe
declarar:

```
API_PACKAGE=Cms
```

Esto es lo que hace que las migraciones, seeders y comandos `cms:*` del paquete `Cms` estén activos en
ese contenedor. Si `app` corriera con otro paquete, los comandos `cms:register-deploy` y compañía no
existirían y `subscription.sh` avisaría de que no pudo registrar el despliegue (ver §6 y §8).

Las demás claves de control relevantes en `src/.env`:

```
# Secreto de control de ESTE deploy (vacío = endpoints /control desactivados)
CORE_CONTROL_SECRET=dev-control-secret

# El CMS llama a los deploys por su subdominio interno con cert wildcard self-signed -> no verificar TLS
CMS_CONTROL_VERIFY=false

# CORS para llamadas directas del SPA al API en prod; en dev se usa el proxy de Vite
CORS_ALLOWED_ORIGINS=http://localhost:5174
```

> `CMS_CONTROL_VERIFY` alimenta la clave de paquete `config('cms.cms.control.verify')` (ver el *gotcha*
> de config namespaced en §9). En dev se deja en `false` porque los despliegues usan el cert wildcard
> self-signed; en producción debe ser `true`.

---

## 3. Migrar y sembrar (`core:migrate` / `core:seed`)

La base de datos del CMS (tablas `clients`, `deployments`, `subscriptions`, `invoices`,
`quota_metrics`, `activity_events`, además de las de `Core`) se crea con los comandos de `Core`, que
combinan las migraciones de `Core` con las del paquete activo. Resuelven el paquete a partir de
`API_PACKAGE` si no se pasa `--package`.

### Migrar

```bash
# Migra Core + Cms contra la conexión por defecto
docker compose exec app php artisan core:migrate --package=Cms

# Variantes útiles
docker compose exec app php artisan core:migrate --package=Cms --seed   # migra y siembra
docker compose exec app php artisan core:migrate --package=Cms --fresh  # tira todo y re-migra
```

`core:migrate` carga dos rutas de migración: `packages/Core/database/migrations` y
`packages/{Paquete}/database/migrations`.

### Sembrar — y el matiz de usar `core:seed` explícito

```bash
docker compose exec app php artisan core:seed --package=Cms
```

El matiz importante: hay que **invocar `core:seed` explícitamente** (o `core:migrate --seed`), no el
`db:seed` de Laravel a secas. La razón es que `core:seed` fija
`CorePackagesDatabaseSeeder::$targetPackage = $package` antes de llamar a `db:seed` y lo limpia
después. Ese flag es lo que dirige el seeder de paquetes hacia el namespace correcto
(`Packages\Cms\...`) y ejecuta `CmsDatabaseSeeder`. Si lanzas `db:seed` directo, `$targetPackage` se
queda en `null` y la siembra del paquete `Cms` no ocurre.

Para producción añade `--force`. Para correr un seeder concreto: `core:seed --class=...`.

El `CmsDatabaseSeeder` siembra el mismo dataset coherente que la SPA trae como fixtures
(`cms/src/store/fixtures.ts`): 8 clientes, 15 despliegues (con estados `active`/`grace`/`suspended`),
suscripciones, facturas, cuotas, eventos de actividad y 48 snapshots horarios de uso por despliegue.
También crea los permisos y el rol `admin` (guard `sanctum`). Es idempotente (`updateOrCreate`).

### Credenciales seed

El usuario administrador del panel lo siembra `Core` (`CoreDatabaseSeeder`):

```
Email:    admin@snake.com
Password: secret1234
```

Las mismas credenciales valen en cada despliegue de cliente recién aprovisionado (lo recuerda la
salida de `subscription.sh add`).

---

## 4. Correr la SPA (`npm run dev` en `:5174`)

La SPA vive en `cms/` (React + IBM Carbon, Vite + Vitest). Configuración relevante en
`cms/vite.config.ts`:

- Servidor de dev en el puerto **5174**.
- Proxy: `'/api'` → `https://localhost:8443`, con `changeOrigin: true` y `secure: false`.

Arranque:

```bash
cd cms
npm install   # la primera vez
npm run dev   # http://localhost:5174
```

El proxy es la pieza clave del transporte en dev: la SPA hace peticiones a `/api/...` (mismo origen),
y Vite las reenvía al backend HTTPS aceptando su certificado self-signed (`secure: false`). Así no hay
CORS ni avisos de certificado en desarrollo. En producción la SPA se sirve compilada y llama al API
directamente, y ahí sí entra en juego `CORS_ALLOWED_ORIGINS`.

Inicia sesión con `admin@snake.com` / `secret1234`.

---

## 5. Comandos artisan del CMS

Todos se ejecutan dentro del contenedor `app` (que corre con `API_PACKAGE=Cms`). Los registra
`CmsServiceProvider` cuando corre en consola.

### `cms:register-deploy` — registrar un despliegue vivo

Da de alta un despliegue en el registro de la plataforma para que la SPA pueda gobernarlo. Almacena su
`base_url` y su `control_secret` (cifrado) y crea/actualiza la fila de cliente. Es idempotente
(`updateOrCreate`).

```bash
docker compose exec app php artisan cms:register-deploy <deployment> \
    --package=Cms \
    --base-url=https://demoa.snake.test/api/v1 \
    --secret=<CORE_CONTROL_SECRET-del-deploy> \
    --client=<cliente> \
    --client-name="Nombre humano"
```

`--secret` es obligatorio (es el `CORE_CONTROL_SECRET` del despliegue). Si falta, el comando aborta.
`--base-url` por defecto es `https://{deployment}.snake.test/api/v1`; `--client` por defecto el propio
id del despliegue.

### `cms:deregister-deploy` — quitar un despliegue del registro

```bash
docker compose exec app php artisan cms:deregister-deploy <deployment>
```

Borra la fila del despliegue, su espejo de cuotas (`QuotaModel`) y sus snapshots de uso
(`UsageSnapshotModel`). La fila de cliente se conserva. Si el despliegue no estaba registrado, avisa y
termina con éxito.

### `cms:sync-deploys` — refrescar estado/uso desde `/control`

```bash
docker compose exec app php artisan cms:sync-deploys          # todos
docker compose exec app php artisan cms:sync-deploys --id=demoa  # solo uno
```

Recorre los despliegues registrados con `base_url` y refresca su estado, cuotas y snapshots de uso
llamando a sus endpoints `/control`. Los despliegues inalcanzables se **omiten** (no es fatal:
captura `CmsControlException` y lo reporta como `skip`). Imprime un resumen `Synced N, skipped M of T`.

Está **programado automáticamente cada hora** (`->hourly()->withoutOverlapping()`) por
`CmsServiceProvider`, por lo que requiere que el `schedule:run` de Laravel esté corriendo (cron del
contenedor). Esto mantiene fresco el espejo local y los charts de plataforma sin sincronizaciones
manuales.

### `core:usage:rollup` — agregar logs de uso por despliegue

Este comando es de `Core` y corre **en cada despliegue** (no en el plano de control). Agrega
`core_request_logs` en rollups por hora/día de forma idempotente:

```bash
# Dentro de un contenedor de despliegue, p. ej. app-demoa
docker compose exec app-demoa php artisan core:usage:rollup            # period=both
docker compose exec app-demoa php artisan core:usage:rollup --period=hour
docker compose exec app-demoa php artisan core:usage:rollup --period=day --prune
```

`--period` admite `hour|day|both` (por defecto `both`). `--prune` elimina filas crudas más antiguas
que `core.metering.retention_days`.

> Matiz importante: el rollup solo agrega buckets de hora/día **ya cerrados**; la hora en curso queda
> fuera. Por eso, recién provisionado un despliegue, la pestaña Usage puede tardar hasta una hora en
> mostrar datos agregados.

---

## 6. Provisionar un despliegue con `subscription.sh`

`scripts/subscription.sh` aprovisiona despliegues reales y aislados (BD + contenedor + vhost propios) y
los **auto-registra en el CMS**, de modo que el panel gobierna despliegues de verdad.

```bash
# Una sola vez: preparar el wiring (activa COMPOSE_FILE y crea docker-compose.clients.yml)
bash scripts/subscription.sh init

# Alta de un despliegue: <cliente> <Paquete>
bash scripts/subscription.sh add demoa Core

# Listado
bash scripts/subscription.sh list
```

Qué hace `add <cliente> <Paquete>`, en orden:

1. Valida el nombre (minúsculas/números/guiones, 2–31 chars; rechaza `app`, `db`, `nginx`, `www`) y
   exige el cert wildcard (`bash scripts/setup-ssl.sh`).
2. Crea BD, usuario y password en MariaDB, todos llamados como el paquete en `snake_case`
   (`MiPaquete` → `mi_paquete`). De ahí la regla **una suscripción por paquete**.
3. Genera un secreto de control `CONTROL_SECRET="sk_ctl_live_$(openssl rand -hex 12)"` y lo inyecta en
   el contenedor como `CORE_CONTROL_SECRET` (esto **activa los endpoints `/control`** del despliegue).
4. Regenera `docker-compose.clients.yml`: añade el servicio `app-<cliente>` y un **alias de red** en
   nginx (`<cliente>.<BASE_DOMAIN>`) para que el contenedor `app` (el CMS) resuelva el subdominio por
   la red interna.
5. Escribe el vhost de nginx (`docker/nginx/conf.d/client-<cliente>.conf.template`) y recarga nginx.
6. Levanta `app-<cliente>`, espera a que arranque (`migrate:status`) y corre **`php artisan core:seed
   --force`** dentro de él.
7. Llama a `docker compose exec -T app php artisan cms:register-deploy <cliente> --package=<Paquete>
   --base-url=https://<cliente>.<BASE_DOMAIN>/api/v1 --secret=<control_secret> --client=<cliente>`.
8. Añade la entrada al `hosts` de Windows.

Salida típica de un alta correcta:

```
Suscripcion [demoa] lista:
  URL:      https://demoa.snake.test:8443/api/v1
  Docs:     https://demoa.snake.test:8443/api/v1/docs
  Login:    admin@snake.com / secret1234 (seed por defecto)
  Control:  registrado en el CMS (controlable desde el panel)
```

Tras esto, desde el panel puedes suspender / poner en gracia / reactivar / rotar-secreto / sincronizar
ese despliegue. Una suspensión hace que el despliegue devuelva **HTTP 402** en sus rutas no exentas.

---

## 7. Quitar un despliegue con `subscription.sh`

```bash
bash scripts/subscription.sh remove demoa             # conserva la BD
bash scripts/subscription.sh remove demoa --drop-db   # borra la BD (pide confirmación)
```

`remove`:

1. Lo quita del registro del CMS (`cms:deregister-deploy <cliente>`, tolerante a fallos).
2. Para y elimina `app-<cliente>`.
3. Borra el vhost y el `.env` del cliente, regenera el compose y recarga nginx.
4. Con `--drop-db`: borra BD y usuario tras teclear el nombre del cliente para confirmar. Sin el flag,
   la BD se conserva.
5. Limpia la entrada del `hosts` de Windows.

---

## 8. Loop de verificación viva (humo)

Para comprobar que el plano de control gobierna de verdad un despliegue:

```bash
# 1. Provisionar
bash scripts/subscription.sh add demoa Core

# 2. El despliegue sirve normal (200)
curl -k https://demoa.snake.test:8443/api/v1

# 3. Suspender desde el panel (o vía el proxy /control del CMS) y verificar el kill-switch
#    -> rutas no exentas devuelven 402 Payment Required

# 4. Sincronizar el espejo local
docker compose exec app php artisan cms:sync-deploys --id=demoa

# 5. Reactivar, y desmontar
bash scripts/subscription.sh remove demoa --drop-db
```

Las rutas **exentas** del kill-switch son `api/v1` (health) y `api/v1/control/*`: siguen respondiendo
aunque el despliegue esté suspendido, lo que permite reactivarlo de forma remota.

---

## 9. Troubleshooting (gotchas reales)

### Config namespaced: `cms.cms.*`, no `cms.*`

Las configs de paquete están **namespaced por `<paquete>.<fichero>`**. El fichero
`src/packages/Cms/config/cms.php` se publica bajo la clave `cms`, así que sus valores se leen como
`config('cms.cms.control.verify')` y `config('cms.cms.operator.default_grace_hours')` — **no**
`config('cms.control.verify')`. Lo verás en `CmsControlClient` (`config('cms.cms.control', [])`) y en
`CmsDeploymentsController`. `Core`, en cambio, usa `core.*` directo. Si una opción de control "no se
aplica", casi siempre es esta doble `cms.cms`.

### El kill-switch exime `/api/v1`

`CoreEnsureActiveMiddleware` corta con **402** cuando el estado efectivo es `suspended`, pero antes
recorre `config('core.state.exempt_paths')`, que vale `['api/v1', 'api/v1/control/*']`. Por eso un
despliegue suspendido sigue respondiendo en el health (`/api/v1`) y en los endpoints de control: son la
vía para reactivarlo. Si un despliegue suspendido devuelve 200 en una ruta que esperabas 402, revisa si
cae bajo uno de esos patrones. El middleware además es *fail-open*: ante cualquier excepción interna
(tabla ausente pre-migrate, caché caída) deja pasar como `active`.

### Entorno de compose vs `.env`

El servicio `app` toma su entorno del `.env` de `src/` (incluido `API_PACKAGE=Cms`). Los servicios
`app-<cliente>`, en cambio, reciben su entorno **inyectado en el bloque `environment:` del compose
generado** (`API_PACKAGE`, `CORE_CONTROL_SECRET`, credenciales de BD…), no de ningún `.env`. Si cambias
`src/.env` esperando afectar a un despliegue de cliente, no pasará nada: hay que regenerar el compose
(vía `subscription.sh`) o editar la variable en el contenedor correspondiente. Cambios en `src/.env`
para el propio CMS requieren recrear/reiniciar `app` (y `php artisan config:clear` si había caché de
config).

### CRLF en `subscription.sh`

El script edita el `hosts` de Windows, que usa finales de línea **CRLF**. Al quitar un cliente, el
borrado de la entrada usa una regex anclada con `\r` opcional (`snake-sub:$c\r?$`) precisamente para no
"comerse" `cliente10` al quitar `cliente1`. Corolario operativo: ejecuta el script con `bash` (no lo
conviertas a CRLF ni lo edites con un editor de Windows que reescriba los finales de línea), o
`set -euo pipefail` y los heredocs fallarán de formas confusas.

### `database is locked` (SQLite)

Síntoma: con SQLite, el CMS lanzaba varias peticiones en paralelo desde la SPA y, al compartir la misma
BD para caché y throttle, aparecía `database is locked`. Arreglo, ya aplicado en
`src/config/database.php` (conexión `sqlite`): **WAL + busy_timeout**, de modo que las lecturas
concurrentes corren durante una escritura y, ante un lock, esperan en vez de fallar:

```php
'busy_timeout'  => env('DB_BUSY_TIMEOUT', 5000),
'journal_mode'  => env('DB_JOURNAL_MODE', 'WAL'),
'synchronous'   => env('DB_SYNCHRONOUS', 'NORMAL'),
```

Además se movió la caché fuera de la BD: `CACHE_STORE=file`. Si el error reaparece, confirma que el
`.env` tiene `CACHE_STORE=file` y que la BD no quedó en `journal_mode=DELETE`.

### `Too Many Attempts` (rate limit)

Síntoma: la SPA, al cargar varias vistas a la vez, chocaba contra el limitador y recibía **429 Too Many
Attempts**. Los límites están en `CoreRouteServiceProvider`:

```php
RateLimiter::for('api',   fn ($r) => Limit::perMinute((int) env('CORE_API_RATE_LIMIT', 240))->by(...));
RateLimiter::for('login', fn ($r) => Limit::perMinute((int) env('CORE_LOGIN_RATE_LIMIT', 10))->by($r->ip()));
```

El límite de API se subió a **240/min** para absorber las ráfagas paralelas del panel; el de login se
mantiene bajo (10/min por IP) a propósito. Si necesitas más holgura en dev, ajusta `CORE_API_RATE_LIMIT`
en el entorno. Ojo: las respuestas 402 del kill-switch y los 429 del throttle son cosas distintas —
mira el cuerpo y la cabecera `X-Package-Status` para distinguirlas.

### Cert wildcard self-signed + `verify=false`

El CMS llama a cada despliegue por su subdominio interno (`<cliente>.<BASE_DOMAIN>`), servido con el
cert **wildcard self-signed** que genera `bash scripts/setup-ssl.sh`. Por eso en dev se pone
`CMS_CONTROL_VERIFY=false` (→ `config('cms.cms.control.verify')`): sin ello, el cliente HTTP de control
rechazaría el certificado y todo `sync`/acción remota fallaría con un error de TLS. En la SPA, el mismo
problema se evita con el proxy de Vite (`secure: false`). En **producción**, donde los despliegues
tienen certificados válidos, `CMS_CONTROL_VERIFY` debe ser `true`. Si las acciones remotas fallan con
errores de certificado, revisa: (a) que existe `docker/nginx/certs/wildcard.<BASE_DOMAIN>.pem`, y
(b) el valor de `CMS_CONTROL_VERIFY`.
