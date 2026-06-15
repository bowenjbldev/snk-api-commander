# Fase 7 — Rotación real del secreto de control (3 capas, en runtime)

## Contexto (por qué)

El canal de gestión entre el Commander y cada despliegue (`/control/*`) está protegido por una firma
HMAC-SHA256 con un **secreto compartido por despliegue**. Ese secreto es la llave maestra del
control remoto: quien lo tiene puede suspender, poner en gracia o reactivar el despliegue. Por eso
debe poder **rotarse** — cuando se sospecha de una fuga, cuando rota un operador, o simplemente por
higiene periódica.

El problema real es que el secreto vive en dos sitios a la vez: en el lado del Commander (columna
`control_secret` cifrada en `commander_deployments`) y en el lado del despliegue (variable de entorno
`CORE_CONTROL_SECRET`). Rotarlo "a mano" significaría editar el `.env` del contenedor y
**reiniciarlo**, con corte de servicio. Eso es inaceptable: los despliegues son autónomos y deben
seguir sirviendo aunque cambie su secreto de control.

Además, en el SPA la acción **Rotate secret** era literalmente el último *stub* que quedaba: el
botón existía en la UI pero no llamaba a nada real. Esta fase lo convierte en una operación viva,
de extremo a extremo, **sin reinicio del despliegue** y con el secreto viejo invalidado al instante.

La idea central del diseño es simple: el secreto rotado, guardado **cifrado en la base de datos del
despliegue**, **prevalece** sobre el de la variable de entorno. Así, en cuanto el despliegue acepta
el nuevo secreto, el viejo deja de verificar — sin tocar el `.env` ni reiniciar el contenedor.

Este documento es hermano de las fases anteriores del proxy `/control` (ver
`08-fase-2-proxy-control.md` para el contrato de firma y la máquina de estados, y el contrato
resumido en la memoria del proyecto).

## Qué se construyó

La rotación atraviesa **tres capas**, cada una con un fichero concreto:

1. **Persistencia en el despliegue (Core).** Una migración añade una columna `control_secret`
   cifrada a `core_package_state`, y `CoreControlAuthMiddleware::activeSecret()` la consulta para
   verificar las firmas: si hay un secreto rotado en BD lo usa, si no, cae al de la variable de
   entorno.

2. **Endpoint de rotación en el despliegue (Core).** `CoreControlController::rotateSecret()` recibe
   el nuevo secreto (en una petición firmada con el **viejo**), lo guarda en esa columna y responde.

3. **Orquestación desde el Commander.** `CommanderControlClient::rotateSecret()` entrega el nuevo secreto firmado
   con el secreto **actual**; `CommanderDeploymentsController::rotateSecret()` genera el nuevo secreto, lo
   entrega y solo entonces lo **adopta** localmente; y `store.rotateSecret()` en el SPA dispara la
   acción (de forma asíncrona, no optimista) y registra la auditoría.

### Ficheros y firmas exactas

- **Migración (Core):**
  `src/packages/Core/database/migrations/2026_06_10_000024_add_control_secret_to_core_package_state.php`

  ```php
  Schema::table('core_package_state', function (Blueprint $table) {
      $table->text('control_secret')->nullable()->after('changed_by');
  });
  ```

  El modelo `Packages\Core\Models\CorePackageStateModel` lo declara cifrado:

  ```php
  'control_secret' => 'encrypted',
  ```

- **Middleware (Core):**
  `src/packages/Core/app/Http/Middleware/CoreControlAuthMiddleware.php`

  ```php
  private function activeSecret(): string
  {
      try {
          $rotated = CorePackageStateModel::query()->find(1)?->control_secret;
          if (is_string($rotated) && $rotated !== '') {
              return $rotated;
          }
      } catch (\Throwable) {
          // tabla ausente / fallo de descifrado → cae al secreto de entorno
      }

      return (string) config('core.control.secret', '');
  }
  ```

- **Controlador receptor (Core):**
  `src/packages/Core/app/Http/Controllers/CoreControlController.php` →
  `rotateSecret(Request $request): JsonResponse`. Valida
  `new_secret` (`required|string|min:16|max:128`), hace `updateOrCreate(['id' => 1], [...])` sobre
  `CorePackageStateModel` y responde `{ "rotated": true, "suffix": "<4 últimos>" }`.

- **Cliente firmante (Commander):**
  `src/packages/Commander/app/Services/CommanderControlClient.php` →
  `rotateSecret(DeploymentModel $deployment, string $newSecret): array`, que hace
  `POST /control/rotate-secret {new_secret}` a través de `send()`.

- **Orquestador del operador (Commander):**
  `src/packages/Commander/app/Http/Controllers/CommanderDeploymentsController.php` →
  `rotateSecret(Request $request, $id = null): JsonResponse`.

- **Store del SPA:**
  `commander/src/store/store.ts` → `rotateSecret: async (id) => Promise<string | null>`.

- **Rutas:**
  - Despliegue: `src/packages/Core/routes/api.php` →
    `Route::post('rotate-secret', [CoreControlController::class, 'rotateSecret']);` dentro del grupo
    con prefijo `control` y `CoreControlAuthMiddleware`.
  - Commander: `src/packages/Commander/routes/api.php` →
    `Route::post('deployments/{id}/rotate-secret', [CommanderDeploymentsController::class, 'rotateSecret']);`

- **Persistencia en el Commander:** las columnas `control_secret` (cifrada) y
  `control_secret_rotated_at` ya existen en
  `src/packages/Commander/database/migrations/2026_06_15_000002_create_commander_deployments_table.php`, y el
  modelo `Packages\Commander\Models\DeploymentModel` las castea (`'control_secret' => 'encrypted'`,
  `'control_secret_rotated_at' => 'datetime'`).

## Decisiones clave (y por qué)

**El secreto rotado en BD prevalece sobre el de entorno, con *fail-open*.** Es el corazón de la
fase. `activeSecret()` lee primero la columna cifrada de `core_package_state` (fila `id = 1`); solo
si está vacía usa `config('core.control.secret')` (que viene de `CORE_CONTROL_SECRET`). Por tanto,
en el instante en que el despliegue guarda el nuevo secreto, **el viejo deja de verificar** — sin
reinicio, sin tocar el `.env`. El `try/catch` con retorno al entorno (*fail-open*) es deliberado:
si la tabla aún no existe (despliegue recién migrado) o el descifrado falla, **no** queremos dejar
el canal de control muerto; preferimos seguir usando el secreto de entorno conocido.

**La entrega del nuevo secreto se firma con el secreto VIEJO.** El despliegue debe poder
**autenticar** la petición de rotación antes de aceptar el cambio. Si firmáramos con el secreto
nuevo, cualquiera podría inyectar uno. Por eso `CommanderControlClient::send()` lee
`(string) $deployment->control_secret` (el **actual**, todavía el viejo en ese momento) y firma con
él; el `CommanderDeploymentsController::rotateSecret()` solo cambia el secreto local **después** de que el
despliegue respondió 2xx. El comentario en el controlador lo deja explícito:

```php
// send() signs with the row's CURRENT secret (read before we change it below).
$this->control->rotateSecret($deployment, $new);
```

**El nuevo secreto lo genera el Commander, no el SPA.** `CommanderDeploymentsController::rotateSecret()` lo
acuña con:

```php
$new = 'sk_ctl_live_' . bin2hex(random_bytes(12));
```

Por eso la acción del SPA **no es optimista**: el cliente no puede adivinar el valor, así que espera
la respuesta del servidor y reconcilia con ella (a diferencia de `suspend`/`grace`/`resume`, que sí
mutan el estado local de inmediato).

**El secreto nunca viaja entero al navegador.** Tanto el modelo del Commander como el del despliegue solo
exponen un **sufijo** de 4 caracteres. `DeploymentModel` oculta `control_secret` (`$hidden`) y añade
`control_secret_suffix` (`$appends`), calculado con `substr($secret, -4)`. La respuesta de Core a la
rotación también devuelve solo `"suffix"`. El operador ve `…ab12`, nunca el secreto.

**Adopción local solo tras el 2xx remoto, con una ventana mínima reconocida.** El orden importa:
primero se entrega al despliegue, y solo si lo acepta el Commander guarda `control_secret` y
`control_secret_rotated_at`. El propio código documenta el caso de borde (ventana diminuta):

```php
// Remote accepted → adopt locally. Tiny window: if this save fails after the remote swap, the
// deploy is on $new while the Commander holds the old secret (operator would re-provision).
```

**Auditoría obligatoria.** Toda rotación deja un `ActivityEventModel` de tipo `rotate` con el actor
(`auth()->user()?->name ?? 'operator'`) y el sufijo nuevo (`'…' . substr($new, -4)`) como mensaje,
para tener traza de quién rotó y a qué sufijo.

## Cómo funciona

El flujo completo, de extremo a extremo:

1. **SPA → Commander.** `store.rotateSecret(id)` hace
   `POST /deployments/{id}/rotate-secret` y **espera** la respuesta (no optimista):

   ```ts
   rotateSecret: async (id) => {
     try {
       const dep = await api<Deployment>(`/deployments/${id}/rotate-secret`, { method: 'POST' })
       if (dep && typeof dep === 'object' && 'id' in dep) replaceDep(dep)
       return dep?.control_secret_suffix ?? null
     } catch (e) {
       fail(e instanceof Error ? e.message : 'Secret rotation failed')
       return null
     }
   }
   ```

2. **Commander (orquestación).** `CommanderDeploymentsController::rotateSecret()` exige el permiso
   `control deployments`, localiza el despliegue, **genera** `$new`
   (`'sk_ctl_live_' . bin2hex(random_bytes(12))`) y lo entrega con
   `$this->control->rotateSecret($deployment, $new)`.

3. **Commander → despliegue (firma con el viejo).** `CommanderControlClient::rotateSecret()` llama a `send()`,
   que firma con el secreto **actual** del despliegue. La cadena canónica
   (`Packages\Commander\Support\CommanderControlSigner`) es:

   ```text
   payload = timestamp \n METHOD \n /path \n sha256(body)
   firma   = hex( hmac_sha256(payload, control_secret_ACTUAL) )
   headers = X-Control-Timestamp: <unix>, X-Control-Signature: <hex>
   ```

   Como `base_url` ya incluye `/api/v1`, el endpoint que viaja es
   `.../api/v1/control/rotate-secret` y la firma se calcula sobre el **path** del URL
   (`parse_url(..., PHP_URL_PATH)`), de modo que coincide con `'/' . ltrim($request->path(), '/')`
   del receptor.

4. **Despliegue (verificación + guardado).** `CoreControlAuthMiddleware` verifica la firma con
   `activeSecret()` — que en este momento aún es el **viejo** —, comprueba la ventana de reloj
   (`core.control.clock_skew_seconds`, 300s) con `hash_equals`, y deja pasar. Entonces
   `CoreControlController::rotateSecret()` valida `new_secret` y hace:

   ```php
   CorePackageStateModel::query()->updateOrCreate(
       ['id' => 1],
       ['control_secret' => $data['new_secret']],
   );
   ```

   **A partir de aquí el secreto viejo ya NO verifica:** la próxima llamada que entre pasará por
   `activeSecret()`, que ahora encuentra el nuevo secreto en BD y lo prefiere sobre el de entorno.
   Core responde `{ "rotated": true, "suffix": "<4 últimos>" }`.

5. **Commander (adopción).** Tras el 2xx, el controlador guarda el nuevo secreto y la marca de tiempo:

   ```php
   $deployment->control_secret = $new;
   $deployment->control_secret_rotated_at = CarbonImmutable::now();
   $deployment->save();
   ```

   Registra el evento `rotate` y devuelve `$deployment->fresh()`, cuyo `control_secret_suffix`
   refleja ya el sufijo nuevo.

6. **SPA.** `replaceDep(dep)` reconcilia el despliegue con el sufijo nuevo y el botón muestra el
   resultado (`…` + 4 últimos).

A partir de ese punto, Commander y despliegue comparten **el mismo secreto nuevo**, y cualquier intento de
control firmado con el viejo se rechaza con **401**.

## Verificación

Además de la verificación unitaria del firmante (`CommanderControlSigner`, cruzada contra el middleware),
la prueba **en vivo** es la que da confianza: se provisiona un despliegue real con el control plane
activo, se rota el secreto y se comprueba que el viejo muere y el nuevo vive.

1. **Provisionar un despliegue real** (con `scripts/subscription.sh add <cliente> Core`), que crea
   BD/contenedor/vhost, genera `CONTROL_SECRET`, inyecta `CORE_CONTROL_SECRET` y lo auto-registra en
   el Commander (`commander:register-deploy`).

2. **Estado base con el secreto viejo:** una llamada de control firmada con el secreto actual
   responde **200** (p. ej. `GET /control/state` vía el botón **Sync** del panel).

3. **Rotar** desde el panel (botón **Rotate secret**) o por API:

   ```bash
   curl -ksS -X POST https://localhost:8443/api/v1/deployments/<id>/rotate-secret \
     -H "Authorization: Bearer <token-operador>" -H "Accept: application/json"
   ```

   El despliegue acepta (la firma usa el secreto viejo), guarda el nuevo en
   `core_package_state.control_secret` y responde con el nuevo `suffix`.

4. **El viejo deja de funcionar:** cualquier petición de control firmada con el secreto antiguo
   (por ejemplo, reusando el Commander antes de adoptar, o un cliente externo con el secreto filtrado)
   recibe ahora **401** (`Invalid control signature.`) — porque `activeSecret()` ya prefiere el
   nuevo de BD.

5. **El nuevo funciona:** una nueva acción de control desde el Commander (que ya adoptó `$new`) responde
   **200**. El sufijo en el panel coincide con el devuelto por Core.

6. **Sin reinicio:** todo lo anterior ocurre **sin reiniciar** el contenedor del despliegue ni
   editar su `.env` — el cambio es puramente de runtime en BD.

7. **Desmontar** el despliegue de prueba al terminar.

Resumen de la prueba: **rotar → viejo 401, nuevo 200**, sin reinicio.

## Gotchas

- **Config con namespace de paquete.** El cliente del Commander lee `config('commander.commander.control', [])`
  (clave `paquete.fichero`), **no** `config('cms.control', ...)`. Core, en cambio, usa `core.*`
  directo (`config('core.control.secret')`, `config('core.control.clock_skew_seconds')`). Confundir
  los namespaces es un error fácil y silencioso.

- **`base_url` ya trae `/api/v1`.** El cliente solo añade `/control/rotate-secret`. La firma se
  calcula sobre el **path** del URL completo (`parse_url`), para que cuadre con
  `$request->path()` del receptor. No firmar el host ni el query string.

- **El secreto se firma con el VIEJO, no con el nuevo.** Es contraintuitivo pero indispensable: el
  despliegue debe autenticar al Commander *antes* de adoptar el cambio. `send()` lee el secreto **actual**
  del modelo justo antes de que el controlador lo reemplace.

- **El secreto rotado prevalece y *fail-open* al entorno.** Si la columna está vacía, falla el
  descifrado o falta la tabla, `activeSecret()` cae a `CORE_CONTROL_SECRET`. Esto evita dejar el
  canal de control muerto, pero implica que **borrar** la fila/columna en BD reactiva el secreto de
  entorno: tenerlo presente al depurar.

- **Ventana de inconsistencia diminuta.** Si el `save()` local del Commander falla **después** de que el
  despliegue ya cambió su secreto, el despliegue queda en `$new` y el Commander conserva el viejo: el
  control deja de funcionar y la salida es **re-provisionar** el despliegue. El código lo documenta;
  es un compromiso consciente (no hay transacción distribuida).

- **El secreto entero nunca llega al navegador.** Solo el sufijo de 4 caracteres
  (`control_secret_suffix` / `"suffix"`). Si la UI necesita "más", la respuesta correcta es **no**
  exponerlo: rotar de nuevo si hay duda.

- **La acción del SPA NO es optimista.** A diferencia de `suspend`/`grace`/`resume`, `rotateSecret`
  es `async` y espera al servidor, porque el secreto lo **genera** el backend. No intentar mutarlo
  localmente "por adelantado".

- **El secreto necesita una `APP_KEY` estable.** Al estar cifrado (`'control_secret' => 'encrypted'`
  en ambos modelos), si rota o se pierde la `APP_KEY` del despliegue, el secreto guardado deja de
  descifrarse y `activeSecret()` cae al entorno por el *fail-open*.

## Ficheros tocados

- `src/packages/Core/database/migrations/2026_06_10_000024_add_control_secret_to_core_package_state.php`
  — añade la columna `control_secret` (cifrada, nullable) a `core_package_state`.
- `src/packages/Core/app/Models/CorePackageStateModel.php` — cast `'control_secret' => 'encrypted'`.
- `src/packages/Core/app/Http/Middleware/CoreControlAuthMiddleware.php` — método `activeSecret()`
  (rotado-BD `??` entorno, *fail-open*).
- `src/packages/Core/app/Http/Controllers/CoreControlController.php` — método `rotateSecret()`.
- `src/packages/Core/routes/api.php` — ruta `POST control/rotate-secret`.
- `src/packages/Commander/app/Services/CommanderControlClient.php` — método `rotateSecret()` (firma con el
  secreto actual).
- `src/packages/Commander/app/Http/Controllers/CommanderDeploymentsController.php` — método `rotateSecret()`
  (genera, entrega firmado-con-viejo, adopta en 2xx, audita).
- `src/packages/Commander/routes/api.php` — ruta `POST deployments/{id}/rotate-secret`.
- `src/packages/Commander/app/Models/DeploymentModel.php` — casts `control_secret`/
  `control_secret_rotated_at`, ocultación del secreto y accesor `control_secret_suffix`.
- `commander/src/store/store.ts` — acción `rotateSecret` (`async`, no optimista, reconcilia + auditoría).
