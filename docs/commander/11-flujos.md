# Flujos end-to-end del Commander

## Por qué este documento

Los documentos anteriores describen las *piezas* por separado: el registro y la autenticación
(fase 1), el proxy `/control` firmado (fase 2), el cableado del SPA (fase 3), el aprovisionamiento
en vivo (fase 4) y los charts, la rotación de secreto y el hardening (fases 5–8). Este documento las
junta y cuenta las **secuencias completas**: quién llama a quién, en qué orden y con qué datos, desde
que el operador hace clic en el panel hasta que el despliegue remoto responde (y vuelta).

El objetivo es que, ante cualquier incidencia ("suspendí un despliegue y no devuelve 402", "el chart
de uso está vacío", "roté el secreto y el viejo sigue funcionando"), se pueda recorrer el camino
exacto y localizar la capa donde algo falló. Cada flujo nombra los ficheros, clases, rutas y comandos
**reales** del código; si necesitas el detalle de una pieza concreta, consulta su documento hermano
(p. ej. el contrato de firma en el documento de control plane, o el modelo de datos en `types.ts`).

Convención de actores en los diagramas:

```
Operador → la persona en el navegador
SPA      → la app React (commander/, store zustand + ActionsProvider)
api()    → el wrapper fetch del SPA (commander/src/lib/api.ts), proxied por Vite a https://localhost:8443
Commander      → el paquete Laravel Commander (CommanderDeploymentsController, CommanderControlClient, CommanderDeploymentSyncService)
Deploy   → el despliegue remoto del cliente (paquete Core: CoreControlController + middlewares)
```

Una nota transversal que aplica a casi todos los flujos: **las acciones del store del SPA son
optimistas**. La mutación local es inmediata (la UI cambia ya), la llamada API ocurre en segundo plano
vía el helper `persist()` de `commander/src/store/store.ts`, y en 2xx con cuerpo de entidad se *reconcilia*
con la respuesta del servidor (autoridad). En fallo, `persist()` muestra un toast (`actionError`) y
re-sincroniza con `loadData()`. La única excepción es `rotateSecret`, que es `async` porque el secreto
lo genera el servidor (no hay valor optimista que mostrar).

---

## (a) Login + carga inicial del SPA

Al arrancar, `App.tsx` monta `AuthGate`, que llama a `useAuth().bootstrap()`
(`commander/src/store/auth.ts`). Si hay un token en `localStorage` (`commander_token`), lo valida contra
`/auth/me`; si no, va a estado `anon` y pinta `Login`. Tras un login correcto, el estado pasa a
`authed`, se monta `AuthedApp`, y su `useEffect` dispara `loadData()` **una sola vez**. `loadData`
lanza las seis lecturas del registro **en paralelo** con `Promise.all`, y hasta que `loaded` es `true`
el router muestra `FullScreenLoading` (evita que parpadeen los fixtures antes de la respuesta real).

```
Operador        SPA (auth.ts / App.tsx)     api() (lib/api.ts)        Commander (Laravel)
   |                  |                            |                        |
   |  abre la app     |                            |                        |
   |----------------->| bootstrap()                |                        |
   |                  |  getToken() == null  --> status='anon' -> <Login/>  |
   |                  |                            |                        |
   |  email+password  |                            |                        |
   |----------------->| login(email,pwd)           |                        |
   |                  |---- POST /auth/login ------>| (throttle:login)       |
   |                  |                            |--- CommanderAuthController --->|
   |                  |                            |   (extiende CoreAuthController, Sanctum)
   |                  |<-- 200 { token, user } -----|<-----------------------|
   |                  | setToken(token); status='authed'                    |
   |                  |                            |                        |
   |              [AuthedApp useEffect] loadData() |                        |
   |                  |== Promise.all ============>|                        |
   |                  |  GET /clients              |--- index -------------->|
   |                  |  GET /deployments          |--- index -------------->|
   |                  |  GET /subscriptions        |--- index -------------->|
   |                  |  GET /invoices             |--- index -------------->|
   |                  |  GET /quotas               |--- index -------------->|
   |                  |  GET /activity             |--- index -------------->|
   |                  |<== 6x 200 (Bearer token) ==|<-----------------------|
   |                  | set({...}); loaded=true                              |
   |<-- panel listo --|                            |                        |
```

Detalles que importan:

- El token viaja como `Authorization: Bearer <token>` en cada petición; lo añade `api()` leyendo
  `getToken()`. Las rutas autenticadas del Commander están bajo `auth:sanctum`
  (ver `src/packages/Commander/routes/api.php`).
- Un **401** en cualquier llamada activa el `setUnauthorizedHandler` registrado en `auth.ts`: borra el
  token y vuelve a `anon` (sesión caída → re-login).
- `loadData` recalcula los dos KPIs reales del dashboard a partir de los datos vivos
  (`requests_24h` sumando despliegues; `mrr` sumando suscripciones no `overdue`); el resto de
  charts/deltas siguen sintéticos hasta que entran los snapshots reales (ver flujo (d)).

---

## (b) Suspender un despliegue (clic → 402 en el deploy)

Es el flujo nuclear del control plane. El operador confirma en el `StateChangeModal`; el
`ActionsProvider` llama a `store.suspend(id, {reason, message})`, que **muta local ya** y dispara
`POST /deployments/{id}/suspend`. En el Commander, `CommanderDeploymentsController::suspend` exige el permiso
`control deployments`, valida `reason ∈ {payment_overdue, abuse, manual}`, y delega en
`CommanderControlClient::setState(...,'suspended',...)`. El cliente **firma HMAC** y hace
`POST {base_url}/control/state`. En el deploy, `CoreControlAuthMiddleware` verifica la firma y
`CoreControlController::updateState` persiste el estado. A partir de ahí, cualquier petición a la API
del cliente pasa por `CoreEnsureActiveMiddleware`, que devuelve **402 Payment Required**.

```
Operador   SPA(store.suspend)   api()   Commander(CommanderDeploymentsController)   CommanderControlClient   Deploy(Core)
   |             |                |              |                            |                  |
 confirma modal  |                |              |                            |                  |
   |------------>| set(state=suspended)  <-- mutación OPTIMISTA, UI ya cambia |                  |
   |             | persist(                                                   |                  |
   |             |   POST /deployments/{id}/suspend {reason,message} )        |                  |
   |             |---------------->|              |                            |                  |
   |             |                 |-- suspend()->| requirePermission('control deployments')     |
   |             |                 |              | validate(reason,message)   |                  |
   |             |                 |              |-- setState('suspended') -->|                  |
   |             |                 |              |        firma:              |                  |
   |             |                 |              |   path = parse_url(base_url + '/control/state')
   |             |                 |              |   payload = ts\nPOST\n/path\nsha256(body)      |
   |             |                 |              |   X-Control-Timestamp, X-Control-Signature    |
   |             |                 |              |-- POST {base_url}/control/state {status,reason,message,grace_until=null} -->|
   |             |                 |              |                            |  CoreControlAuthMiddleware:
   |             |                 |              |                            |   |reloj ±300s| hash_equals(firma)
   |             |                 |              |                            |  CoreControlController::updateState
   |             |                 |              |                            |   stateService->set(Suspended,...)
   |             |                 |              |<-- 200 { state:{effective_status:'suspended',...} } --|
   |             |                 |              | sync->applyState($dep, state)  (espejo local)  |
   |             |                 |              | recordActivity('suspend')  (commander_activity_events)
   |             |                 |<-- 200 dep --| return $deployment->fresh()|                  |
   |             | replaceDep(dep) <-- reconcilia con la respuesta del servidor (autoridad)       |
   |             |                 |              |                            |                  |
   |  ── desde ahora, tráfico del cliente al deploy ──                        |                  |
 cliente API --> Deploy: GET /api/v1/<algo>  → CoreEnsureActiveMiddleware → 402 Payment Required  |
                                              (header X-Package-Status: suspended)                |
```

Detalles que importan:

- **Permiso dedicado**: las acciones de estado usan `control deployments`
  (`CommanderDeploymentsController::CONTROL_PERMISSION`), no el `update deployments` de CRUD.
- **Firma sobre el path, no la query** (`CommanderControlClient::send`): se firma
  `parse_url($url, PHP_URL_PATH)` para que cuadre con `'/'.ltrim($request->path())` del receptor. El
  cuerpo se serializa **una sola vez** y se envían esos mismos bytes (para que `sha256(body)` coincida).
- **Exenciones del kill-switch**: `CoreEnsureActiveMiddleware` salta las `core.state.exempt_paths`
  (health `/api/v1` y `/api/v1/control/*`), de modo que un deploy suspendido **sigue siendo
  controlable** y reporta salud.
- **Fallo de la llamada de control**: si el deploy está caído o responde no-2xx, `CommanderControlClient`
  lanza `CommanderControlException`; el controlador la traduce a **502** con `detail`/`remote_status`. En el
  SPA, `persist()` captura el error, muestra el toast y llama a `loadData()` para revertir la mutación
  optimista a la verdad del servidor.

---

## (c) Gracia y reactivación

Misma mecánica que (b), cambiando el `status` enviado al deploy y, en el caso de gracia, calculando
un `grace_until`. El modal del SPA convierte `graceHours` (o el `default_grace_hours` de settings) en
un ISO8601, y `store.grace` lo envía. En el Commander, `CommanderDeploymentsController::grace` admite `grace_until`
(ISO) **o** `grace_hours` (1–720; por defecto `config('commander.commander.operator.default_grace_hours', 72)`) y
lo manda como `grace_until` ISO al deploy. En gracia el deploy **no corta**: deja pasar el tráfico
pero añade cabeceras de aviso (`X-Package-Status: grace`, `X-Package-Grace-Until`, `X-Package-Message`).

```
Operador   SPA              Commander(grace)                         Deploy(Core)
   |  grace |                |                                  |
   |------->| set(state=grace, grace_until=ISO)  <-- optimista  |
   |        | POST /deployments/{id}/grace {reason,message,grace_until} ->
   |        |                |-- requirePermission('control deployments')
   |        |                |   grace_until = parse(ISO) | now()->addHours(grace_hours|72)
   |        |                |-- setState('grace', reason, msg, graceUntil) -->|
   |        |                |                                  | updateState → stateService->set(Grace,...)
   |        |                |<-- 200 {state:{effective_status:'grace', grace_until}} --|
   |        |                | applyState + recordActivity('grace')               |
   |        |<-- 200 dep ----| return fresh()                   |                  |
   |        | replaceDep(dep)|                                  |                  |
   |  ── tráfico del cliente ──> Deploy: pasa, con headers X-Package-Status: grace |
```

Reactivar (`resume`) es el caso simétrico y el más simple: `store.resume(id)` (optimista, sin modal)
→ `POST /deployments/{id}/resume` → `setState('active', null, null, null)` limpia
`reason`/`message`/`grace_until` en el deploy. La máquina de estados del deploy es
`active ↔ grace ↔ suspended`; cualquier transición pasa siempre por el mismo `updateState`.

Hay un puente con facturación: en `ActionsProvider`, `markPaid` de una factura cuyo despliegue estaba
`suspended` por `payment_overdue` ofrece un toast con acción "Resume deployment" que invoca
`resume`. Y `OverdueEnforcementModal` permite, ante una factura vencida, elegir entre dejar correr,
mover a gracia (`grace` con `reason: 'payment_overdue'`) o suspender, marcando además el
`enforcement` de la factura.

---

## (d) Sync (estado + quotas + usage → espejo + snapshots)

El sync **lee** del deploy hacia el espejo local (al revés que suspend/grace/resume, que *escriben*).
`POST /deployments/{id}/sync` entra por `CommanderDeploymentsController::sync`, que delega todo en
`CommanderDeploymentSyncService::sync`. Este hace **dos** llamadas de control: `GET /control/state` (estado
+ quotas) y `GET /control/usage` (rollups horarios de las últimas ~48h). Luego vuelca: el estado a las
columnas planas del modelo (`applyState`), las quotas a `commander_quota_metrics` (`applyQuotas`), y el uso
horario al caché `commander_usage_snapshots` (`applySnapshots`) que alimenta los charts de plataforma. Al
final recalcula `requests_24h`, `quota_health` y `last_seen`.

```
SPA           Commander(sync)            CommanderDeploymentSyncService            Deploy(Core)
 | POST .../sync                    |                                  |
 |------------>| requirePermission('control deployments')              |
 |             |-- sync->sync($dep)->|                                 |
 |             |                     |-- GET /control/state ---------->| showState():
 |             |                     |                                 |   { state, package, quotas[] }
 |             |                     |<-- 200 estado+quotas -----------|
 |             |                     |-- GET /control/usage?period=hour&from=now-2d&per_page=1000 -->|
 |             |                     |                                 |   usage(): paginate(core_usage_rollups)
 |             |                     |<-- 200 { data:[{period_start,request_count,error_count}] } --|
 |             |                     | applyState(state)   -> columnas del deployment (flip → changed_at)
 |             |                     | applyQuotas(quotas) -> commander_quota_metrics (updateOrCreate id=dep:metric)
 |             |                     | applySnapshots(usage)-> commander_usage_snapshots (updateOrCreate dep:hour:ts)
 |             |                     | requests_24h = sumRequests(usage, 24h)
 |             |                     | quota_health = worstQuotaHealth($dep)
 |             |                     | last_seen = now(); save()
 |             |<-- fresh() ---------|                                 |
 |<-- 200 dep (con quotas) ---------|                                 |
```

Detalles que importan:

- **`applyState` solo sella `changed_at` en un flip real** (cuando el `effective_status` cambia), para
  no falsear la antigüedad del estado en syncs repetidos. Este `changed_at` es el que usa el SPA en
  `replaceDep` para descartar reconciliaciones fuera de orden (dos acciones rápidas sobre el mismo
  despliegue): una respuesta con `changed_at` más viejo que el estado actual se ignora.
- **El espejo es la fuente de los charts**: los snapshots horarios (`applySnapshots`) son el caché que
  el endpoint `GET /usage/platform` (`CommanderUsageController::platform`) agrega para el Dashboard/Usage. Por
  eso el sync resuelve la "flota mixta" (despliegues vivos + seeds): tras sincronizar, los charts
  reflejan tráfico real.
- **`core:usage:rollup` solo cierra horas/días completos** en el deploy; la hora en curso no aparece en
  los rollups, así que el sync siempre va una franja por detrás del tiempo real (es esperado).
- La pestaña Usage de un despliegue concreto no usa el espejo: llama a `GET /deployments/{id}/usage`
  (`CommanderDeploymentsController::usage`, permiso `show deployments`), que **proxya en vivo** el
  `/control/usage` del deploy.

---

## (e) Rotar el secreto de control (las 3 capas)

Rotar el secreto es el único flujo **no optimista** del store, porque el valor nuevo lo genera el
servidor. El SPA pide confirmación tipeada (`TypedConfirmModal`, hay que escribir el nombre del
despliegue) y llama a `store.rotateSecret(id)` (`async`). El Commander genera un secreto nuevo
(`sk_ctl_live_<hex>`), lo **entrega al deploy firmando con el secreto ACTUAL** (para que el deploy lo
valide antes de cambiarlo), y solo entonces lo adopta localmente. Son tres capas de secreto que
conviene tener claras:

1. **Capa de transporte (firma de la entrega)**: `CommanderControlClient::send` firma la petición
   `POST /control/rotate-secret` con `$deployment->control_secret`, es decir el secreto **viejo** (aún
   vigente). Así `CoreControlAuthMiddleware` la acepta.
2. **Capa de persistencia en el deploy**: `CoreControlController::rotateSecret` guarda el nuevo secreto
   en `core_package_state.control_secret` (cifrado). Desde ese instante,
   `CoreControlAuthMiddleware::activeSecret()` **prevalece** sobre el env `CORE_CONTROL_SECRET`: el
   secreto viejo deja de verificar **en runtime, sin reinicio**.
3. **Capa de adopción en el Commander**: de vuelta en `CommanderDeploymentsController::rotateSecret`, tras el 2xx
   del deploy, se hace `$deployment->control_secret = $new` y se guarda
   `control_secret_rotated_at`. Si esta escritura fallara *después* del swap remoto, queda una ventana
   donde el deploy ya está en `$new` y el Commander en el viejo (se re-aprovisiona).

```
Operador   SPA(store.rotateSecret async)   Commander(rotateSecret)                Deploy(Core)
   |  escribe el nombre + confirma           |                              |
   |---------> POST /deployments/{id}/rotate-secret ----------------------->|
   |           |                              | requirePermission('control deployments')
   |           |                              | $new = 'sk_ctl_live_'.bin2hex(random_bytes(12))
   |           |                              |-- control->rotateSecret($dep, $new) -->
   |           |                              |   FIRMA con el secreto VIEJO (capa 1)
   |           |                              |-- POST /control/rotate-secret {new_secret:$new} -->|
   |           |                              |                              | AuthMiddleware OK (firma vieja)
   |           |                              |                              | rotateSecret(): core_package_state
   |           |                              |                              |   .control_secret = $new (cifrado) (capa 2)
   |           |                              |<-- 200 { rotated:true, suffix } --|  (viejo ya NO verifica)
   |           |                              | $dep->control_secret = $new (capa 3)
   |           |                              | $dep->control_secret_rotated_at = now()
   |           |                              | recordActivity('rotate', '…'.suffix)
   |           |<-- 200 dep -------------------| return fresh()              |
   |  toast "ends …{suffix}" <-- replaceDep   |                              |
```

El SPA nunca ve el secreto completo: solo `control_secret_suffix` (los últimos 4 caracteres), que
muestra en el toast de confirmación. El próximo `setState`/`sync` ya firma con el secreto nuevo.

---

## (f) Aprovisionar un despliegue real con `scripts/subscription.sh`

Aquí no hay SPA: es el script que crea un despliegue **aislado y autónomo** (BD + contenedor + vhost
propios) y lo **auto-registra** en el Commander para que el panel pueda controlarlo. El comando es
`bash scripts/subscription.sh add <cliente> <Package>`. Resumen de lo que hace `cmd_add`, en orden:

```
Operador (CLI)        subscription.sh (cmd_add)                  Docker / MariaDB / nginx / artisan
   |  add <c> <Pkg>        |                                     |
   |--------------------->| load_env (BASE_DOMAIN, HTTPS_PORT)   |
   |                      | validate_name(c) (regex, reservados, no colisión con APP_DOMAIN)
   |                      | existe src/packages/<Pkg> ? Pkg != Core ? <c>.env no existe ?
   |                      | una suscripción por paquete (la BD se llama como el paquete)
   |                      | require_cert (wildcard.<BASE_DOMAIN>.pem)
   |                      | db = snake(Pkg);  control_secret = "sk_ctl_live_$(openssl rand -hex 12)"
   |                      |-- escribe docker/clients/<c>.env (CLIENT,PACKAGE,DB_*,CONTROL_SECRET,CREATED_AT)
   |                      |-- regen_compose -> docker-compose.clients.yml:
   |                      |     service app-<c> con CORE_CONTROL_SECRET=<control_secret>, DB_* propios
   |                      |     nginx: ALIAS de red <c>.<BASE_DOMAIN> (para que el Commander lo resuelva interno)
   |                      |-- docker compose up -d db ; create_database(db,db,db)
   |                      |-- write_vhost(c) -> docker/nginx/conf.d/client-<c>.conf.template
   |                      |-- docker compose up -d app-<c> ; up -d nginx (re-render vhosts + alias)
   |                      |-- wait_for_app(c)  (php artisan migrate:status hasta responder)
   |                      |-- docker compose exec app-<c> php artisan core:seed --force
   |                      |                                     |
   |                      |== AUTO-REGISTRO EN EL Commander ==        |
   |                      |-- docker compose exec app php artisan commander:register-deploy <c> \
   |                      |       --package=<Pkg> \
   |                      |       --base-url=https://<c>.<BASE_DOMAIN>/api/v1 \
   |                      |       --secret=<control_secret> --client=<c>
   |                      |        -> ClientModel + DeploymentModel updateOrCreate (control_secret cifrado)
   |                      |-- hosts_add(<c>.<BASE_DOMAIN>)      |
   |<-- "registrado en el Commander (controlable desde el panel)" ---|
```

Detalles que importan:

- **`base_url` lleva ya el `/api/v1`** (`https://<c>.<BASE_DOMAIN>/api/v1`). Es exactamente lo que el
  `CommanderControlClient` espera: él solo añade `/control/...`. Esto cierra el círculo con los flujos (b)–(e).
- **El secreto se comparte por dos caminos coherentes**: se inyecta como `CORE_CONTROL_SECRET` en el
  contenedor del deploy (vía `app-<c>` en el compose) **y** se pasa con `--secret` a
  `commander:register-deploy`, que lo guarda **cifrado** en `commander_deployments.control_secret`. Mismo secreto en
  ambos lados ⇒ las firmas cuadran desde el primer `sync`.
- **El alias de red de nginx** es lo que permite que el contenedor `app` (que corre el Commander con
  `API_PACKAGE=Commander`) resuelva `<c>.<BASE_DOMAIN>` por la red interna de Docker y llegue al deploy sin
  salir a Internet.
- `commander:register-deploy` es **idempotente** (`updateOrCreate`): re-ejecutar `add` actualiza la fila.
  `remove` invoca `commander:deregister-deploy <c>` antes de tumbar el contenedor.
- Si el servicio `app` no corre con `API_PACKAGE=Commander`, el registro falla con un AVISO no fatal (el
  deploy queda creado pero no aparece en el panel).

Tras `add`, el despliegue está vivo y registrado: desde el panel ya se puede ejecutar el loop completo
de los flujos (b)–(e). La prueba viva es suspender desde el SPA y comprobar que el deploy responde
**402** en sus rutas no exentas.

---

## (g) Auto-sync programado

Para que el espejo y los charts no dependan de syncs manuales, `CommanderServiceProvider::boot` registra en
el scheduler de Laravel el comando `commander:sync-deploys`, **cada hora** y con `withoutOverlapping()`
(solo en consola, dentro de `app->booted`). El cron del sistema invoca `php artisan schedule:run` cada
minuto y este dispara el comando cuando toca.

```
cron (cada minuto) → php artisan schedule:run
        |
        └── (a la hora en punto) commander:sync-deploys   [withoutOverlapping]
                |
                | DeploymentModel::where(base_url no vacío)->get()   (opcional --id=<uno>)
                |
                └── por cada deployment:
                      CommanderDeploymentSyncService::sync($dep)   ← mismo servicio que el flujo (d)
                         GET /control/state  + GET /control/usage  → espejo + snapshots
                      éxito → ok++ ;  CommanderControlException → skip (warn, NO fatal)
                |
                └── "Synced {ok}, skipped {skipped} of {total}."
```

Detalles que importan:

- **Reutiliza exactamente el flujo (d)**: el comando no tiene lógica propia de control; llama al mismo
  `CommanderDeploymentSyncService::sync`. Por eso lo que se actualiza (estado, quotas, snapshots,
  `requests_24h`, `quota_health`, `last_seen`) es idéntico al sync manual.
- **Tolerante a fallos**: un deploy caído lanza `CommanderControlException`, que el comando captura como
  `skip` con un `warn`; no aborta el resto de la flota. Encaja con la premisa del proyecto: los
  despliegues son autónomos y el panel solo refleja su estado cuando puede alcanzarlos.
- Para forzar un sync de un único despliegue desde la CLI:
  `php artisan commander:sync-deploys --id=<deployment-id>`.
