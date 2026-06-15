# Fase 2 — Proxy `/control` firmado

## Contexto (por qué)

El CMS de SnakeCloud es el panel del operador, pero por sí solo no es más que un registro de
despliegues: filas en su propia base de datos que describen contenedores remotos. Lo que convierte ese
registro en un verdadero plano de control es la capacidad de **actuar** sobre cada despliegue desde el
panel: suspenderlo cuando el cliente deja de pagar, ponerlo en gracia mientras regulariza, reactivarlo
cuando salda la deuda y sincronizar su estado real. Cada despliegue es autónomo (si el CMS cae, los
clientes siguen siendo servidos), así que la única vía legítima para mandarle órdenes es su API
`/control`, protegida por una firma HMAC que el despliegue verifica en su propio middleware.

Esta fase construye ese mecanismo de control remoto del lado del CMS. El reto técnico central no es
hacer peticiones HTTP, sino **firmarlas de forma byte-idéntica** a lo que el receptor espera. Si el CMS
calcula el payload canónico de una manera y el despliegue lo recalcula de otra (por ejemplo, hashea un
cuerpo distinto del que se envía, o firma un path con la query incluida), la firma no cuadra y toda
orden devuelve 401. Por eso la firma es código puro, aislado y cruzado con el middleware real en los
tests.

Para entender el contrato HMAC completo y la máquina de estados del receptor, ver
`03-fase-1-registro-auth.md` (registro y auth) y la documentación del paquete `Core`; aquí se documenta
exclusivamente el lado emisor (el CMS) y cómo se cablea a los endpoints del operador.

## Qué se construyó

La fase entrega tres piezas que se apilan, de lo más puro a lo más expuesto:

1. **`CmsControlSigner`** — la firma pura, sin I/O. Calcula el payload canónico y el HMAC-SHA256 hex.
   Es la traducción exacta del cálculo que hace `CoreControlAuthMiddleware` en el despliegue.

2. **`CmsControlClient`** — el cliente HTTP. Construye la URL hacia el `base_url` del despliegue, firma
   el **path** de esa URL, envía **los mismos bytes** que hasheó, adjunta las cabeceras de control y
   traduce cualquier fallo (conexión o respuesta no-2xx) en una `CmsControlException`.

3. **Acciones de operador en `CmsDeploymentsController`** — `suspend`, `grace`, `resume`, `sync`
   (más `usage` y `rotateSecret`), todas bajo el permiso `control deployments`, que llaman al
   despliegue a través del cliente y **espejan** el estado devuelto en el mirror local. Si la llamada
   al despliegue falla, la `CmsControlException` se convierte en **HTTP 502** y el estado local **no se
   toca**.

Las rutas quedan registradas en `src/packages/Cms/routes/api.php`, dentro del grupo
`auth:sanctum`:

```php
Route::post('deployments/{id}/suspend', [CmsDeploymentsController::class, 'suspend']);
Route::post('deployments/{id}/grace', [CmsDeploymentsController::class, 'grace']);
Route::post('deployments/{id}/resume', [CmsDeploymentsController::class, 'resume']);
Route::post('deployments/{id}/sync', [CmsDeploymentsController::class, 'sync']);
Route::post('deployments/{id}/rotate-secret', [CmsDeploymentsController::class, 'rotateSecret']);
Route::get('deployments/{id}/usage', [CmsDeploymentsController::class, 'usage']);
```

(La rotación de secreto y el proxy de usage se introducen aquí porque comparten exactamente la misma
maquinaria de firma; su tratamiento a fondo vive en fases posteriores.)

## Decisiones clave (y por qué)

**La firma debe ser idéntica a la que verifica el receptor, no "compatible".** El payload canónico es
una cadena de cuatro líneas unidas por `\n`:

```
timestamp \n METHOD \n /path \n sha256(body)
```

El emisor (`CmsControlSigner`) y el receptor (`CoreControlAuthMiddleware`) tienen que construir esa
cadena carácter a carácter de la misma forma, o `hash_equals` falla. Para garantizarlo, la firma se
extrajo a una clase **pura, sin I/O**, de modo que pueda probarse en aislamiento y, sobre todo,
cruzarse contra el middleware real (ver `## Verificación`). Esta es la decisión que sostiene toda la
fase: si la firma no es byte-idéntica, nada funciona.

**Se firma solo el path, nunca la query.** El receptor verifica usando `'/' . ltrim($request->path())`,
que en Laravel **no incluye la query string**. Por eso el cliente extrae el path con
`parse_url($url, PHP_URL_PATH)` y firma solo eso; los parámetros de query (los que usa `getUsage`)
viajan en la URL pero quedan fuera de la firma. Si se firmara la query, la firma no cuadraría con la del
receptor.

**Se envían los mismos bytes que se hashean.** Un cuerpo no nulo se serializa a JSON **una sola vez**
(`json_encode($body)`), se hashea con `sha256` y se envía con `withBody($payloadBody, 'application/json')`.
Si en su lugar se pasara el array a `Http::post()` y se dejara que el cliente HTTP re-serializara,
podría emitir bytes distintos (orden de claves, escapes, espaciado) de los hasheados, y el
`sha256(body)` del receptor no coincidiría. Serializar una vez y reutilizar esos bytes exactos elimina
esa clase entera de bugs.

**Un fallo de control es 502, no 500, y nunca desincroniza el mirror.** El despliegue es la fuente de
verdad de su propio estado. Una orden que el despliegue rechaza o no recibe (timeout, firma mala, no-2xx)
**no debe** dejar el registro del CMS afirmando algo que el despliegue no aplicó. Por eso
`CmsControlException` se mapea a **HTTP 502** (Bad Gateway: el upstream falló) y el controlador aborta
**antes** de mutar nada local. El mirror solo se actualiza tras un 2xx, espejando el estado que el
propio despliegue devuelve.

**Las acciones de operador van bajo un permiso dedicado, no bajo el CRUD genérico.** Suspender un
despliegue no es "editar un registro": es una acción operativa con consecuencias para un cliente real.
Por eso `suspend`, `grace`, `resume`, `sync` y `rotateSecret` exigen el permiso
`control deployments` (constante `CmsDeploymentsController::CONTROL_PERMISSION`), separado del
`update deployments` del CRUD. El proxy de `usage`, en cambio, es de solo lectura y reutiliza
`show deployments`.

**El estado se espeja desde la respuesta del despliegue, no desde lo que pedimos.** Tras un 2xx, el
controlador llama a `CmsDeploymentSyncService::applyState($deployment, $result['state'] ?? [])`. Es
decir, escribimos en el mirror **el estado que el despliegue confirma haber aplicado**
(`effective_status`, `reason`, `message`, `grace_until`, `changed_by`), no los parámetros que enviamos.
Así el panel refleja la realidad del despliegue, no nuestra intención.

## Cómo funciona

### La firma pura (`CmsControlSigner`)

Ruta: `src/packages/Cms/app/Support/CmsControlSigner.php`. Dos métodos estáticos:

```php
public static function canonicalPayload(int $timestamp, string $method, string $path, string $body): string
{
    return implode("\n", [
        (string) $timestamp,
        strtoupper($method),
        '/' . ltrim($path, '/'),
        hash('sha256', $body),
    ]);
}

public static function sign(string $secret, int $timestamp, string $method, string $path, string $body): string
{
    return hash_hmac('sha256', self::canonicalPayload($timestamp, $method, $path, $body), $secret);
}
```

Detalles que importan: el método se normaliza con `strtoupper`, el path siempre lleva una barra inicial
(`'/' . ltrim($path, '/')`), el cuerpo se hashea con `sha256` (cadena vacía `''` en los GET) y el
resultado final es **HMAC-SHA256 en hex minúscula**. Nada de esto hace I/O, lo que permite probarlo
contra el middleware real.

### El cliente HTTP (`CmsControlClient`)

Ruta: `src/packages/Cms/app/Services/CmsControlClient.php`. Expone una API por endpoint del despliegue:

- `getState($deployment)` → `GET /control/state` → `{ state, package, quotas[] }`.
- `setState($deployment, $status, $reason?, $message?, $graceUntil?)` → `POST /control/state` con
  `{status, reason?, message?, grace_until?}` → `{ state }`. El `grace_until` se serializa con
  `toIso8601String()`.
- `getUsage($deployment, $query = [])` → `GET /control/usage?period=&from=&to=&per_page=` (la query
  **no** se firma).
- `rotateSecret($deployment, $newSecret)` → `POST /control/rotate-secret` con `{new_secret}` (tratado en
  la fase de rotación).

Todos pasan por el método privado `send()`, que es el corazón del cliente. Su secuencia exacta:

1. Lee el secreto de la fila: `$secret = (string) $deployment->control_secret`. Si está vacío, lanza
   `CmsControlException` (no se puede firmar sin secreto).
2. Compone la URL: `rtrim($deployment->base_url, '/') . $endpoint`. El `base_url` **ya incluye**
   `/api/v1`, así que `$endpoint` (por ejemplo `/control/state`) se concatena directamente.
3. Extrae el path a firmar: `$path = parse_url($url, PHP_URL_PATH)` — **sin** query, para que cuadre con
   el `'/'.ltrim($request->path())` del receptor.
4. Serializa el cuerpo **una vez**: `$payloadBody = $body === null ? '' : json_encode($body)`.
5. Firma: `CmsControlSigner::sign($secret, $timestamp, $method, $path, $payloadBody)` con
   `$timestamp = time()`.
6. Lee la config del paquete: `config('cms.cms.control', [])`. Ojo con el doble namespace: la clave real
   es **`cms.cms.control`** (`paquete.fichero.clave`), no `cms.control`. De ahí salen `timeout` (por
   defecto 5 s), `connect_timeout` (3 s) y `verify` (si es `false`, se llama a `withoutVerifying()` para
   el cert self-signed en dev).
7. Adjunta cabeceras y, si hay cuerpo, lo envía con **los mismos bytes** hasheados:
   `withBody($payloadBody, 'application/json')`. La query, si la hay, viaja como
   `['query' => $query]`.

Las cabeceras emitidas son exactamente:

```
X-Control-Timestamp: <unix>
X-Control-Signature: <hex hmac-sha256>
Accept: application/json
```

**Manejo de errores en `send()`:** una `ConnectionException` (despliegue inalcanzable, timeout) se
envuelve en `CmsControlException` con el mensaje del fallo. Una respuesta `$response->failed()` (no-2xx)
también lanza `CmsControlException`, llevando el `remoteStatus` (`$response->status()`) y los primeros
500 caracteres del cuerpo remoto (`mb_substr(..., 0, 500)`) para diagnóstico. En 2xx, devuelve
`$response->json()` como array.

### Las acciones de operador (`CmsDeploymentsController`)

Ruta: `src/packages/Cms/app/Http/Controllers/CmsDeploymentsController.php`. Todas las acciones de
escritura siguen el mismo patrón de cuatro pasos. Tomando `suspend` como ejemplo:

1. **Permiso:** `if ($response = $this->requirePermission(self::CONTROL_PERMISSION))` → si falta el
   permiso `control deployments`, devuelve 403 sin tocar nada.
2. **Modelo:** `findModel($id)`; si no existe, 404.
3. **Validación + llamada al despliegue:** valida (`reason` obligatorio en
   `payment_overdue|abuse|manual`, `message` opcional ≤280) y llama
   `$this->control->setState($deployment, 'suspended', $reason, $message, null)` dentro de un
   `try/catch (CmsControlException)`. Si salta, `controlError($e)` → **502**.
4. **Espejo + auditoría:** en éxito, `$this->sync->applyState($deployment, $result['state'] ?? [])`
   escribe el estado confirmado en el mirror, y `recordActivity('suspend', ...)` registra el evento.
   Devuelve `$deployment->fresh()`.

Las variantes:

- **`grace`** valida además `grace_until` (fecha ISO) o `grace_hours` (1–720). Calcula el
  `CarbonImmutable`: si viene `grace_until`, lo parsea; si no, `now()->addHours(grace_hours ?? config('cms.cms.operator.default_grace_hours', 72))`. Llama a `setState(..., 'grace', ..., $graceUntil)`.
- **`resume`** llama a `setState($deployment, 'active', null, null, null)` — limpia reason/message/grace.
- **`sync`** delega entero en `CmsDeploymentSyncService::sync($deployment)`, que hace `getState` +
  `getUsage`, espeja estado, cuotas y snapshots de uso, recalcula `requests_24h` y `quota_health` y
  estampa `last_seen`. Devuelve `$fresh->load('quotas')`.
- **`usage`** (solo lectura, permiso `show deployments`) valida `period|from|to|per_page`, arma la query
  con defaults (`period='hour'`, `per_page=1000`) y proxea `getUsage` tal cual al SPA.

El método `controlError()` produce la respuesta de fallo uniforme:

```php
return response()->json([
    'error' => 'Control plane call failed.',
    'detail' => $e->getMessage(),
    'remote_status' => $e->remoteStatus,
], 502);
```

Y `recordActivity()` inserta una fila en `cms_activity_events` con `kind`, `deployment_id`, `reason`,
`message`, el actor (`auth()->user()?->name ?? 'operator'`) y la marca de tiempo.

### Flujo completo de una suspensión

```
SPA  --POST /api/v1/deployments/{id}/suspend-->  CmsDeploymentsController::suspend
                                                   │ requirePermission('control deployments')
                                                   │ findModel + validate
                                                   ▼
                                                 CmsControlClient::setState
                                                   │ firma path con secreto de la fila
                                                   ▼
   despliegue  <--POST /api/v1/control/state (X-Control-*)--  (mismos bytes hasheados)
   CoreControlAuthMiddleware verifica la firma → aplica estado → devuelve { state }
                                                   ▼
                                  applyState(mirror) + recordActivity → 200 deployment.fresh()
   (si el despliegue falla en cualquier punto → CmsControlException → 502, mirror intacto)
```

## Verificación

La cobertura vive en `src/tests/Feature/CmsControlTest.php`. Los tests son la prueba de que la firma es
correcta y de que el manejo de fallos respeta la invariante "el despliegue es la fuente de verdad".

**El "gold test" — cross-validación de la firma contra el middleware real.**
`test_signer_is_accepted_by_the_real_core_middleware` firma una petición con `CmsControlSigner::sign(...)`
y la entrega al **middleware real** `CoreControlAuthMiddleware` (instanciado de verdad, no un mock), y
exige que devuelva 200. Lo prueba tanto para un `POST` con cuerpo JSON como para un `GET` con cuerpo
vacío. Esto demuestra, sin un despliegue vivo, que el emisor y el receptor calculan la firma de forma
byte-idéntica.

**Rechazos del receptor.** `test_middleware_rejects_tampered_body_and_stale_timestamp` confirma que el
middleware devuelve 401 si el cuerpo cambia tras firmar (el `sha256(body)` ya no cuadra) o si el
timestamp queda fuera de la ventana de reloj (±300 s).

**Espejo local correcto.** `test_suspend_pushes_signed_state_and_mirrors_locally` usa `Http::fake` para
responder al `*/control/state`, lanza la suspensión y verifica que: la fila `cms_deployments` queda en
`status=suspended, reason=payment_overdue, changed_by=cms`; se crea el `cms_activity_events` con
`kind=suspend`; y, vía `Http::assertSent`, que la petición saliente llevó las cabeceras correctas y una
firma **byte-idéntica** recomputada con `CmsControlSigner::sign` sobre el path real y el cuerpo enviado.

**Permisos.** `test_control_actions_require_authentication_and_permission` comprueba 401 sin autenticar
(por `auth:sanctum`) y 403 para un usuario autenticado que carece de `control deployments`.

**Fallo remoto = 502 sin mutar nada.** `test_remote_failure_returns_502_and_does_not_mutate_local_state`
falsea un 500 del despliegue, lanza la suspensión y exige 502, que el `status` local siga igual que
antes y que **no** se haya escrito ningún `cms_activity_events`.

**Sync.** `test_sync_pulls_state_quotas_and_usage_into_the_mirror` falsea state+quotas+usage y verifica
que se upsertan las filas `cms_quota_metrics` y que `requests_24h` y `quota_health` se recalculan
correctamente (p.ej. `quota_health = 0.5` para la peor ratio `250000/500000`).

**Preferencia del secreto rotado y rotación** quedan cubiertas por
`test_middleware_prefers_a_rotated_db_secret_over_env`, `test_rotate_secret_delivers_new_secret_signed_with_the_old_and_adopts_it`
y `test_rotate_secret_failure_leaves_the_secret_unchanged`, que pertenecen al ámbito de la fase de
rotación pero validan ya en esta fase que la maquinaria de firma soporta el caso "firmar con el secreto
viejo, entregar el nuevo".

La verificación en vivo de extremo a extremo (provisionar un despliegue real, suspenderlo y comprobar
que devuelve **HTTP 402** en sus rutas no exentas) se realiza en la fase de demo multi-deploy; ver
`05-fase-3-cablear-spa.md` y la fase 4.

## Gotchas

- **Config con doble namespace.** La clave real es `config('cms.cms.control.*')`
  (`paquete.fichero.clave`), **no** `config('cms.control.*')`. El paquete `Core` sí usa `core.*`
  directo, lo que invita al error. Si lees la clave equivocada, `timeout`, `connect_timeout` y `verify`
  vuelven a sus defaults silenciosamente y, en dev, el cert self-signed rompe la conexión por no llamar
  a `withoutVerifying()`.
- **Firmar el path, no la URL completa.** Hay que extraer el path con `parse_url($url, PHP_URL_PATH)`.
  Firmar la URL entera (con esquema/host) o incluir la query rompe la verificación, porque el receptor
  solo conoce `$request->path()`.
- **Serializar el JSON una sola vez.** Si se deja que el cliente HTTP re-serialice el array, los bytes
  enviados pueden diferir de los hasheados y el `sha256(body)` del receptor falla. Siempre
  `json_encode` una vez y `withBody(...)` con esos mismos bytes.
- **El `base_url` ya trae `/api/v1`.** Los endpoints del cliente añaden solo `/control/...`. Duplicar el
  prefijo (`/api/v1/api/v1/...`) genera un 404 en el despliegue, no un error de firma — más difícil de
  diagnosticar.
- **Un control fallido es 502, nunca un 200 optimista.** El controlador debe abortar antes de tocar el
  mirror. Mutar local y luego intentar la llamada dejaría el panel mintiendo sobre el estado real del
  despliegue.
- **GET firma cuerpo vacío.** El cuerpo de un GET es `''`, y aún así entra en el `sha256('')` del
  payload. No es opcional: el receptor también hashea `''`.

## Ficheros tocados

- `src/packages/Cms/app/Support/CmsControlSigner.php` — firma HMAC pura (payload canónico + HMAC-SHA256
  hex), byte-idéntica al receptor.
- `src/packages/Cms/app/Services/CmsControlClient.php` — cliente HTTP de `/control`: `getState`,
  `setState`, `getUsage` (y `rotateSecret`); firma el path, envía los mismos bytes hasheados, mapea
  fallos a `CmsControlException`.
- `src/packages/Cms/app/Exceptions/CmsControlException.php` — excepción con `remoteStatus` y
  `remoteBody`, que los controladores convierten en 502.
- `src/packages/Cms/app/Services/CmsDeploymentSyncService.php` — `applyState` (espejo de estado) y
  `sync` (state + cuotas + snapshots de uso); reutilizado por las acciones de operador.
- `src/packages/Cms/app/Http/Controllers/CmsDeploymentsController.php` — acciones de operador
  `suspend`/`grace`/`resume`/`sync` (+ `usage`, `rotateSecret`) bajo `control deployments`, con
  espejado y auditoría; `controlError()` → 502.
- `src/packages/Cms/routes/api.php` — rutas `deployments/{id}/{suspend,grace,resume,sync,rotate-secret}`
  (POST) y `deployments/{id}/usage` (GET) dentro del grupo `auth:sanctum`.
- `src/tests/Feature/CmsControlTest.php` — cross-validación de la firma contra el middleware real,
  espejado, permisos y manejo de fallos (502).
