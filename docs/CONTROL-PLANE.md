# Control Plane — estado, metering y cuotas por deploy

Base implementada en Core para operar la plataforma como un negocio: cada deploy
mide su uso, conoce su estado (activo/gracia/suspendido) y expone una superficie
de gestion firmada para el futuro Commander. Todo vive en la BD de **cada** deploy.

## 1. Objetivo y vision (monorepo)

Este repo convivira tres piezas:

| Pieza | Donde | Estado |
|---|---|---|
| **API** (Core + paquete de negocio) | cada subdominio de cliente | hecho |
| **Commander** (panel tipo Heroku) | `packages/Commander` en el deploy principal (`API_PACKAGE=Commander`) | hecho |
| **Web publica + UI Commander** | React/Vite servidas en el dominio principal (SPA en `commander/`) | hecho |

El Commander gestionara N deploys de clientes llamando a los endpoints `/control` de
cada uno. Cada deploy es autonomo: si el Commander cae, los clientes siguen sirviendo.

## 2. Modelo de datos

Cuatro tablas con prefijo `core_` (las de Laravel/Spatie/Sanctum van sin
prefijo — no hay colision). Migran automaticamente en cada deploy.

### `core_package_state` (single-row, id=1)

Estado del deploy. Una sola fila tipada gana a un key-value: enum + timestamp.

| Columna | Tipo | Notas |
|---|---|---|
| status | string(20) | `active` \| `grace` \| `suspended` (seed: active; fila ausente = active) |
| reason | string(100) null | codigo maquina: `manual`, `payment_overdue`, `quota_exceeded:<metric>` |
| message | text null | texto para el cliente, se devuelve en el 402 |
| grace_until | timestamp null | un grace vencido se comporta como suspended (resuelto en lectura) |
| changed_by | string(50) null | `cli` \| `quota` \| `cms` |
| control_secret | text null (encrypted) | override runtime del secreto HMAC (set via `POST /control/rotate-secret`); precede a `CORE_CONTROL_SECRET`; nunca se serializa |

### `core_request_logs` (cruda, write-heavy)

Una fila por request medido. **Un solo indice** (`occurred_at`) ademas del PK:
sirve al barrido del rollup y a la poda, y mantiene el insert barato.

`occurred_at`(idx), `method`, `route` (patron de ruta, baja cardinalidad; null
si no hubo match), `path`, `status`, `duration_ms`, `user_id` (sin FK),
`token_id`, `ip`, `request_bytes`, `response_bytes`, `memory_kb`.

### `core_usage_rollups` (agregados)

`period` (hour|day), `period_start` (UTC), `route` ('' = sin ruta), `method`,
`status_class` (2xx..5xx), `request_count`, `error_count`, `avg_duration_ms`,
`p95_duration_ms`, `total_request_bytes`, `total_response_bytes`,
`unique_users`. **UNIQUE (period, period_start, route, method, status_class)**
→ re-rollup idempotente via upsert.

### `core_quota_metrics`

`metric_key` (unique), `limit_value` (null = sin limite), `current_value`,
`measured_at`, `on_exceed` (none|warn|suspend), `exceeded_at`.
Metricas seed: `disk_db_mb`, `disk_storage_mb`, `requests_day`, `users_total`.

## 3. Flujo de request

```
request
  └─ CoreMeteringMiddleware (handle: marca inicio)        [primero del grupo 'api']
       └─ throttle:api                                     (429 tambien se mide)
            └─ CoreEnsureActiveMiddleware                  (kill-switch, cacheado)
                 ├─ suspended → 402 JSON {status, reason, message}
                 ├─ grace     → continua + X-Package-Status/Grace-Until/Message
                 └─ active    → continua
                      └─ auth:sanctum → controller → response
  └─ terminate(): INSERT core_request_logs                 (la respuesta ya salio: 0 latencia con FPM)
```

- Un deploy **suspendido sigue midiendo**: los 402 son datos para el Commander.
- Los 404 sin ruta no pasan por el grupo 'api' → no se miden (solo rutas reales).
- Metering jamas rompe un request: try/catch total + `report()`.

## 4. Kill-switch

- Lector tonto de `core_package_state` con cache (`CORE_STATE_CACHE_TTL`, 30s).
  Con `CACHE_STORE=database` el cache es una fila en la misma BD: sigue siendo
  una lectura PK barata y la propagacion de un cambio de estado es ≤ TTL.
- **Quien suspende, escribe la tabla** via `CorePackageStateService::set()`:
  CLI (`core:status`), cuotas (`core:quota:measure`) y Commander (`POST /control/state`).
  Punto de convergencia unico — añadir mecanismos no toca el middleware.
- `402 Payment Required` para suspended (403 seria "prohibido por identidad";
  402 expresa "relacion de servicio caducada"). Grace: pasa con headers de aviso.
- Exenciones (`core.state.exempt_paths`): health (`api/v1`) y `api/v1/control/*`
  (un deploy suspendido debe poder reactivarse). Las docs de Scramble estan
  fuera del grupo 'api' → exentas de serie.
- Fail-open: si la tabla no existe aun (primer boot pre-migrate) o el cache
  falla, se comporta como active y reporta el error.

## 5. Metering y rollups

- Config en `core.metering`: `enabled`, `sample_rate` (0–1), `retention_days`,
  `skip_paths`. Con sampling < 1, `requests_day` infra-cuenta (escalar 1/rate).
- `core:usage:rollup` agrega buckets **cerrados** UTC (hora/dia). El ultimo
  bucket agregado se recalcula en cada pasada (absorbe escrituras tardias del
  terminate); el upsert con la unique key lo hace idempotente.
- p95 exacto calculado desde la tabla cruda (los percentiles **no componen**:
  el dia no puede derivarse de las horas). Por eso `retention_days >= 2`.
- `--prune` borra crudo anterior a la retencion en lotes de 5000 (sin locks largos).
- Crecimiento: con el throttle por defecto de 240 rpm (`CORE_API_RATE_LIMIT`) el techo es
  ~345k filas/dia ≈ 60–100 MB/dia en MariaDB. Valvulas: retencion y sample_rate.

## 6. Cuotas

Ciclo: `core:quota:measure` → mide → persiste → compara con `limit_value` →

- **over** + `on_exceed=warn` → log warning.
- **over** + `on_exceed=suspend` → si active ⇒ **grace** con deadline
  (`CORE_QUOTA_GRACE_HOURS`, 72h) y reason `quota_exceeded:<metric>`; si el
  grace propio vencio ⇒ **suspended** definitivo.
- **bajo el limite de nuevo** → limpia `exceeded_at` y **auto-heal**: vuelve a
  active SOLO si la suspension la causo esa misma metrica (nunca pisa una
  suspension manual o del Commander).

Medicion cross-driver: `disk_db_mb` usa `information_schema` en MariaDB/MySQL y
`filesize()` (+ WAL) en sqlite; driver desconocido → null (degrada sin romper).
Añadir una metrica = fila nueva en `core_quota_metrics` + case en
`CoreQuotaMeasurerService::measure()`.

## 7. Integracion con el Commander (push, confirmado)

Cada deploy expone `/api/v1/control/*`, firmado HMAC, **solo si**
`CORE_CONTROL_SECRET` esta definido (sin secreto las rutas no se registran).
Excluidos de la documentacion publica de Scramble.

### Contrato

```
GET  /api/v1/control/state              → { state, package, quotas[] }
POST /api/v1/control/state              → { state }
     body: { status: active|grace|suspended, reason?, message?, grace_until? }
GET  /api/v1/control/usage              → paginado de core_usage_rollups
     query: period=hour|day, from?, to?, per_page?
POST /api/v1/control/rotate-secret      → { rotated, suffix }
     body: { new_secret }   (min 16 / max 128; nuevo override de core_package_state.control_secret)
```

### Firma (por request)

```
payload  = timestamp + "\n" + METHOD + "\n" + /path + "\n" + sha256(body)
firma    = hex( hmac_sha256(payload, CORE_CONTROL_SECRET) )
headers  = X-Control-Timestamp: <unix>, X-Control-Signature: <firma>
```

Anti-replay por ventana de reloj (`CORE_CONTROL_CLOCK_SKEW`, ±300s) y
comparacion `hash_equals`. Ejemplo bash (el Commander hara esto en PHP):

```bash
TS=$(date +%s); BODY='{"status":"suspended","reason":"payment_overdue"}'
HASH=$(printf '%s' "$BODY" | sha256sum | cut -d' ' -f1)
SIG=$(printf '%s\nPOST\n/api/v1/control/state\n%s' "$TS" "$HASH" \
      | openssl dgst -sha256 -hmac "$SECRET" -hex | sed 's/.*= //')
curl -X POST https://cliente.dominio.com/api/v1/control/state \
     -H "X-Control-Timestamp: $TS" -H "X-Control-Signature: $SIG" \
     -H 'Content-Type: application/json' -d "$BODY"
```

### El package Commander (implementado)

BD propia del deploy principal con el registro de la plataforma:

- `clients` (datos de facturacion/contacto)
- `deployments` (client_id, package, base_url, **control_secret cifrado**
  (cast `encrypted`), estado conocido, last_seen)
- `subscriptions` / `invoices` (plan, billing status) — al marcar impago, una
  accion llama a `POST {base_url}/api/v1/control/state` con suspend/grace
- `usage_snapshots` (cache local de `GET /control/usage` para dashboards)

UI React (Vite) consumiendo el `/api/v1` del propio deploy Commander.

### Alternativas descartadas

- **Pull/heartbeat** (el deploy pregunta al Commander): re-introduce la dependencia
  del Commander para operar y necesita scheduler vivo en cada deploy para el control.
- **BD directa** (el Commander escribe core_package_state de cada BD): se rompe en
  cuanto un deploy viva en otro servidor; acopla el Commander a la topologia de BD.
- **Telemetria push** (deploy → Commander con rollups): compatible y complementaria,
  puede añadirse despues sin tocar nada de lo actual.

## 8. Operativa

```bash
php artisan core:status                                   # ver estado + cuotas
php artisan core:status --set=suspended --reason=manual --message="..."
php artisan core:status --set=grace --grace-until="+72 hours"
php artisan core:status --set=active

php artisan core:usage:rollup --prune                     # agrega + poda
php artisan core:quota:measure [--dry-run]                # mide + aplica on_exceed
```

Scheduling (definido en `src/routes/console.php`): rollup cada hora, cuotas
cada 6h. Requiere un proceso de scheduler; opciones:

```bash
# manual / cron del host:
docker compose exec app php artisan schedule:run
```

```yaml
# snippet para docker-compose.yml cuando se quiera automatizar:
  scheduler:
    image: snake-laravel-api-app
    restart: unless-stopped
    working_dir: /var/www/src
    volumes:
      - ./src:/var/www/src
      - /var/www/src/bootstrap/cache
    command: php artisan schedule:work
    depends_on:
      db:
        condition: service_healthy
    networks:
      - app-network
```

Sin scheduler los comandos siguen siendo utilizables a mano; lo unico que crece
sin poda es `core_request_logs`.

## 9. Riesgos y limites conocidos

- **Terminable + FPM**: con nginx+fpm la respuesta sale antes del insert (cero
  latencia cliente; coste = worker ocupado unos ms). Con `artisan serve` el
  insert añade latencia (solo local).
- **p95 historico**: congelado tal como se calculo una vez podado el crudo.
- **sqlite bajo carga**: posibles `SQLITE_BUSY` en inserts concurrentes de
  metering → el try/catch los traga (se pierde una fila, nunca falla un
  request). Si la instancia sqlite recibiera trafico real: WAL + busy_timeout.
- **404 sin ruta no se miden** (no pasan por el grupo 'api').
- **APP_KEY/secretos**: `CORE_CONTROL_SECRET` distinto por deploy; rotarlo =
  actualizar .env del deploy y el registro del Commander.
