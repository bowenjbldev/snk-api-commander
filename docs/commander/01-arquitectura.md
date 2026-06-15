# Arquitectura del sistema SnakeCloud Commander

## Por qué este documento

Antes de tocar una sola línea de código del panel conviene entender qué problema
resuelve y por qué está partido en las piezas que está. SnakeCloud es una
plataforma multi-tenant del estilo de Heroku o IONOS: cada cliente que contrata
el servicio recibe un **despliegue aislado** —su propio contenedor y su propia
base de datos— que sirve una API de negocio en `/api/v1`. El reto de operar
decenas de despliegues a la vez (ver su estado, medir su uso, facturarlos,
suspender a quien no paga y reactivarlo cuando paga) es lo que justifica la
existencia de un **panel del operador**, el Commander.

La decisión de diseño que vertebra todo lo demás es esta: **los despliegues son
autónomos**. Si el panel se cae, los clientes siguen siendo servidos sin
interrupción, porque el despliegue conoce su propio estado y lo aplica él mismo.
El Commander no está en la ruta crítica de ninguna petición de cliente; es una consola
que ordena, no un proxy por el que pase el tráfico. Este documento explica los
tres componentes que materializan esa idea, el stack sobre el que corren, el
modelo de datos que comparten y cómo encajan las ocho fases de construcción.

Documentos hermanos: el contrato y la mecánica interna del control plane del
despliegue están en `docs/CONTROL-PLANE.md`; el modelo modular de paquetes y la
simulación local de suscripciones, en el `README.md` de la raíz.

## Los tres componentes y la frontera de confianza

El sistema tiene exactamente tres piezas. Dos viven en el mundo "operador" y una
se replica N veces en el mundo "cliente". Entre ambos mundos hay una **frontera
de confianza** que se cruza únicamente por un canal firmado.

### 1. El despliegue autónomo (paquete `Core`)

Es la copia que recibe cada cliente. Corre el mismo monolito Laravel
configurado por variables de entorno (`API_PACKAGE`, `DB_*`), sirviendo el
paquete `Core` siempre activo más el paquete de negocio contratado. Core aporta,
además de autenticación y CRUD base, todo el **control plane local**: mide cada
petición (`core_request_logs`), conoce su estado en una fila única tipada
(`core_package_state`: `active` | `grace` | `suspended`), agrega métricas en
`core_usage_rollups`, vigila cuotas en `core_quota_metrics` y expone una
superficie de gestión firmada en `/api/v1/control/*`.

Lo decisivo es que el **kill-switch vive aquí**, no en el panel. El middleware
`CoreEnsureActiveMiddleware` lee `core_package_state` (cacheado) en cada
petición: si el despliegue está `suspended` devuelve `402 Payment Required` en
las rutas de negocio; si está en `grace` deja pasar con cabeceras de aviso
(`X-Package-Status`, `Grace-Until`, `Message`). Quedan **exentas** del kill-switch
el health (`api/v1`) y `api/v1/control/*`, precisamente para que un despliegue
suspendido pueda ser reactivado. El secreto de control vive en
`CORE_CONTROL_SECRET`; si no está definido, las rutas `/control` ni se registran.

### 2. El panel del operador (paquete `Commander` + SPA React)

El Commander son dos cosas que conviene no confundir:

- **El paquete `Commander`** (un paquete de negocio más, activado con `API_PACKAGE=Commander`
  en el despliegue principal). Tiene su **propia base de datos** con el registro
  de la plataforma (tablas `commander_*`), su login de administrador por Sanctum, una
  API admin bajo `/api/v1` y, lo más importante, el **cliente saliente** que
  firma y emite las llamadas a los `/control` de cada despliegue remoto.
- **La SPA React** (carpeta `commander/`), construida con Vite, React, IBM Carbon y un
  store de `zustand`. Es la interfaz que el operador ve en el navegador: nueve
  secciones (Dashboard, Deployments, Usage, Clients, Subscriptions, Invoices,
  Activity, Settings y el detalle por despliegue). Consume el `/api/v1` del
  propio paquete `Commander`.

### 3. La frontera de confianza entre ambos

El Commander **no** habla con la base de datos de cada despliegue, ni mantiene una
conexión persistente, ni los despliegues hacen heartbeat hacia el panel (ambas
alternativas se descartaron en `docs/CONTROL-PLANE.md`: la primera acopla el Commander
a la topología de BD, la segunda reintroduce la dependencia del panel para
operar). La única vía de cruce es una **llamada HTTP firmada con HMAC-SHA256**
del Commander hacia el despliegue, con un secreto distinto por despliegue. El panel
**empuja** órdenes (push); el despliegue las verifica y aplica. Si el panel se
apaga, nada deja de funcionar para el cliente: solo el operador pierde
temporalmente su consola.

```
        ┌──────────────────────── MUNDO OPERADOR ────────────────────────┐
        │                                                                 │
        │   ┌─────────────────┐        ┌──────────────────────────────┐   │
        │   │  SPA React       │  HTTP  │  Paquete Commander (API_PACKAGE=Commander)│  │
        │   │  Vite+Carbon+    │ /api/v1│  ┌────────────────────────┐  │   │
        │   │  zustand store   │◄──────►│  │ CommanderControlClient/Signer│  │   │
        │   │  (commander/)          │        │  │ BD propia: commander_*       │  │   │
        │   └─────────────────┘         │  └───────────┬────────────┘  │   │
        │       navegador del operador  └──────────────┼───────────────┘   │
        └──────────────────────────────────────────────┼───────────────────┘
                                                        │  HMAC-SHA256 firmado
                       FRONTERA DE CONFIANZA  ──────────┼──────  (push, +-300s skew)
                                                        ▼
        ┌──────────────────────── MUNDO CLIENTE (xN) ───────────────────────┐
        │   ┌────────────────────────────────────────────────────────────┐  │
        │   │  Despliegue autónomo  (paquete Core + paquete de negocio)    │ │
        │   │  POST/GET /api/v1/control/{state,usage,rotate-secret}        │  │
        │   │  CoreControlAuthMiddleware  → verifica firma                 │  │
        │   │  CoreEnsureActiveMiddleware → kill-switch (402 si suspended) │  │
        │   │  BD propia: core_package_state, core_request_logs,          │   │
        │   │             core_usage_rollups, core_quota_metrics          │   │
        │   └────────────────────────────────────────────────────────────┘  │
        │   ...repetido N veces, uno por cliente/subdominio, BD aislada...   │
        └───────────────────────────────────────────────────────────────────┘
```

## El stack

### Laravel 13 modular por paquetes

La regla del monorepo es **un despliegue = un cliente = un paquete = una base de
datos**. El paquete activo se selecciona con `API_PACKAGE` en el `.env`; al
arrancar, `CoreServiceProvider` carga únicamente Core más ese paquete (providers,
configs, rutas, migraciones y seeders del resto se ignoran). El Commander no es una
excepción a esta regla: es un paquete de negocio más, que se activa con
`API_PACKAGE=Commander` en el despliegue que hace de panel.

Un detalle de configuración que conviene tener presente desde ya: las claves de
config del paquete `Commander` viven **con namespace de paquete**. La verificación TLS
del cliente saliente, por ejemplo, es `config('commander.commander.control.verify')`
(`paquete.fichero.clave`), no `config('cms.control.verify')`. Esto contrasta con
Core, que usa `core.*` directo. El fichero real es
`src/packages/Commander/config/commander.php`.

### Docker compose: app / nginx / db

El stack base de `docker-compose.yml` levanta tres servicios sobre una red
`app-network`:

| Servicio | Imagen                 | Rol                                   |
|----------|------------------------|---------------------------------------|
| `app`    | `php:8.3-fpm-alpine`   | PHP-FPM con `src/` montado (bind)      |
| `nginx`  | `nginx:alpine`         | Proxy/TLS, `HTTPS_PORT` (8443 en dev)  |
| `db`     | `mariadb:latest`       | Base de datos de las suscripciones     |

El despliegue principal arranca **agnóstico** y usa **sqlite** por defecto; las
suscripciones de cliente usan **MariaDB** (una BD y un usuario por paquete, en
snake_case). El script `scripts/subscription.sh add <cliente> <Paquete>` hace el
alta completa: crea BD y usuario MariaDB, levanta un contenedor `app-<cliente>`
con su `.env`, genera el vhost nginx para `<cliente>.<BASE_DOMAIN>`, genera el
`CONTROL_SECRET` e inyecta `CORE_CONTROL_SECRET`, añade un **alias de red** en
nginx para que el Commander resuelva el subdominio internamente, y ejecuta
`commander:register-deploy` para **auto-registrar** ese despliegue en el panel. Así el
panel controla despliegues reales, no maquetas.

En desarrollo, el transporte entre SPA y API es el **proxy de Vite** (`/api`
→ `https://localhost:8443`, `secure:false`): sin CORS y sin lidiar con el
certificado self-signed. Para producción se usa `CORS_ALLOWED_ORIGINS`.

### SPA: Vite + React + Carbon + zustand

La SPA (`commander/`) usa React 19, IBM Carbon (`@carbon/react`, `@carbon/charts`),
`react-router` y un store `zustand`. Las acciones del store son **optimistas con
persistencia en background**: la mutación local se aplica de inmediato, se lanza
la llamada a la API, y en respuesta 2xx se reconcilia con lo que devuelve el
servidor, o en fallo se hace `loadData()` (resync) y un toast. La excepción es
`rotateSecret`, que es asíncrona porque el secreto lo genera el servidor.

## El modelo de datos

El modelo de datos es la clave de bóveda de toda la arquitectura, porque define
qué sabe el Commander de cada despliegue y cómo lo mantiene sincronizado. Hay tres
capas: las tablas `core_*` (en la BD de cada despliegue), las tablas `commander_*` (en
la BD del panel, que **espejan** las `core_*`) y el tipado TypeScript de la SPA
en `commander/src/store/types.ts`, que **fija la forma del JSON** que cruza entre el
backend `Commander` y el frontend.

### El espejo commander_* ↔ core_*

Cada despliegue mide y guarda su verdad localmente en sus tablas `core_*`
(`core_package_state`, `core_request_logs`, `core_usage_rollups`,
`core_quota_metrics`, descritas en `docs/CONTROL-PLANE.md`). El Commander mantiene en
su propia BD un **espejo** de esa información, más el registro de negocio
(clientes, suscripciones, facturas) que solo existe en el panel. Las siete
tablas `commander_*` (migraciones en `src/packages/Commander/database/migrations/`,
modelos en `src/packages/Commander/app/Models/`, namespace `Packages\Commander\Models`) son:

| Tabla `commander_*`         | Modelo                | Espeja / contiene                                                |
|-----------------------|-----------------------|------------------------------------------------------------------|
| `commander_clients`         | `ClientModel`         | Datos de contacto/facturación del cliente (solo en el Commander)        |
| `commander_deployments`     | `DeploymentModel`     | Registro del despliegue + estado conocido, espejo de `core_package_state` |
| `commander_subscriptions`   | `SubscriptionModel`   | Plan, precio, billing status, `auto_suspend` (solo en el Commander)     |
| `commander_invoices`        | `InvoiceModel`        | Facturas y su `enforcement` (grace/suspended) al despliegue        |
| `commander_quota_metrics`   | `QuotaModel`          | Espejo de `core_quota_metrics` por despliegue                     |
| `commander_activity_events` | `ActivityEventModel`  | Bitácora de acciones del operador (suspend/grace/resume/paid/quota/rotate) |
| `commander_usage_snapshots` | `UsageSnapshotModel`  | Caché local horaria del uso, espejo agregado de `core_usage_rollups` |

La tabla `commander_deployments` es donde mejor se ve el espejo. Almacena las columnas
planas de estado (`status`, `reason`, `message`, `grace_until`, `changed_by`,
`changed_at`) que reproducen `core_package_state`, y guarda el **secreto de
control cifrado** (`control_secret`, cast `encrypted`) que se usa para firmar las
llamadas a ese despliegue. El modelo nunca expone el secreto en crudo: oculta
`control_secret` y las columnas planas de estado, y en su lugar **añade** un
objeto anidado `state` y un `control_secret_suffix` (los últimos 4 caracteres),
para que el JSON case exactamente con los tipos de la SPA.

### types.ts fija el JSON

El fichero `commander/src/store/types.ts` es el **contrato de forma** entre el backend
y el frontend. Su comentario de cabecera lo dice explícitamente: los tipos
espejan las tablas `core_*` del despliegue "para que el cambio de fixtures a la
API firmada `/control` sea un cambio de fuente de datos, no de modelo". Tipos
clave y su correspondencia:

- `DeploymentState` (`status`, `reason`, `message`, `grace_until`, `changed_by`,
  `changed_at`) ↔ el objeto anidado `state` que produce `DeploymentModel`.
- `Deployment` lleva `control_secret_suffix` (últimos 4) y
  `control_secret_rotated_at`, nunca el secreto completo.
- `QuotaMetric` (`metric_key`, `current_value`, `limit_value`, `on_exceed`,
  `exceeded_at`) ↔ `commander_quota_metrics` ↔ `core_quota_metrics`.
- `UsageRollup` ↔ una fila de `core_usage_rollups` (servida por
  `GET /deployments/{id}/usage`).
- `UsageSnapshot` ↔ una fila de `commander_usage_snapshots` (servida por
  `GET /usage/platform`, alimenta las gráficas de plataforma).
- `Invoice.enforcement`, `Subscription.auto_suspend`, `ActivityEvent.kind` ↔ sus
  columnas homónimas en `commander_invoices` / `commander_subscriptions` /
  `commander_activity_events`.

## El flujo de control a alto nivel

El ciclo que el panel ejerce sobre un despliegue es siempre el mismo, sea cual
sea la acción. Tomemos una suspensión por impago como ejemplo:

```
SPA (operador pulsa "Suspender")
  └─ POST /api/v1/deployments/{id}/suspend          (al paquete Commander, auth Sanctum)
       └─ CommanderDeploymentsController::suspend
            └─ CommanderControlClient (lee control_secret de la fila, descifrado)
                 └─ CommanderControlSigner::sign
                      payload = ts \n POST \n /api/v1/control/state \n sha256(body)
                      firma   = hex( hmac_sha256(payload, control_secret) )
                 └─ POST {base_url}/api/v1/control/state
                      headers: X-Control-Timestamp, X-Control-Signature
                      body:    { status:"suspended", reason:"payment_overdue", ... }
                         │
   ════════════════════ FRONTERA DE CONFIANZA ════════════════════
                         ▼
DESPLIEGUE
  └─ CoreControlAuthMiddleware  → verifica firma (hash_equals, ventana +-300s)
       └─ escribe core_package_state vía CorePackageStateService::set()
            └─ CoreEnsureActiveMiddleware ahora devuelve 402 en rutas de negocio
  └─ responde { state } 2xx
       │
       ▼
Commander reconcilia commander_deployments con la respuesta, registra commander_activity_events,
y la SPA refleja el nuevo estado (mutación optimista ya reconciliada).
```

Detalles exactos que importan para que la firma cuadre (verificados en el
código):

- El `base_url` de la fila **ya incluye** `/api/v1`; el cliente le añade
  `/control/...`. La firma usa el **path del URL completo** (vía `parse_url`)
  para que coincida con el `$request->path()` que recibe el despliegue. Por eso
  `CommanderControlSigner::canonicalPayload` normaliza a `'/' . ltrim($path, '/')`.
- El secreto rotado vive cifrado en `core_package_state.control_secret` del
  despliegue y **prevalece** sobre `CORE_CONTROL_SECRET` del entorno
  (`CoreControlAuthMiddleware::activeSecret`, con fail-open al env). Así una
  rotación invalida el secreto viejo en runtime, sin reiniciar.

Las rutas del proxy firmado en el paquete `Commander` (`src/packages/Commander/routes/api.php`)
son `POST deployments/{id}/{suspend,grace,resume,sync,rotate-secret}` y
`GET deployments/{id}/usage`, todas bajo `auth:sanctum`. La firma la construye
`Packages\Commander\Support\CommanderControlSigner` y la emite
`Packages\Commander\Services\CommanderControlClient`; los comandos de registro y sync son
`commander:register-deploy`, `commander:deregister-deploy` y `commander:sync-deploys`.

## Cómo encajan las ocho fases

La construcción es incremental y cada fase es verificable de forma
independiente. El orden no es arbitrario: cada fase apoya sobre la anterior.

1. **Registro + auth.** La BD propia del Commander (`commander_*`) y el login admin. Es la
   base; sin registro no hay a quién controlar. La forma del JSON la fija
   `commander/src/store/types.ts`.
2. **Proxy `/control` firmado.** El cliente HMAC en el Commander (`CommanderControlSigner`,
   `CommanderControlClient`) más los endpoints de operador (suspend/grace/resume/sync).
   Es el **mecanismo** de control remoto que cruza la frontera de confianza.
3. **Cablear la SPA.** Reemplazar el store de datos falsos (fixtures) por
   llamadas reales más login. Convierte el panel en algo real.
4. **Demo multi-deploy viva.** `scripts/subscription.sh` aprovisiona despliegues
   reales aislados con su control plane activo y los auto-registra en el Commander;
   suspender desde el panel hace que el despliegue devuelva `402`. Es la prueba
   en vivo de que todo el lazo funciona.
5. **Charts de uso por despliegue.** La pestaña Usage muestra métricas reales
   leídas de `core_usage_rollups` vía `GET /deployments/{id}/usage`.
6. **Charts de plataforma.** Dashboard y Usage muestran uso agregado real desde
   la caché local `commander_usage_snapshots`, sembrada y refrescada por `sync`. Esto
   resuelve el problema de la flota mixta (despliegues vivos + seeds de demo).
7. **Rotación real de secreto.** Rotar el `control_secret` de un despliegue en
   runtime invalidando el viejo, sin reinicio (las tres capas: env, fila cifrada
   en el despliegue, y registro en `commander_deployments`).
8. **Hardening y cierre.** Scheduler de auto-sync, allowlist de CORS, auditoría
   de rotación, más arreglos operativos: sqlite con WAL y `busy_timeout`,
   `CACHE_STORE=file`, y subida del rate limit a 240/min para absorber las
   peticiones paralelas de la SPA sin `database is locked`.
