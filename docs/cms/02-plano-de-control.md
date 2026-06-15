# El plano de control: cómo el CMS gobierna despliegues remotos

## Por qué existe esta pieza

SnakeCloud es una plataforma multi-tenant donde cada cliente recibe un despliegue
aislado (contenedor y base de datos propios) que ejecuta el paquete Laravel `Core` y
sirve su API en `/api/v1`. Esos despliegues son deliberadamente **autónomos**: si el
panel del operador (el CMS) se cae, los clientes siguen siendo atendidos sin
degradación. Esa autonomía plantea un problema: ¿cómo gobierna el operador una flota
de N despliegues que viven en máquinas distintas, posiblemente en redes distintas, sin
acoplar el CMS a la topología de bases de datos de cada uno?

La respuesta es el **plano de control**: una superficie HTTP firmada que cada despliegue
expone en `/api/v1/control/*` y que solo el CMS sabe llamar, porque solo el CMS conoce
el secreto compartido de ese despliegue. El CMS empuja órdenes (suspender, poner en
gracia, reactivar, rotar el secreto) y lee estado y uso. No hay base de datos compartida,
no hay heartbeat, no hay dependencia inversa: el CMS habla y el despliegue obedece, y
cada parte puede vivir sin la otra. Se descartaron explícitamente las alternativas de
pull/heartbeat (reintroduce la dependencia del CMS para operar) y de escritura directa a
la BD de cada despliegue (se rompe en cuanto un despliegue vive en otro servidor); ver
`docs/CONTROL-PLANE.md`, sección "Alternativas descartadas".

Este documento describe el plano de control **desde la óptica del CMS que lo consume**:
el contrato firmado, los endpoints, la máquina de estados que activan y la rotación de
secreto. El emisor de las firmas es `Packages\Cms\Support\CmsControlSigner` y el cliente
HTTP es `Packages\Cms\Services\CmsControlClient`; el verificador en el otro extremo es
`Packages\Core\Http\Middleware\CoreControlAuthMiddleware`. Para entender qué hace el CMS
con las respuestas (acciones optimistas del store, toasts, resync) ver los documentos
hermanos sobre el SPA.

## El contrato HMAC, byte a byte

Cada petición al plano de control se autentica con una firma HMAC-SHA256 sobre una cadena
canónica. No es autenticación por identidad (no hay token Sanctum aquí): es autenticación
máquina-a-máquina con secreto compartido por despliegue. El emisor y el verificador
construyen **exactamente la misma cadena** o la firma no cuadra; de ahí la obsesión por
los detalles.

### La cadena canónica

El emisor está en `CmsControlSigner::canonicalPayload()`. Este es su código exacto:

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
```

Es decir, cuatro líneas unidas por un salto de línea `\n` (LF, no CRLF):

```
payload = timestamp + "\n" + METHOD + "\n" + /path + "\n" + sha256(body)
```

- **timestamp**: el unix time en segundos, como cadena.
- **METHOD**: el verbo HTTP en mayúsculas (`GET`, `POST`).
- **/path**: la ruta del URL, **solo el path** (sin query string), con barra inicial
  garantizada por `'/' . ltrim($path, '/')`.
- **sha256(body)**: el hash hexadecimal del cuerpo crudo de la petición. Para un `GET`
  el cuerpo es la cadena vacía `''`, cuyo sha256 es siempre el mismo valor conocido.

La firma final es el HMAC-SHA256 de esa cadena bajo el secreto del despliegue, en
hexadecimal minúscula (`CmsControlSigner::sign()`):

```php
public static function sign(string $secret, int $timestamp, string $method, string $path, string $body): string
{
    return hash_hmac('sha256', self::canonicalPayload($timestamp, $method, $path, $body), $secret);
}
```

El signer es **puro** (no hace I/O): por eso es testeable en unidad y se puede contrastar
byte a byte contra el middleware real.

### El lado verificador (Core)

Para confirmar que cuadran, este es el bloque equivalente en
`CoreControlAuthMiddleware::handle()`, que el despliegue ejecuta al recibir la petición:

```php
$payload = implode("\n", [
    $timestamp,
    $request->method(),
    '/' . ltrim($request->path(), '/'),
    hash('sha256', $request->getContent()),
]);

$expected = hash_hmac('sha256', $payload, $secret);

if (!hash_equals($expected, $signature)) {
    return $this->deny('Invalid control signature.');
}
```

La comparación es con `hash_equals()`, que es de tiempo constante: no filtra información
por el tiempo de respuesta como haría un `===` que cortocircuita en el primer byte
distinto. Un fallo de firma devuelve `401` con `{"error": "Invalid control signature."}`.

### El gotcha del path: por qué `parse_url` y no concatenar

Aquí está el detalle que más fácilmente rompe una integración. El `base_url` del
despliegue **ya incluye el prefijo `/api/v1`**. El cliente del CMS le añade el endpoint
(`/control/state`, etc.) y firma. Pero el verificador firma sobre `$request->path()`,
que en Laravel es el path **sin** dominio ni esquema. Si el CMS firmara la concatenación
ingenua, podría incluir esquema o dominio y dejaría de cuadrar.

`CmsControlClient::send()` resuelve esto extrayendo el path con `parse_url`, exactamente
para que coincida con lo que el receptor obtiene de `$request->path()`:

```php
$url = rtrim((string) $deployment->base_url, '/') . $endpoint;
// Sign the path only (no query), matching the receiver's '/'.ltrim($request->path()).
$path = (string) parse_url($url, PHP_URL_PATH);
$payloadBody = $body === null ? '' : (string) json_encode($body);
$timestamp = time();
$signature = CmsControlSigner::sign($secret, $timestamp, $method, $path, $payloadBody);
```

Así, para un `base_url` de `https://cliente.dominio.com/api/v1`, el path firmado es
`/api/v1/control/state`, idéntico a lo que el middleware reconstruye con
`'/' . ltrim($request->path(), '/')`.

### El gotcha del cuerpo: serializar una sola vez

`sha256(body)` debe calcularse sobre **los mismos bytes** que viajan en el cuerpo. Si el
CMS pasara el array a Guzzle como `json` y por separado serializara para el hash, una
diferencia mínima de codificación (orden de claves, espacios) rompería la firma. Por eso
el cliente serializa una sola vez y envía esos mismos bytes:

```php
$payloadBody = $body === null ? '' : (string) json_encode($body);
// ...
if ($payloadBody !== '') {
    $request = $request->withBody($payloadBody, 'application/json');
}
```

El query string (`?period=&from=&to=&per_page=`) viaja en el URL y **nunca se firma**:
solo se firma el path. El receptor tampoco lo incluye en su cadena canónica.

### Las cabeceras y la ventana anti-replay

El cliente adjunta dos cabeceras:

```php
$request = Http::withHeaders([
    'X-Control-Timestamp' => (string) $timestamp,
    'X-Control-Signature' => $signature,
    'Accept' => 'application/json',
])
    ->timeout((int) ($config['timeout'] ?? 5))
    ->connectTimeout((int) ($config['connect_timeout'] ?? 3));
```

| Cabecera | Contenido |
|---|---|
| `X-Control-Timestamp` | unix time en segundos (`time()`) |
| `X-Control-Signature` | HMAC-SHA256 en hex minúscula |

El verificador exige que ambas estén presentes y no vacías (si no, `401` con
`Missing control signature headers.`) y luego comprueba la **ventana de reloj** para
frenar ataques de repetición:

```php
$skew = (int) config('core.control.clock_skew_seconds', 300);

if (abs(time() - (int) $timestamp) > $skew) {
    return $this->deny('Control timestamp outside the allowed window.');
}
```

La ventana por defecto es de ±300 segundos (`CORE_CONTROL_CLOCK_SKEW`). Una firma con
timestamp fuera de esa ventana se rechaza con `401`
(`Control timestamp outside the allowed window.`), aunque el HMAC fuera correcto. Esto
implica que los relojes del CMS y de los despliegues deben estar razonablemente
sincronizados (NTP).

### Configuración del cliente y el gotcha del namespace de config

El CMS lee su configuración de salida de `cms.cms.control`. Ojo con la clave doble: en
este monorepo las configuraciones de paquete están namespaced como `<paquete>.<fichero>`,
de modo que `cms/config/cms.php` se lee como `config('cms.cms.*')`, **no**
`config('cms.control.*')`. Core, en cambio, usa `core.*` directo. El cliente lo documenta
en línea:

```php
// Package configs are namespaced <package>.<file>: cms/config/cms.php → config('cms.cms.*').
$config = (array) config('cms.cms.control', []);
```

Las claves disponibles (de `cms/config/cms.php`):

| Clave | Env | Defecto | Para qué |
|---|---|---|---|
| `timeout` | `CMS_CONTROL_TIMEOUT` | `5` | timeout total de la petición (s) |
| `connect_timeout` | `CMS_CONTROL_CONNECT_TIMEOUT` | `3` | timeout de conexión (s) |
| `verify` | `CMS_CONTROL_VERIFY` | `true` | verificación TLS del certificado |

`verify` solo se desactiva si vale exactamente `false`, y entonces se llama a
`$request->withoutVerifying()`. Esto cubre el escenario de despliegues con certificado
self-signed; en desarrollo, además, el SPA va por el proxy de Vite hacia
`https://localhost:8443` con `secure:false`, evitando CORS y el certificado.

## Los endpoints del despliegue

Las rutas se registran en `src/packages/Core/routes/api.php` bajo el prefijo `control`, y
**solo si existe `CORE_CONTROL_SECRET`**: sin secreto, la superficie de control no se
registra siquiera (defensa en profundidad).

```php
if ((string) config('core.control.secret', '') !== '') {
    Route::middleware(['api', CoreControlAuthMiddleware::class])
        ->prefix('control')
        ->group(function () {
            Route::get('state', [CoreControlController::class, 'showState']);
            Route::post('state', [CoreControlController::class, 'updateState']);
            Route::get('usage', [CoreControlController::class, 'usage']);
            Route::post('rotate-secret', [CoreControlController::class, 'rotateSecret']);
        });
}
```

Todas cuelgan de `/api/v1` (porque ese es el prefijo del grupo `api` del despliegue), van
firmadas por `CoreControlAuthMiddleware` y están excluidas de la documentación pública de
Scramble. El CMS las consume a través de los métodos de `CmsControlClient`:

### `GET /api/v1/control/state` — leer estado

Método CMS: `CmsControlClient::getState()`. Devuelve el estado actual, el paquete de
negocio y las métricas de cuota:

```php
return response()->json([
    'state' => $this->statePayload($this->stateService->current()),
    'package' => config('core.package'),
    'quotas' => CoreQuotaMetricModel::query()->orderBy('metric_key')->get()->map(...),
]);
```

El bloque `state` incluye `status` (lo escrito en la BD), `effective_status` (el estado
efectivo: un grace vencido se reporta como suspended, ver más abajo), `reason`, `message`,
`grace_until` y `changed_by`.

### `POST /api/v1/control/state` — cambiar estado

Método CMS: `CmsControlClient::setState($deployment, $status, $reason?, $message?, $graceUntil?)`.
Esta es la orden de kill-switch del operador. El controlador valida estrictamente:

```php
$data = $request->validate([
    'status' => 'required|string|in:active,grace,suspended',
    'reason' => 'nullable|string|max:100',
    'message' => 'nullable|string|max:1000',
    'grace_until' => 'nullable|date',
]);
```

El estado se persiste vía `CorePackageStateService::set(...)` con `changed_by = 'cms'`, y
se devuelve el nuevo `state`. El cliente del CMS arma el cuerpo así (con `grace_until` en
ISO 8601):

```php
return $this->send($deployment, 'POST', '/control/state', [
    'status' => $status,
    'reason' => $reason,
    'message' => $message,
    'grace_until' => $graceUntil?->toIso8601String(),
]);
```

### `GET /api/v1/control/usage` — leer uso agregado

Método CMS: `CmsControlClient::getUsage($deployment, $query)`. Devuelve un paginado de
`core_usage_rollups`. El query (`period=hour|day`, `from`, `to`, `per_page` hasta 1000)
viaja en el URL y, como se dijo, **no se firma**:

```php
/** GET /control/usage?period=&from=&to=&per_page= → paginated rollups (query not signed). */
public function getUsage(DeploymentModel $deployment, array $query = []): array
{
    return $this->send($deployment, 'GET', '/control/usage', null, $query);
}
```

Detalle importante para interpretar los datos: `core:usage:rollup` solo agrega buckets de
hora/día **cerrados**; la hora en curso queda fuera. El CMS cachea estos rollups
localmente (`cms_usage_snapshots`) para alimentar dashboards sin pegar a cada despliegue
en cada carga de página.

### `POST /api/v1/control/rotate-secret` — rotar el secreto

Método CMS: `CmsControlClient::rotateSecret($deployment, $newSecret)`. Se detalla en la
sección de rotación, más abajo.

## La máquina de estados: active, grace, suspended

El estado vive en una única fila (`core_package_state`, id=1) de la BD de cada despliegue.
Hay tres estados, tipados en `Packages\Core\Enums\CorePackageStatusEnum`:

```php
enum CorePackageStatusEnum: string
{
    case Active = 'active';
    case Grace = 'grace';
    case Suspended = 'suspended';
}
```

Las transiciones válidas son `active <-> grace <-> suspended`. Quien las dispara desde el
CMS es siempre `POST /control/state`; en el despliegue también pueden cambiarlas el CLI
(`core:status`) y el medidor de cuotas (`core:quota:measure`), todo convergiendo en
`CorePackageStateService::set()`.

### Estado efectivo: el grace vencido cuenta como suspendido

`status` es lo escrito en la BD, pero el comportamiento se decide por el **estado
efectivo**, que resuelve en lectura un grace cuyo plazo ya pasó. Lógica exacta en
`CorePackageStateData::effectiveStatus()`:

```php
public function effectiveStatus(): CorePackageStatusEnum
{
    if ($this->status === CorePackageStatusEnum::Grace
        && $this->graceUntil !== null
        && $this->graceUntil->isPast()) {
        return CorePackageStatusEnum::Suspended;
    }

    return $this->status;
}
```

Por eso el payload de `GET /control/state` expone tanto `status` como `effective_status`:
el CMS debe mostrar el efectivo si quiere reflejar lo que el cliente final experimenta.

### El 402 y las rutas exentas

El kill-switch lo aplica `CoreEnsureActiveMiddleware` en cada request del despliegue:

- **suspended** (o grace vencido): la API responde `402 Payment Required` con un JSON
  `{status, reason, message}` en toda ruta no exenta. El 402 es deliberado: 403 diría
  "prohibido por identidad", mientras que 402 expresa "relación de servicio caducada".
- **grace** (vigente): la petición pasa, pero se añaden cabeceras de aviso
  (`X-Package-Status`, `X-Package-Grace-Until`, `X-Package-Message`).
- **active**: la petición pasa sin más.

Un despliegue **suspendido sigue midiendo**: los 402 son datos válidos para el CMS. Y hay
rutas **exentas** del kill-switch (`core.state.exempt_paths`), cruciales para que el CMS
pueda operar un despliegue caído:

```php
'exempt_paths' => ['api/v1', 'api/v1/control/*'],
```

Es decir, el health check (`api/v1`) y **todo el plano de control** (`api/v1/control/*`)
siguen respondiendo aunque el despliegue esté suspendido. Esto es lo que permite que el
CMS reactive un despliegue suspendido: si `/control/*` se bloqueara con un 402, el
kill-switch no tendría marcha atrás remota.

## Rotación de secreto: las tres capas y la precedencia BD-vs-env

Rotar el `control_secret` de un despliegue debe invalidar el secreto viejo **en runtime,
sin reiniciar** el contenedor. Esto se logra con tres capas y una regla de precedencia.

### Capa 1: el CMS genera y entrega el secreto, firmando con el viejo

El controlador `CmsDeploymentsController::rotateSecret()` genera un secreto nuevo y se lo
entrega al despliegue **firmando la entrega con el secreto actual (el viejo)**, para que
el despliegue pueda verificar la petición antes de cambiar nada:

```php
$new = 'sk_ctl_live_' . bin2hex(random_bytes(12));

try {
    // send() signs with the row's CURRENT secret (read before we change it below).
    $this->control->rotateSecret($deployment, $new);
} catch (CmsControlException $e) {
    return $this->controlError($e);
}

// Remote accepted → adopt locally.
$deployment->control_secret = $new;
$deployment->control_secret_rotated_at = CarbonImmutable::now();
$deployment->save();
```

El orden importa: primero se entrega (firmado con el viejo), y **solo si el despliegue
acepta** el CMS adopta el nuevo localmente. La rotación es la excepción a las acciones
optimistas del store del SPA: es asíncrona y el servidor del CMS coordina el swap. Queda
una ventana mínima: si el `save()` local fallara tras el swap remoto, el despliegue ya
estaría en `$new` mientras el CMS guarda el viejo (el operador re-aprovisionaría).

### Capa 2: el despliegue almacena el nuevo secreto

`CoreControlController::rotateSecret()` valida y persiste el secreto en
`core_package_state`:

```php
$data = $request->validate([
    'new_secret' => 'required|string|min:16|max:128',
]);

CorePackageStateModel::query()->updateOrCreate(
    ['id' => 1],
    ['control_secret' => $data['new_secret']],
);

return response()->json([
    'rotated' => true,
    'suffix' => substr($data['new_secret'], -4),
]);
```

Devuelve solo `{rotated, suffix}` (los últimos 4 caracteres), nunca el secreto completo.

### Capa 3: el verificador prefiere la BD sobre el env

Aquí está el corazón de la rotación en caliente. `CoreControlAuthMiddleware::activeSecret()`
decide contra qué secreto verificar: **el override rotado en BD prevalece sobre
`CORE_CONTROL_SECRET`**, con fail-open al env si la lectura o el descifrado fallan:

```php
private function activeSecret(): string
{
    try {
        $rotated = CorePackageStateModel::query()->find(1)?->control_secret;
        if (is_string($rotated) && $rotated !== '') {
            return $rotated;
        }
    } catch (\Throwable) {
        // table missing / decrypt failure → fall back to the env secret
    }

    return (string) config('core.control.secret', '');
}
```

El efecto combinado: en cuanto el despliegue guarda el secreto rotado (capa 2), la
siguiente petición de control se verifica contra ese valor de BD (capa 3), y el secreto
viejo del `.env` **deja de funcionar inmediatamente** sin reiniciar el proceso. El
`control_secret` se almacena cifrado (cast `encrypted`) tanto en el despliegue
(`core_package_state.control_secret`) como en el registro del CMS
(`cms_deployments.control_secret`, cast `encrypted` en `DeploymentModel`).

El fail-open es deliberado: si la tabla aún no existe (primer boot pre-migrate) o el
descifrado falla, se vuelve al secreto del env en lugar de dejar el despliegue
incontrolable.

## Resumen del flujo completo

1. El operador pulsa una acción en el SPA (p. ej. suspender). El store del CMS muta
   localmente de forma optimista y dispara la persistencia en segundo plano.
2. El backend del CMS llama al método correspondiente de `CmsControlClient`, que
   construye la cadena canónica con `CmsControlSigner`, firma con el `control_secret` de
   esa fila y envía la petición con `X-Control-Timestamp` y `X-Control-Signature`.
3. El despliegue recibe en `/api/v1/control/*`. `CoreControlAuthMiddleware` reconstruye la
   cadena byte a byte, resuelve el secreto activo (BD sobre env), comprueba la ventana de
   reloj y compara con `hash_equals`.
4. Si la firma es válida, `CoreControlController` ejecuta la orden y devuelve el nuevo
   estado/uso. El CMS reconcilia con la respuesta en 2xx, o hace resync y muestra un toast
   en caso de fallo.
5. El cambio de estado se propaga al kill-switch del despliegue en `≤ TTL` (caché de
   estado), de modo que las rutas no exentas pasan a responder 402 cuando corresponde.

Para el detalle del verificador y el modelo de datos del lado del despliegue, ver
`docs/CONTROL-PLANE.md`. Para cómo el SPA orquesta estas llamadas, ver los documentos
hermanos del CMS.
