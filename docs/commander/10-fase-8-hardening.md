# Fase 8 — Hardening + cierre

## Contexto (por qué)

Las fases 1 a 7 dejaron el panel funcional de punta a punta: el Commander registra despliegues, los
controla por el canal firmado `/control`, cablea el SPA a la API real, aprovisiona despliegues vivos,
pinta gráficas por-despliegue y de plataforma, y rota el secreto de control en runtime. Pero
"funcional" no es lo mismo que "operable a diario". Esta fase cierra las puntas que solo aparecen
cuando uno *vive* con el panel: el mirror local se queda viejo si nadie pulsa **Sync** a mano, un
despliegue de producción servido desde otro origen choca con CORS, y la rotación de secreto —la
acción más sensible que existe— no dejaba ninguna huella auditable. Encima, usar el SPA de verdad
destapó dos fallos operativos de SQLite y del rate limiter que no se ven con peticiones de una en una.

El objetivo de la fase es que el operador pueda dejar el panel encendido y olvidarse: que se
mantenga fresco solo, que despliegue en producción sin pelearse con cabeceras, que cada rotación
quede registrada, y que el backend aguante el patrón de carga real del SPA (varias peticiones en
paralelo por navegación).

## Qué se construyó

1. **Auto-sync programado.** Se extrajo la lógica de refresco a un servicio compartido
   `CommanderDeploymentSyncService`, se añadió un comando de consola `commander:sync-deploys` y se programó
   horariamente desde `CommanderServiceProvider`. Así el mirror local (estado, cuotas y snapshots de uso)
   se mantiene fresco sin que nadie pulse **Sync**.
2. **CORS para producción.** Configuración de orígenes permitidos vía `CORS_ALLOWED_ORIGINS`, con el
   matiz —documentado en el propio fichero— de que el `cors.php` que de verdad consume `HandleCors`
   es el del *app* (`config/cors.php`), no el que Core publica bajo la clave namespaced `core.cors`.
3. **Auditoría de rotación.** Cada rotación de secreto graba un `ActivityEvent` con `kind = 'rotate'`,
   que el SPA ya sabe pintar en el feed de actividad del Dashboard.
4. **Arreglos operativos del SPA en vivo.** Se resolvió el `database is locked` de SQLite activando
   WAL + `busy_timeout` y moviendo la caché fuera de la BD (`CACHE_STORE=file`), y el `Too Many
   Attempts` subiendo el rate limit de la API a 240/min (`CORE_API_RATE_LIMIT`).

## Decisiones clave (y por qué)

**Un servicio compartido en vez de duplicar el refresco.** El refresco del mirror lo necesitaban tres
sitios distintos: la acción on-demand `CommanderDeploymentsController::sync` (botón **Sync** del SPA), el
nuevo comando programado, y las acciones de estado del operador, que reflejan el estado que el
despliegue devuelve. Tener tres copias de la misma lógica de "leer `/control/state` + `/control/usage`
y volcarlo al mirror" garantiza que se desincronicen. Por eso se centralizó en
`CommanderDeploymentSyncService::sync(DeploymentModel): DeploymentModel`, que es la única fuente de verdad
del refresco.

**El schedule va dentro del paquete, no en `routes/console.php` del app.** El paquete Commander es
autocontenido: registra sus comandos y su programación desde su propio service provider. Esto mantiene
el app-host limpio (solo necesita un `schedule:run` por cron) y hace que el paquete sea instalable sin
tocar ficheros del app.

**Hourly + `withoutOverlapping()`.** El uso se agrega en buckets de hora cerrados (`core:usage:rollup`
deja fuera la hora en curso), así que un refresco por hora cuadra con la granularidad del dato; no hay
ganancia en correr más a menudo. `withoutOverlapping()` evita que dos ejecuciones se pisen si una
tanda de despliegues lentos no termina antes de la siguiente hora.

**Los despliegues inalcanzables se saltan, no son fatales.** Una flota real tiene despliegues caídos o
en mantenimiento. El comando captura `CommanderControlException` por despliegue, lo cuenta como `skipped` y
sigue; un solo despliegue muerto no debe abortar el refresco de los demás.

**Caché fuera de la BD.** Con SQLite, mantener la caché y el throttle en la *misma* BD que los datos de
negocio multiplica los escritores que compiten por el único fichero. Mover la caché a `file` quita esa
presión del camino crítico sin montar Redis en dev.

## Cómo funciona

### Auto-sync

El comando recorre los despliegues registrables y delega cada uno en el servicio compartido:

```
php artisan commander:sync-deploys            # toda la flota
php artisan commander:sync-deploys --id=<id>  # un solo despliegue
```

`CommanderSyncDeploysCommand::handle(CommanderDeploymentSyncService $sync)` selecciona solo los despliegues con
`base_url` no vacío (los seed falsos sin URL no se intentan), opcionalmente filtra por `--id`, y por
cada uno llama a `$sync->sync($deployment)`. Cuenta `ok` / `skipped` y resume al final con
`Synced {ok}, skipped {skipped} of {total}.`. Las excepciones `CommanderControlException` se emiten como
`warn("skip {id}: {mensaje}")` y no detienen el bucle.

`CommanderDeploymentSyncService::sync()` hace, en este orden:

1. `getState()` y `getUsage()` contra el despliegue (período `hour`, ventana de las últimas 48 h,
   `per_page = 1000`).
2. `applyState()` vuelca el estado a las columnas planas del mirror, tomando
   `effective_status ?? status` y sellando `changed_at` solo cuando hay un *flip* real de estado.
3. `applyQuotas()` hace upsert de las cuotas en `commander_quota_metrics` (id sintético
   `<deployment_id>:<metric>`).
4. `applySnapshots()` agrega el uso por bucket horario y lo upserta en `commander_usage_snapshots` (id
   `<deployment_id>:hour:<timestamp>`), que es lo que alimenta las gráficas de plataforma.
5. Recalcula `requests_24h`, `quota_health` (peor ratio `current/limit` entre las cuotas limitadas),
   sella `last_seen` y guarda; devuelve el modelo `fresh()`.

El schedule se registra en `CommanderServiceProvider::boot()`, solo en consola, dentro de
`$this->app->booted(...)` para que el `Schedule` ya esté disponible:

```php
$this->app->make(Schedule::class)
    ->command('commander:sync-deploys')
    ->hourly()
    ->withoutOverlapping();
```

En el host basta con el cron estándar de Laravel que dispara `schedule:run` cada minuto; él se encarga
de invocar `commander:sync-deploys` a la hora en punto.

### CORS en producción

En desarrollo el SPA usa el proxy de Vite (`/api` → `https://localhost:8443`, mismo origen), así que
CORS ni se ejerce. En producción el SPA se sirve desde otro origen y llama a la API directamente, y ahí
sí entra en juego. Los orígenes salen de `CORS_ALLOWED_ORIGINS` (lista separada por comas):

```
CORS_ALLOWED_ORIGINS=https://panel.midominio.com,https://otro.midominio.com
```

El **gotcha** clave —y la razón de que esto merezca su propio apartado— es de qué fichero lee
`HandleCors`. El middleware consume la clave de config `cors`, que corresponde al fichero del *app*
`config/cors.php`. Core publica su propio `packages/Core/config/cors.php`, pero por la convención de
config namespaced del paquete ese fichero vive bajo `core.cors` y **NO** es lo que `HandleCors` mira.
Quien controla el CORS efectivo de producción es `config/cors.php`: `paths` en `['api/*']`,
`allowed_origins` desde `CORS_ALLOWED_ORIGINS` (por defecto `http://localhost:5174`, el puerto del SPA
en dev), y `supports_credentials => false` porque la auth es por Bearer token, no por cookies. Editar
el `core.cors` y esperar que cambie el comportamiento es perder el rato; hay que tocar el del app.

### Auditoría de rotación

`CommanderDeploymentsController::rotateSecret()` genera el secreto nuevo
(`'sk_ctl_live_' . bin2hex(random_bytes(12))`), lo entrega al despliegue firmado con el secreto
*actual* (`CommanderControlClient::rotateSecret`), y solo si el remoto lo acepta lo adopta localmente:
`control_secret = $new`, `control_secret_rotated_at = now()`. Acto seguido deja la huella auditable:

```php
$this->recordActivity('rotate', $deployment, null, '…' . substr($new, -4));
```

`recordActivity()` inserta un `ActivityEventModel` con `id = 'act_' . Str::random(24)`, `at = now()`,
`kind = 'rotate'`, el `deployment_id`, `by = auth()->user()?->name ?? 'operator'` y como `message` el
sufijo del nuevo secreto (`…XXXX`) — nunca el secreto entero, solo los últimos 4 caracteres para poder
identificarlo sin filtrarlo. El SPA conoce el tipo (`ActivityKind` incluye `'rotate'` en
`store/types.ts`) y lo pinta en el feed del Dashboard como
`<entidad> control secret rotated → …XXXX · by <quién>`.

> Nota: el comentario de la migración `commander_activity_events` enumera
> `suspend|grace|resume|paid|quota` y no menciona `rotate`, pero la columna es un `string(16)` libre,
> así que `'rotate'` cabe sin cambios de esquema; solo está sin actualizar el comentario.

## Verificación

- **Auto-sync manual:** `php artisan commander:sync-deploys` sobre la flota viva imprime
  `Synced N, skipped M of T.`; con un despliegue apagado, ese aparece como `skip <id>: ...` y el resto
  se sincroniza igual. `--id=<id>` refresca uno solo.
- **Schedule:** `php artisan schedule:list` muestra `commander:sync-deploys` programado `hourly`. Disparar
  `php artisan schedule:run` en la hora en punto lo ejecuta y se ve reflejado en `last_seen` y en los
  snapshots del mirror.
- **CORS:** con `CORS_ALLOWED_ORIGINS` apuntando al origen del SPA de producción, una petición
  `OPTIONS` preflight a `/api/...` devuelve `Access-Control-Allow-Origin` con ese origen; un origen
  fuera de la lista no recibe la cabecera. (En dev no aplica por el proxy de Vite.)
- **Auditoría de rotación:** rotar el secreto desde el SPA (overflow de la fila o botón **Rotate
  secret…** del detalle) produce un toast `Control secret rotated · New secret ends …XXXX` y una nueva
  entrada `rotate` en el feed de actividad del Dashboard; el despliegue real empieza a aceptar el
  secreto nuevo y rechaza el viejo en runtime, sin reinicio.
- **Arreglos operativos:** ejercitando el SPA con navegaciones que disparan varias peticiones en
  paralelo (loadData + uso por-página), ya no aparece `database is locked` ni `Too Many Attempts`.

## Gotchas

- **`HandleCors` lee `config('cors')`, es decir `config/cors.php` del app.** El `core.cors` del paquete
  es decorativo a efectos de CORS; quien manda en producción es el fichero del app. Es el error fácil:
  editar el equivocado.
- **El comentario de la migración de actividad está desactualizado.** Dice
  `suspend|grace|resume|paid|quota`; el código emite también `'rotate'` y la columna lo admite porque
  es un `string(16)` sin restricción. No hace falta migrar nada.
- **`database is locked` con SQLite.** Tener caché y throttle en la *misma* BD SQLite que los datos,
  más el patrón del SPA de varias peticiones concurrentes, generaba escritores que se bloqueaban entre
  sí. La cura fue triple: `journal_mode = WAL` (lecturas concurrentes durante una escritura),
  `busy_timeout = 5000` (esperar el lock en vez de fallar) y `CACHE_STORE=file` (sacar la caché del
  fichero de datos). Está en el `.env` activo (`DB_CONNECTION=sqlite`, `CACHE_STORE=file`) y los
  *defaults* viven en `config/database.php`, en la conexión `sqlite`:
  `'busy_timeout' => env('DB_BUSY_TIMEOUT', 5000)`, `'journal_mode' => env('DB_JOURNAL_MODE', 'WAL')`,
  `'synchronous' => env('DB_SYNCHRONOUS', 'NORMAL')`.
- **`Too Many Attempts` (HTTP 429).** El SPA abanica varias peticiones por navegación, así que el
  límite por defecto de Laravel se agotaba rápido. El limiter `api` se hizo generoso y env-tuneable:
  `Limit::perMinute((int) env('CORE_API_RATE_LIMIT', 240))`, en
  `CoreRouteServiceProvider::configureRateLimiting()`. Ojo: ese mismo provider mete
  `CoreMeteringMiddleware` como el más externo del grupo `api`, de forma que también se contabilizan los
  429 del throttle y los 402 del kill-switch.
- **El sync solo intenta despliegues con `base_url`.** Los registros seed sin URL se ignoran por
  diseño; no son errores, simplemente no hay a quién llamar.

## Ficheros tocados

- `src/packages/Commander/app/Services/CommanderDeploymentSyncService.php` — servicio compartido de refresco
  (estado + cuotas + snapshots de uso) extraído para que lo reusen el botón **Sync**, el comando
  programado y las acciones de estado del operador.
- `src/packages/Commander/app/Console/Commands/CommanderSyncDeploysCommand.php` — comando `commander:sync-deploys`
  (`--id` opcional); salta inalcanzables y resume `Synced/skipped`.
- `src/packages/Commander/app/Providers/CommanderServiceProvider.php` — registra el comando y lo programa
  `hourly()->withoutOverlapping()` dentro de `app->booted`.
- `src/packages/Commander/app/Http/Controllers/CommanderDeploymentsController.php` — `rotateSecret()` graba el
  `ActivityEvent` `kind = 'rotate'` con el sufijo del nuevo secreto vía `recordActivity()`.
- `config/cors.php` (app) — CORS efectivo de producción desde `CORS_ALLOWED_ORIGINS`; comentario que
  aclara que este, y no `core.cors`, es el que consume `HandleCors`.
- `src/config/database.php` — conexión `sqlite` con WAL + `busy_timeout` + `synchronous` env-tuneables.
- `src/packages/Core/app/Providers/CoreRouteServiceProvider.php` — rate limit `api` env-tuneable
  (`CORE_API_RATE_LIMIT`, default 240/min) y orden de middlewares del grupo `api`.
- `.env` — `CACHE_STORE=file`, `CORS_ALLOWED_ORIGINS`, y (donde se desvíen de los defaults) las claves
  `DB_*` de SQLite.

Para el resto del recorrido por fases, ver los documentos hermanos de `docs/commander/`.
