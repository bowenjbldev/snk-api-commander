# Fase 1 — Registro y autenticación (paquete `Commander`)

## Contexto (por qué)

El Commander es el panel del operador de SnakeCloud: desde él se ven y se gobiernan N despliegues
remotos (suspender, poner en gracia, reactivar, rotar secreto, sincronizar). Antes de poder
controlar nada, el panel necesita dos cosas que esta fase construye y que no pueden faltar:

1. **Una fuente de verdad propia.** El operador no puede depender de consultar en vivo a cada
   despliegue para saber qué clientes existen, qué despliegues hay, en qué estado están, qué se
   les factura o qué cuotas consumen. Si un despliegue cae, el panel debe seguir mostrando su
   ficha y su último estado conocido. Por eso el Commander tiene su **propia base de datos** con las
   tablas `commander_clients`, `commander_deployments`, `commander_subscriptions`, `commander_invoices`,
   `commander_quota_metrics` y `commander_activity_events`. Es el espejo (mirror) local que las fases
   posteriores refrescan vía `/control`.

2. **Un login de administrador.** Toda la API admin va detrás de Sanctum y de permisos Spatie. Sin
   sesión autenticada y sin permisos no se lista ni se toca nada.

La forma exacta del JSON que consume la SPA la fija el fichero `commander/src/store/types.ts`; el
contrato de datos de esta fase está deliberadamente alineado con esos tipos (por ejemplo, el objeto
`state` anidado del despliegue o el `control_secret_suffix`). El seeder porta el mismo dataset de
demostración que la SPA trae como fixtures en `commander/src/store/fixtures.ts`, de modo que el panel,
una vez cableado en la Fase 3, muestra exactamente los mismos datos contra la base de datos real.

Esta fase **no** habla todavía con ningún despliegue: no hay firma HMAC ni llamadas salientes
`/control`. Eso es la Fase 2. Aquí se levanta el registro y la puerta de entrada.

## Qué se construyó

- **Scaffolding del paquete Laravel `Commander`** en `src/packages/Commander`, con la estructura estándar de
  paquete del monorepo (`app/`, `config/`, `database/`, `routes/`) y su
  `CommanderServiceProvider`.
- **Seis migraciones `commander_*`** con clave primaria de tipo `string`, porque los identificadores los
  fija el cliente (la SPA / el seeder), no son autoincrementales.
- **Seis modelos** Eloquent, todos con PK string. `DeploymentModel` es el más rico: expone un
  objeto `state` anidado y un `control_secret_suffix`, y oculta el secreto cifrado y las columnas
  planas de estado.
- **Seis controladores** que extienden `CoreBaseApiController` (CRUD genérico + chequeo de
  permisos), más dos controladores delegados (`CommanderAuthController`, `CommanderUsersController`) que
  heredan del Core sin añadir nada.
- **Un seeder** (`CommanderDatabaseSeeder`) que crea los permisos Spatie del Commander, el rol `admin` y porta
  el dataset de demostración completo de `fixtures.ts`.
- **Autenticación Sanctum** reutilizada del Core: el usuario admin es `admin@snake.com` con
  contraseña `secret1234` (lo siembra `CoreDatabaseSeeder`), y los **permisos Spatie** que rigen
  cada recurso del panel los siembra el seeder del Commander.
- **Rutas** en `src/packages/Commander/routes/api.php`, montadas bajo el prefijo `api/v1` por el Core.

### Las seis migraciones

Todas usan `$table->string('id', 64)->primary()` (o `96` en quotas) en lugar de `id()`
autoincremental. Ficheros en `src/packages/Commander/database/migrations/`:

| Migración | Tabla | Notas de esquema |
| --- | --- | --- |
| `2026_06_15_000001_create_commander_clients_table.php` | `commander_clients` | PK = slug (`acme`, `bluepeak`…); `status` = `active\|inactive`. |
| `2026_06_15_000002_create_commander_deployments_table.php` | `commander_deployments` | PK = slug == name; `control_secret` (`text`, cifrado en reposo), `control_secret_rotated_at`, y las columnas planas de estado `status`/`reason`/`message`/`grace_until`/`changed_by`/`changed_at`. |
| `2026_06_15_000003_create_commander_subscriptions_table.php` | `commander_subscriptions` | `billing_status` = `paid\|pending\|overdue`; `auto_suspend` booleano. |
| `2026_06_15_000004_create_commander_invoices_table.php` | `commander_invoices` | PK con formato `INV-2026-0140`; `enforcement` = `grace\|suspended\|null`. |
| `2026_06_15_000005_create_commander_quota_metrics_table.php` | `commander_quota_metrics` | PK = `"${deployment_id}:${metric_key}"` (string de 96); `on_exceed` = `none\|warn\|suspend`; `unit` = `count\|mb`. |
| `2026_06_15_000006_create_commander_activity_events_table.php` | `commander_activity_events` | `kind` = `suspend\|grace\|resume\|paid\|quota`. |

> Nota de alcance: en disco existe además una séptima migración,
> `2026_06_15_000007_create_commander_usage_snapshots_table.php` (tabla `commander_usage_snapshots`), y su
> modelo `UsageSnapshotModel`. **No pertenece a la Fase 1**: es la caché local de uso de la Fase 6.
> El seeder ya la rellena para que los charts de plataforma funcionen de fábrica, pero el registro
> y la auth de esta fase se sostienen sobre las seis tablas listadas arriba.

### Los seis modelos

En `src/packages/Commander/app/Models/`. Todos comparten el patrón base:

```php
public $incrementing = false;
protected $keyType = 'string';
protected $guarded = [];
```

- `ClientModel` — relaciones `deployments()`, `subscriptions()`, `invoices()` (`hasMany`).
- `DeploymentModel` — el modelo central (detallado abajo).
- `SubscriptionModel` — `belongsTo` cliente y despliegue; castea `price_monthly`/`auto_suspend`/`started_at`.
- `InvoiceModel` — `belongsTo` cliente y despliegue; castea `amount`/`issued_at`/`due_at`.
- `QuotaModel` (tabla `commander_quota_metrics`) — `belongsTo` despliegue; castea `current_value`/`limit_value`/`exceeded_at`.
- `ActivityEventModel` — sin relaciones; castea `at`.

### Los seis controladores

En `src/packages/Commander/app/Http/Controllers/`. Los de recurso extienden `CoreBaseApiController` y solo
declaran, de forma plana, el modelo, los permisos, las relaciones permitidas en `?with=` y las reglas de
validación. No todos son CRUD completo: `CommanderClientsController` declara los cinco permisos
(index/show/create/update/delete), pero `CommanderActivityController` solo `index`/`show` (ruta
`only(['index','show'])`) y `CommanderQuotasController` solo `index`/`show`/`update`. (Además, en disco
hay un séptimo controlador que extiende `CoreBaseApiController`, `CommanderUsageController` —`GET usage/platform`—,
pero pertenece a la caché de uso de plataforma de la Fase 6, no al registro de esta fase.) Por ejemplo
`CommanderClientsController`:

```php
protected string $modelClass = ClientModel::class;
protected string $indexPermission = 'index clients';
protected string $showPermission  = 'show clients';
protected string $storePermission = 'create clients';
protected string $updatePermission = 'update clients';
protected string $destroyPermission = 'delete clients';
protected array  $allowedWith = ['deployments', 'subscriptions', 'invoices'];
```

`CommanderDeploymentsController` declara el mismo CRUD (`*  deployments`) y añade, ya pensando en la Fase
2, las acciones de operador (`suspend`, `grace`, `resume`, `sync`, `usage`, `rotateSecret`),
guardadas por un permiso dedicado `control deployments` y no por el `update deployments` de CRUD.
En la Fase 1 esos métodos ya existen pero su mecánica de firma HMAC pertenece a la Fase 2.

Además, dos controladores **delegan** íntegramente en el Core sin código propio:

```php
// CommanderAuthController
class CommanderAuthController extends CoreAuthController {}

// CommanderUsersController
class CommanderUsersController extends CoreUsersController {}
```

### El seeder

`src/packages/Commander/database/seeders/CommanderDatabaseSeeder.php` hace dos cosas:

1. **`seedPermissions()`** genera los permisos Spatie del panel por producto cartesiano de
   `['clients','deployments','subscriptions','invoices','quotas','activity']` ×
   `['index','show','create','update','delete']`, añade el permiso custom `control deployments`, y
   se los concede todos al rol `admin` (guard `sanctum`).
2. Porta el **dataset demo** de `fixtures.ts`: 8 clientes, 15 despliegues, 10 suscripciones, 8
   facturas, las cuotas por despliegue, 6 eventos de actividad y (ya para la Fase 6) 48 snapshots
   horarios de uso por despliegue. Todo con `updateOrCreate` por id, así que es idempotente.

## Decisiones clave (y por qué)

- **PK string en todas las tablas.** Los ids vienen del mundo del SPA y son slugs legibles
  (`acme`, `bluepeak-eu`, `tetra-telemetry`) o cadenas con formato (`INV-2026-0140`,
  `sub_acme-shop`, `acme-shop:requests_day`). Mantener el id del Commander idéntico al del SPA elimina
  una capa de traducción y hace el espejo trivial de razonar. De ahí
  `$incrementing = false; $keyType = 'string';` y migraciones con `string('id')->primary()`.

- **El despliegue expone `state` anidado, no columnas planas.** La SPA modela el estado del
  despliegue como un objeto (`DeploymentState` en `types.ts`). En base de datos, en cambio,
  conviene tener columnas planas indexables (`status`, `reason`, `message`, `grace_until`,
  `changed_by`, `changed_at`). Para conciliar ambos mundos, `DeploymentModel` **oculta** las
  columnas planas y **añade** un atributo computado `state`:

  ```php
  protected $hidden = [
      'control_secret', 'status', 'reason', 'message',
      'grace_until', 'changed_by', 'changed_at',
  ];
  protected $appends = ['state', 'control_secret_suffix'];

  public function getStateAttribute(): array
  {
      return [
          'status'     => $this->attributes['status'] ?? 'active',
          'reason'     => $this->attributes['reason'] ?? null,
          'message'    => $this->attributes['message'] ?? null,
          'grace_until'=> optional($this->grace_until)->toISOString(),
          'changed_by' => $this->attributes['changed_by'] ?? null,
          'changed_at' => optional($this->changed_at)->toISOString(),
      ];
  }
  ```

  Así el JSON que sale por la API ya tiene la forma que el store del SPA espera, sin transformación
  en el cliente.

- **El secreto de control nunca sale; solo su sufijo.** `control_secret` se castea como `encrypted`
  (cifrado en reposo) y está en `$hidden`, de modo que **jamás** aparece en una respuesta. Para que
  el operador pueda verificar de un vistazo qué secreto está activo sin exponerlo, el modelo añade
  un atributo de solo lectura con los **últimos 4 caracteres** del secreto descifrado:

  ```php
  public function getControlSecretSuffixAttribute(): ?string
  {
      $secret = $this->control_secret;        // descifrado por el cast
      return $secret ? substr($secret, -4) : null;
  }
  ```

- **Acciones de operador con permiso propio (`control deployments`).** Suspender o rotar un secreto
  no es lo mismo que editar el nombre de un despliegue. Por eso `CommanderDeploymentsController` separa el
  permiso de control del de CRUD: `suspend/grace/resume/sync/rotateSecret` exigen
  `control deployments`, mientras que `update` exige `update deployments`. La acción `usage` exige
  solo `show deployments` (es lectura).

- **Auth y usuarios reutilizados del Core, no reimplementados.** `CommanderAuthController` y
  `CommanderUsersController` son clases vacías que heredan de `CoreAuthController` y
  `CoreUsersController`. Toda la lógica de login/logout/me y de gestión de usuarios vive en el Core
  y es común a todos los paquetes; el Commander solo la reexpone bajo sus rutas. El admin de demostración
  (`admin@snake.com` / `secret1234`) lo crea `CoreDatabaseSeeder`, y `CommanderDatabaseSeeder` se limita a
  darle al rol `admin` los permisos específicos del panel.

## Cómo funciona

### Registro de rutas (prefijo `api/v1`)

`src/packages/Commander/routes/api.php` define todo bajo el grupo `api`. El Core descubre el fichero de
rutas del paquete activo y lo monta bajo `api/v1` en `CoreRouteServiceProvider::configureRoutes()`:

```php
Route::prefix('api/v1')->middleware(['api'])->group($routeFile);
```

Por tanto la ruta efectiva del login es `POST /api/v1/auth/login`, y un listado de clientes es
`GET /api/v1/clients`. El login lleva su propio rate limiter:

```php
Route::post('auth/login', [CommanderAuthController::class, 'login'])->middleware('throttle:login');
```

El limitador `login` está definido en `CoreRouteServiceProvider::configureRateLimiting()` como
`CORE_LOGIN_RATE_LIMIT` peticiones/min por IP (10 por defecto); el resto de la API va por el
limitador `api` (`CORE_API_RATE_LIMIT`, 240/min por usuario o IP).

### Flujo de autenticación

1. La SPA hace `POST /api/v1/auth/login` con `{ email, password }`.
2. `CoreAuthController::login()` valida contra `CoreUserModel`, y si las credenciales cuadran
   (`Hash::check`) emite un token personal de Sanctum y devuelve
   `{ token, token_type: "Bearer", user, token_id }`.
3. Todas las demás rutas del Commander van dentro de `Route::middleware('auth:sanctum')->group(...)`:
   exigen `Authorization: Bearer <token>`.
4. Dentro de cada controlador, `CoreBaseApiController::requirePermission()` comprueba el permiso
   Spatie correspondiente antes de tocar datos: devuelve `401` si no hay usuario y `403` si lo hay
   pero le falta el permiso.
5. `GET /api/v1/auth/me` devuelve el usuario con `all_permissions` y `role_names`, que la SPA usa
   para pintar/ocultar acciones según permisos.

### Mapa de rutas de la Fase 1

Todas bajo `api/v1` y, salvo el login, bajo `auth:sanctum`:

```
POST   auth/login                 (throttle:login)
GET    auth/me
POST   auth/logout
apiResource users
apiResource clients
apiResource deployments           (+ acciones de control de la Fase 2)
apiResource subscriptions
apiResource invoices
apiResource quotas      (only index, show, update)
apiResource activity    (only index, show)
```

## Verificación

Esta fase se verifica sin necesidad de ningún despliegue remoto (eso llega en la Fase 4). El
arranque típico, ejecutando el paquete `Commander` sobre `https://localhost:8443`:

1. **Migrar y sembrar:** correr las migraciones del paquete y `CommanderDatabaseSeeder` (más el
   `CoreDatabaseSeeder`, que crea el admin). Tras esto las seis tablas `commander_*` quedan pobladas con
   el dataset de `fixtures.ts` y existe el usuario `admin@snake.com`.

2. **Login:** `POST /api/v1/auth/login` con `{"email":"admin@snake.com","password":"secret1234"}`
   debe devolver `200` y un `token` Bearer.

3. **Listados protegidos:** con el token, `GET /api/v1/clients` y `GET /api/v1/deployments` deben
   devolver `200` con el dataset sembrado. Sin token deben devolver `401`.

4. **Forma del JSON del despliegue:** en `GET /api/v1/deployments` cada fila debe traer el objeto
   `state` anidado (`{status, reason, message, grace_until, changed_by, changed_at}`) y el campo
   `control_secret_suffix` (4 caracteres), y **no** debe aparecer `control_secret` ni las columnas
   planas de estado. Esto confirma el contrato con `types.ts`.

5. **Permisos:** `GET /api/v1/auth/me` debe listar en `all_permissions` los permisos de los seis
   recursos más `control deployments`, y `role_names` debe incluir `admin`.

Las pruebas automatizadas que cubren este recorrido viven en `src/tests/Feature/CommanderApiTest.php` (y,
para las acciones de control de la Fase 2, `src/tests/Feature/CommanderControlTest.php`), que resuelven el
admin con `CoreUserModel::where('email', 'admin@snake.com')->firstOrFail()`.

## Gotchas

- **El dataset del seeder está acoplado a `fixtures.ts`.** El objetivo explícito es que, una vez
  cableada la SPA (Fase 3), el panel muestre exactamente los mismos datos contra la base de datos
  real. Si cambias un id, un slug o un valor en `fixtures.ts` sin reflejarlo en `CommanderDatabaseSeeder`,
  la demo dejará de cuadrar. El seeder usa `updateOrCreate` por id, así que es idempotente y se
  puede re-correr sin duplicar.

- **`control_secret` está oculto y cifrado a la vez.** Está en `$hidden` (no sale en JSON) y casteado
  como `encrypted` (cifrado en reposo). Para mostrarlo en la UI usa `control_secret_suffix`; nunca
  intentes serializar el secreto completo. Ojo: el cast `encrypted` depende de `APP_KEY`; si rotas la
  clave de la app sin re-cifrar, los secretos sembrados dejarán de descifrarse.

- **El `state` anidado oculta las columnas planas.** Como `status`, `reason`, etc. están en
  `$hidden`, no las leas del JSON de la API: léelas de `deployment.state.*`. En base de datos siguen
  siendo columnas planas e indexadas (hay índices en `status`, `client_id`, `subscription_id`), que
  es lo que conviene para filtrar y para que las fases siguientes las actualicen directamente.

- **No confundas `update deployments` con `control deployments`.** Editar metadatos del despliegue
  usa el permiso CRUD; suspender/gracia/reanudar/sincronizar/rotar usan el permiso de control. El
  seeder le da ambos al rol `admin`, pero la separación importa si más adelante se definen roles de
  menor privilegio.

- **La séptima tabla/modelo no es de esta fase.** `commander_usage_snapshots` / `UsageSnapshotModel`
  existen ya en el código y el seeder los rellena, pero son de la Fase 6 (caché de uso de
  plataforma). No los cuentes entre las "seis" tablas del registro base.

- **El admin lo crea el Core, no el Commander.** Si solo corres `CommanderDatabaseSeeder` tendrás permisos y rol
  `admin`, pero no el usuario: hay que correr también `CoreDatabaseSeeder` para tener
  `admin@snake.com` / `secret1234`.

## Ficheros tocados

Migraciones (en `src/packages/Commander/database/migrations/`):

- `2026_06_15_000001_create_commander_clients_table.php`
- `2026_06_15_000002_create_commander_deployments_table.php`
- `2026_06_15_000003_create_commander_subscriptions_table.php`
- `2026_06_15_000004_create_commander_invoices_table.php`
- `2026_06_15_000005_create_commander_quota_metrics_table.php`
- `2026_06_15_000006_create_commander_activity_events_table.php`

Modelos (en `src/packages/Commander/app/Models/`):

- `ClientModel.php`, `DeploymentModel.php`, `SubscriptionModel.php`, `InvoiceModel.php`,
  `QuotaModel.php`, `ActivityEventModel.php`

Controladores (en `src/packages/Commander/app/Http/Controllers/`):

- `CommanderClientsController.php`, `CommanderDeploymentsController.php`, `CommanderSubscriptionsController.php`,
  `CommanderInvoicesController.php`, `CommanderQuotasController.php`, `CommanderActivityController.php`
- `CommanderAuthController.php` y `CommanderUsersController.php` (delegan en el Core)

Otros del paquete:

- `src/packages/Commander/routes/api.php`
- `src/packages/Commander/database/seeders/CommanderDatabaseSeeder.php`
- `src/packages/Commander/app/Providers/CommanderServiceProvider.php`
- `src/packages/Commander/config/commander.php`, `config/sanctum.php`, `config/scramble.php`

Reutilizados del Core (sin cambios en esta fase, pero de los que depende):

- `src/packages/Core/app/Http/Controllers/CoreAuthController.php`,
  `CoreBaseApiController.php`, `CoreUsersController.php`
- `src/packages/Core/app/Models/CoreUserModel.php`
- `src/packages/Core/database/seeders/CoreDatabaseSeeder.php` (admin `admin@snake.com` / `secret1234`)
- `src/packages/Core/app/Providers/CoreRouteServiceProvider.php` (prefijo `api/v1`, limiters `api`/`login`)
