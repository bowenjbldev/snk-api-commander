# Snake Laravel API

API modular sobre Laravel 13. **Un solo despliegue (una app) sirve TODOS los clientes y paquetes**. El **subdominio es el CLIENTE** (`{cliente}.dominio.com`) y el **slug del paquete va en el path** (`{cliente}.dominio.com/api/v1/{paquete}/...`), resueltos en cada request. El **código de cada paquete es per-cliente y vive ANIDADO** en `packages/<Cliente>/<Paquete>` (p.ej. `packages/Acme/Shop`); un cliente puede tener varios paquetes y dos clientes pueden tener el mismo paquete lógico (`shop`) con código y BD distintos. Multi-tenant por **base de datos**: una BD commander (`commander`) con el registro + el panel de operador + el metering, y **una BD propia por cada par (cliente, paquete)** —`{cliente}_{paquete}`, p.ej. `acme_shop`— con sus tablas de negocio + su base Laravel (users/roles/tokens). El Commander da de alta clientes y paquetes y los provisiona en un paso (crea la BD incluida), sin scripts de host.

> **Modelo centralizado, cliente-primero, paquetes anidados.** El subdominio es el **cliente**; el paquete va en el path. Una sola instancia carga Core + Commander + todos los paquetes de cliente (`packages/*/*`); en cada request se resuelve el cliente por el `Host` y el paquete por el primer segmento de ruta, y se conmuta a la BD de ese par (cliente, paquete). La instancia viva de cada par es **una fila en `commander_packages`** (relación 1:1, sin tabla de deployments aparte).

## Documentación

El proyecto se documenta en tres ficheros, cada uno con un alcance distinto:

| Documento | Alcance |
|-----------|---------|
| **`README.md`** (este) | El backend Laravel: arquitectura multi-tenant, resolución de cliente por subdominio + paquete por path, paquetes anidados, provisioning, migraciones, comandos Artisan, despliegue y entorno local. |
| [`commander/README.md`](commander/README.md) | El **frontend** del Commander (SPA React + Carbon): cómo correr/testear/desplegar, el flujo de control plane, las tablas `commander_*`, auth y la arquitectura del código. |
| [`design/README.md`](design/README.md) | El **sistema de diseño** del Commander: reglas visuales, tokens de tema (g100/g10), semántica de estado, páginas implementadas, flujos UX y correspondencia con la API. |

> Backend del Commander: paquete `src/packages/Commander/` (controllers, rutas, migraciones `commander_*`). Frontend: `commander/`. Diseño: `design/`.

## Stack

- **PHP 8.3+** / **Laravel 13**
- **Laravel Sanctum** — Autenticacion Bearer tokens
- **Spatie Laravel Permission** — Roles y permisos
- **Scramble** — Documentacion OpenAPI auto-generada con RapiDoc UI
- **Docker** — PHP-FPM (Alpine) + Nginx (vhost wildcard) + MariaDB
- **Commander** — SPA React + IBM Carbon (en `commander/`, fuera de `src/`), panel de operador en `commander.<BASE_DOMAIN>` **conectado a la API real** del paquete `Commander` (Bearer Sanctum). Diseño en [`design/README.md`](design/README.md), app en [`commander/README.md`](commander/README.md).

## Puesta en marcha desde cero

Solo necesitas Docker (composer, PHP y migraciones corren dentro del contenedor):

```bash
git clone <repo> && cd snake-laravel-api
cp .env.example .env          # puertos, dominios (ajusta si quieres)
cp src/.env.example src/.env  # BD commander + root multi-BD (centralizado)
bash scripts/setup-ssl.sh     # cert wildcard *.BASE_DOMAIN con mkcert (se auto-descarga si falta)
docker compose up -d          # build + composer install + APP_KEY + migrate commander, automatico
curl -k https://localhost:8443/api/v1   # health check (Core, agnostico de paquete)
```

El entrypoint migra el deploy **commander** (`core:migrate`, sin `--package`): registro, metering, panel del operador y la base Laravel. Las BD de cada paquete las crea el provisioning. Para que el panel de operador tenga un admin, siémbralo una vez:

```bash
docker compose exec app php artisan core:seed --force   # commander; admin@snake.com / secret1234
```

Luego, desde el Commander (`https://commander.<BASE_DOMAIN>`), creas un plan y das de alta un paquete para un cliente: eso lo provisiona en vivo (ver "Provisioning").

## Estructura del proyecto

```
src/
├── app/                        # App base Laravel (minima)
├── bootstrap/
│   ├── app.php
│   └── providers.php           # Registra CoreServiceProvider
├── config/                     # Config estandar Laravel (database, etc.)
├── packages/
│   ├── Core/                   # Paquete base (siempre activo)
│   │   ├── app/
│   │   │   ├── Console/        # core:migrate, core:seed, make:package, 35+ make:*
│   │   │   ├── Http/
│   │   │   │   ├── Controllers/
│   │   │   │   │   ├── CoreBaseApiController.php   # CRUD abstracto con permisos
│   │   │   │   │   ├── CoreAuthController.php      # Login/Logout/Me (scoped por (cliente,paquete))
│   │   │   │   │   ├── CoreHealthController.php    # Health check
│   │   │   │   │   └── CoreUsersController.php     # Users REST (scoped por (cliente,paquete))
│   │   │   │   └── Middleware/
│   │   │   │       ├── CoreResolvePackageFromHostMiddleware.php  # cliente(host)+slug(path) -> paquete activo + BD
│   │   │   │       ├── CoreMeteringMiddleware.php  # registra cada request (por paquete)
│   │   │   │       └── CoreEnsureActiveMiddleware.php  # kill-switch 402 (por paquete)
│   │   │   ├── Models/
│   │   │   │   └── CoreUserModel.php               # User (en la BD del paquete), roles y tokens
│   │   │   │                                       # (el registro vive en Commander: CommanderPackageModel)
│   │   │   ├── Providers/                          # Service providers (orquestacion, rutas, consola, docs)
│   │   │   ├── Services/                           # estado, metering, cuotas
│   │   │   └── Support/                            # descubrimiento, selector, paquete activo
│   │   ├── config/             # core, auth, cors, permission, sanctum, scramble
│   │   ├── database/migrations + seeders
│   │   └── routes/api.php       # Core: health check
│   ├── Commander/              # Panel de operador (clientes, planes, provisioning, estado)
│   ├── Acme/                   # Paraguas del cliente "acme" (NO es un paquete)
│   │   ├── Shop/               # AcmeShop: acme.dom/api/v1/shop, BD acme_shop
│   │   └── Blog/               # AcmeBlog
│   └── Initech/Crm ...         # InitechCrm: initech.dom/api/v1/crm, BD initech_crm
├── routes/web.php
└── tests/
```

> **Nivel 1 = SOLO framework** (`Core`, `Commander`). Cualquier otra carpeta de nivel 1 es un **paraguas de cliente** (`Acme`, `Initech`…) y sus hijos (`Acme/Shop`) son paquetes. Clases con prefijo cliente+paquete (`AcmeShopAuthController`), namespace `Packages\Acme\Shop\`. Se crean con `make:package <Cliente> <Paquete>`.

Fuera de `src/`, en la raíz del repo:

```
commander/   # SPA React + Carbon del panel de operador (Vite). Consume la API del paquete Commander.
design/      # Sistema de diseño del Commander (reglas, páginas, flujos) — un solo README.md.
```

El backend del Commander es el paquete `src/packages/Commander/` (controllers, rutas, migraciones `commander_*`); el frontend es `commander/`, que llama a esa API.

## Arquitectura

### Concepto general

Monolito modular centralizado, multi-tenant por BD: **una app sirve todos los clientes y paquetes; cada par (cliente, paquete) tiene su propia base de datos**. Toda la logica de negocio vive en `packages/<Cliente>/<Paquete>`, no en `app/`.

- **Core** es el paquete base, siempre activo. Provee infraestructura: autenticacion, CRUD base, permisos, descubrimiento automatico, scaffolding, metering, cuotas y kill-switch.
- El **subdominio es el cliente** (`{cliente}.<BASE_DOMAIN>`) y el **slug del paquete va en el path** (`/api/v1/{slug}/...`). El paquete activo (ese par cliente x paquete) lo resuelve `CoreResolvePackageFromHostMiddleware`: el cliente por el `Host`, el slug por el argumento de middleware (lo conoce el registro de rutas).
- **Una BD por par (cliente, paquete)** (aislamiento fisico). Conexiones: `commander` (fija, BD `commander`) + `tenant` (BD dinamica). El default es `commander`; en un subdominio de cliente, el resolutor conmuta la conexion default a la BD del paquete (`commander_packages.database`, p.ej. `acme_shop`). Seguro bajo PHP-FPM (share-nothing). Las tablas commander (`commander_*`) van pinneadas a `commander`; el metering se indexa por `package_id`. Los `users` NO llevan `package_id`: el aislamiento es por BD.

### Resolucion del paquete activo

Al arrancar, `CoreServiceProvider` descubre y carga **Core + Commander + TODOS los paquetes de cliente** (`packages/*/*`: providers, configs namespaced `cliente.paquete.*`, rutas). El paquete activo NO se elige en boot: lo resuelve `CoreResolvePackageFromHostMiddleware` en cada request —cliente por el `Host` (`commander_clients.slug`, que ES el host) + slug del paquete por su argumento de middleware → la fila viva de `commander_packages` con ese `(client_id, slug)`— y conmuta la conexion `tenant` a su BD.

Las rutas de cada paquete de cliente se montan en su **subdominio concreto**: `Route::domain('{cliente}.<BASE_DOMAIN>')->prefix('api/v1/{slug}')`, derivado de la carpeta `packages/<Cliente>/<Paquete>` en boot (disk-only → `route:cache` intacto). Dos clientes con el mismo slug montan en subdominios distintos → cero colision. Core (health/docs) globales; Commander en `commander.<BASE_DOMAIN>`. Un par (cliente, slug) solo "esta vivo" si su fila en `commander_packages` esta **provisionada** (`provisioned_at` no nulo); si no, `404`. El override de config global por paquete ocurre **dentro del resolutor** (namespace `{cliente}.{slug}`).

### `commander_packages`: la instancia viva 1:1 (sin tabla de deployments)

**Una sola tabla**, en la BD `commander`, con **una fila por par (cliente, paquete) provisionado**: la instancia viva. Lleva `client_id` (FK clients) + `slug` (del path) + `name` (StudlyCase del paquete) + **`database`** (su BD propia `{cliente}_{slug}`) + `subscription_id` (puntero) + **estado runtime** (`active|grace|suspended` + reason/message/grace_until/changed_at) + KPIs (requests_24h, quota_health, last_seen) + `provisioned_at`. **`UNIQUE(client_id, slug)`** (un cliente, un "shop"); **no hay columna `subdomain`** —el subdominio ES `clients.slug`, se llega por la relacion. `provisioned_at != null` marca la instancia viva; el resolutor la busca por `(client_id via host, slug)`. El de-provision es **soft**: anula lo operativo (incluida `slug`/`database`/`client_id`/`provisioned_at`) y conserva la fila + el historial de billing. El metering/billing/cuotas usan `package_id` (FK a `commander_packages`).

### Identidad por paquete

Aislamiento **fisico**: cada par (cliente, paquete) tiene su propia tabla `users` + `personal_access_tokens` en su propia BD; el operador, las suyas en la commander. `admin@snake.com` existe en cada BD por separado. Un token de un paquete simplemente **no existe** en la BD de otro → 401 automatico, sin codigo de comprobacion. No hay `users.package_id` ni middleware de aislamiento: lo da la separacion de BD.

### Despliegue (IONOS Ubuntu 24.04, sin Plesk)

Un solo despliegue sirve todo:

```
*.dominio.com  ->  A-record wildcard  ->  el unico servidor
   {cliente}.dominio.com -> el cliente; el paquete va en el path:
       acme.dominio.com/api/v1/shop/...   -> paquete Acme/Shop (BD acme_shop)
       acme.dominio.com/api/v1/blog/...   -> paquete Acme/Blog (BD acme_blog)
   commander.dominio.com -> panel de operador
   api.dominio.com       -> health/docs (Core, agnostico)
```

Deploy / actualizar:

```bash
git pull && composer install --no-dev -o
php artisan core:migrate --force   # commander (sin --package; las BD de paquete las crea el provisioning)
php artisan config:cache route:cache   # route:cache hornea BASE_DOMAIN; re-cachear en cada deploy
```

- **DNS**: A-record wildcard `*.dominio.com` -> IP del servidor.
- **TLS**: cert wildcard `*.dominio.com` (Let's Encrypt **DNS-01** — requerido para wildcards; hook de la API DNS de IONOS para certbot).
- **nginx**: un vhost wildcard `server_name *.dominio.com` -> un upstream FPM. Un paquete nuevo: vivo tras `git pull` (sus rutas se registran en boot) + provisioning (crea la fila + migra/siembra); cero paso de infra.

### Local con subdominios (Docker + WSL2)

Una sola stack Docker (app + nginx + db + commander-web) replica produccion 1:1. nginx sirve un vhost wildcard `*.<BASE_DOMAIN>`.

**Requisitos:**

1. `BASE_DOMAIN` en el `.env` raiz (default `snake.test`; evita `.local`, colisiona con mDNS).
2. Certificado wildcard: `bash scripts/setup-ssl.sh` (genera `*.BASE_DOMAIN` con mkcert; auto-descarga si falta; en WSL importa la CA al store de Windows con una sola ventana UAC para el candado verde).
3. **Resolver `*.<BASE_DOMAIN>` -> 127.0.0.1** para que el navegador abra los subdominios. Ejecuta `bash scripts/dns-wildcard.sh` (configura dnsmasq donde existe; si no, imprime la config de Acrylic/hosts). Opciones:
   - **Wildcard (recomendado, 1:1 con prod):** dnsmasq (Linux/WSL) o Acrylic DNS (Windows) con `address=/snake.test/127.0.0.1`.
   - **Simple:** anadir cada subdominio al hosts de Windows (`C:\Windows\System32\drivers\etc\hosts`): `127.0.0.1  api.snake.test commander.snake.test acme.snake.test initech.snake.test ...` (uno por cliente; el paquete va en el path).

Dentro de WSL, hasta tener DNS, prueba con `curl --resolve <sub>.snake.test:8443:127.0.0.1 ...`.

### Override de configuracion

- Core se aplica primero. **Solo Core puede escribir claves globales de Laravel** (`auth`, `cors`, `scramble`...) en boot: con todos los paquetes cargados a la vez, permitir overrides globales desde paquetes de negocio causaria colisiones no deterministas.
- Las configs de los paquetes de negocio van **siempre namespaced**: `config('shop.archivo.key')`.

#### Override de una clave GLOBAL por paquete (en runtime)

Si un paquete necesita un valor distinto en una clave **global** del framework, crea un config con el **nombre de esa clave del skeleton** (`app`, `session`, `cache`, `mail`...) y declara solo lo que cambia (se fusiona con `array_replace_recursive`). Para overridear `app.name` en Acme/Shop:

```php
// packages/Acme/Shop/config/app.php
return ['name' => 'Acme Shop'];
```

El discovery lo carga namespaced por `{cliente}.{slug}` (`acme.shop.app`); el **resolutor** (`CoreResolvePackageFromHostMiddleware`, que ya conoce cliente+slug) **fusiona** ese config sobre la clave global `app` **solo en los requests de ese paquete** (FPM share-nothing: muere al acabar el request). API stateless (Sanctum bearer): el override en el resolutor basta (ya no hay middleware global pre-routing).

### Rutas

- Cada paquete de cliente bajo `Route::domain('{cliente}.<BASE_DOMAIN>')->prefix('api/v1/{slug}')`, con el resolutor recibiendo el slug. Las de Core (health) son globales (cualquier host). El Commander en `commander.<BASE_DOMAIN>`.

## Rutas API

| Metodo | Ruta | Descripcion | Auth |
|--------|------|-------------|------|
| `GET` | `/api/v1/` | Health check (Core, cualquier host) | - |
| `GET` | `/api/v1/docs` | Documentacion OpenAPI (RapiDoc UI) | - |
| `POST` | `{cliente}.dom/api/v1/{paquete}/auth/login` | Login del paquete (cliente x paquete) (Bearer token) | - |
| `POST` | `{cliente}.dom/api/v1/{paquete}/auth/logout` | Logout (revoca token) | Sanctum |
| `GET` | `{cliente}.dom/api/v1/{paquete}/auth/me` | Usuario actual con roles y permisos | Sanctum |
| `*` | `{cliente}.dom/api/v1/{paquete}/users` | CRUD usuarios (scoped al paquete) | Sanctum |
| `*` | `{cliente}.dom/api/v1/{paquete}/{...}` | Rutas de negocio del paquete | Sanctum |

## Autenticacion

Sanctum en modo **Bearer token exclusivamente**. No hay modo stateful ni sesiones.

**Flujo de login:**

1. `POST {cliente}.dom/api/v1/{paquete}/auth/login` con `email` y `password`
2. Se busca el usuario en la **BD del par (cliente, paquete)** (la conexion ya conmuto); el operador, en la commander
3. Si las credenciales son validas, se genera un token Sanctum (en esa misma BD)
4. Se devuelve `{ token, token_type: "Bearer", user, token_id }`

Un token solo vale en su paquete: en otra BD no existe → 401. El panel de operador (`commander.<BASE_DOMAIN>`) corre sobre la commander, asi que un token de tenant tampoco entra ahi.

## Descubrimiento automatico

`CoreServiceProvider` descubre y carga los recursos de Core + de TODOS los paquetes:

El "módulo" es un namespace derivado de la ruta: `Core`/`Commander` (framework, 1 nivel) o `Cliente\Paquete` (negocio, 2 niveles). Cada recurso se globea en ambos niveles:

| Recurso | Patron glob | Accion |
|---------|-------------|--------|
| Providers | `packages/*/app/Providers/*` + `packages/*/*/app/Providers/*` | Registra en el container |
| Configs | `packages/*/config/*` + `packages/*/*/config/*` | Namespaced (`acme.shop.*`; globales solo de Core) |
| Rutas | `packages/*/routes/api.php` + `packages/*/*/routes/api.php` | `Route::domain('{cliente}')->prefix('api/v1/{slug}')` |
| Migraciones | `…/database/migrations/` (1 y 2 niveles) | Carga paths |
| Seeders | `…/database/seeders/*DatabaseSeeder.php` (1 y 2 niveles) | Ejecuta con `core:seed` |

## Provisioning (dar de alta un paquete)

El **codigo** per-cliente viaja por git (lo programas en local con `make:package <Cliente> <Paquete>` y haces deploy). El Commander instancia un paquete **existente en disco** para un cliente, en un paso, in-process:

`POST /provisioning` (en `commander.<BASE_DOMAIN>`, con `plan_id` + `client_id` existente **o** un objeto `client` nuevo, cuyo `slug` ES su host):

1. Crea/resuelve cliente + suscripcion. El `slug` del cliente forma la carpeta de código y la BD.
2. Valida que existe el código `packages/<Cliente>/<Paquete>` (si no, 422).
3. Crea la fila `commander_packages` (la instancia viva `(client_id, slug)`).
4. **Crea la BD del paquete** (`CREATE DATABASE {cliente}_{slug}`) y corre `core:migrate --client=<cliente> --package=<X>` (base Laravel + negocio) + `core:seed --client=<cliente> --package=<X>` (admin) **en esa BD**.
5. Activa + escribe las cuotas del plan sobre `package_id` (en la commander).

El paquete queda vivo al terminar (`{cliente}.dom/api/v1/{slug}/...`). De-provisionar (`DELETE /packages/{name}`) hace `DROP DATABASE` + de-provision **soft** en la commander (conserva la fila + el historial de billing). Un plan oferta un tipo lógico (`Shop`); es provisionable si ese código existe bajo **algún** cliente (`packages/*/Shop`).

## Comandos Artisan

### Gestion de paquetes (desarrollo)

```bash
php artisan make:package Acme Shop            # scaffold anidado packages/Acme/Shop (clases AcmeShop*)
php artisan remove:package NombrePaquete      # eliminar
composer dump-autoload
```

### Base de datos

```bash
php artisan core:migrate                              # COMMANDER: registro + metering + operador + base
php artisan core:seed                                 # COMMANDER: admin + permisos del operador
php artisan core:migrate --client=acme --package=Shop # TENANT: base + negocio (packages/Acme/Shop, BD acme_shop)
php artisan core:seed    --client=acme --package=Shop # TENANT: admin + seeders del paquete (en su BD)
php artisan core:migrate --rollback                   # rollback de la commander
# Regla: --client + --package juntos => tenant (packages/Studly(client)/Package, BD snake(client)_snake(package));
#        sin ninguno => commander. (normalmente las BD las gestiona el provisioning del Commander, no a mano)
```

### Control plane (estado, metering, cuotas)

Todo in-process (Commander y deploys viven en la misma app/BD).

```bash
php artisan commander:status                       # estado + cuotas (por paquete activo)
php artisan commander:usage:rollup --prune         # agrega request_logs en rollups hora/dia (por package)
php artisan commander:quota:measure                # mide disco/requests/usuarios por package y aplica on_exceed
```

- Estados por paquete: `active` | `grace` (headers `X-Package-Status`) | `suspended` (402 en rutas de negocio; health y docs siguen respondiendo).
- Metering por request en `commander_request_logs` (middleware terminable, cero latencia con FPM, columna `package_id`) + rollups en `commander_usage_rollups`.
- El Commander cambia el estado de un paquete escribiendo `commander_packages` directamente: `POST /packages/{name}/suspend|grace|resume`.

### Simulación de datos (`scripts/simulate.sh`)

Script end-to-end para poblar el Commander en local (corre contra el stack Docker). Subcomandos:

```bash
bash scripts/simulate.sh                  # flujo completo: limpia BD → scaffold → provisiona →
                                          # invoices → trafico (HTTP) → pipeline → reporte
bash scripts/simulate.sh traffic <cliente> <slug> [n] # SOLO trafico HTTP contra un (cliente, paquete)
bash scripts/simulate.sh invoices         # SOLO genera invoices (paid/pending/overdue)
bash scripts/simulate.sh pipeline         # SOLO rollup + quota:measure + refresh de KPIs
bash scripts/simulate.sh report           # SOLO el reporte de estado
```

**Volumen masivo** — para llenar las gráficas (Requests per hour, Error rate) con datos repartidos
en las últimas 48h sin esperar tráfico real:

```bash
bash scripts/simulate.sh volume [filas_por_package]   # default 100000; p. ej. volume 200000
```

- Inserta los `commander_request_logs` con **un solo `INSERT ... SELECT FROM seq_1_to_N`** por package
  (motor **SEQUENCE** de MariaDB): set-based, sin loops ni HTTP → órdenes de magnitud más rápido.
- `created_at` repartido uniforme en 48h (rellena los ~48 buckets horarios); escala por package
  (1.0 / 0.6 / 0.3) y mezcla de status realista (~95% 2xx · 4% 4xx · 1% 5xx).
- **Resetea** `commander_usage_rollups` y recomputa todos los buckets (`commander:usage:rollup` es
  incremental desde el último bucket; sin el reset solo agregaría las horas recientes).
- El `INSERT` es rapidísimo incluso con cientos de miles; el cuello de botella a alto volumen es el
  rollup en PHP (recorre los buckets). Cada `volume` **acumula** sobre lo existente.

Overrides por entorno: `ADMIN_EMAIL` / `ADMIN_PASSWORD` (operador), `SCENARIO=shop|blog|generic`
(en `traffic`), `DOCKER_COMPOSE` (runner). La spec de packages se edita en el array `PACKAGES[]`.

### Generadores package-aware

`make:package <Cliente> <Paquete>` scaffolda el paquete anidado. Los demás `make:*` (model,
controller, migration, seeder, test, factory…) aceptan **`--client` + `--package`** y generan en
`packages/<Cliente>/<Paquete>/...` con namespace `Packages\<Cliente>\<Paquete>\` y prefijo de clase
N2 `<Cliente><Paquete>`:

```bash
php artisan make:model Product --client=acme --package=Shop      # Packages\Acme\Shop\Models\AcmeShopProductModel
php artisan make:migration create_products_table --client=acme --package=Shop
php artisan make:controller ProductController --client=acme --package=Shop
```

## CoreBaseApiController

Controlador abstracto con CRUD completo:

- Verificacion de permisos por accion (`indexPermission`, `storePermission`, etc.)
- Carga de relaciones via `?with=relation1,relation2`
- Validacion con reglas interpolables (`unique:users,email,{id}`)
- Form Requests custom (`storeRequestClass`, `updateRequestClass`)

```php
class MiController extends CoreBaseApiController
{
    protected string $modelClass = MiModelo::class;
    protected string $indexPermission = 'index mi-recurso';
    protected array $allowedWith = ['relacion'];
    protected array $validationRulesStore = ['campo' => 'required|string'];
}
```

## Convencion de un paquete

Paquetes de negocio **anidados por cliente** `packages/<Cliente>/<Paquete>`, namespace
`Packages\<Cliente>\<Paquete>\`. **Cada clase lleva el prefijo cliente+paquete (N2)**: en
`packages/Acme/Shop` → `AcmeShopAuthController`, `AcmeShopServiceProvider`,
`AcmeShopDatabaseSeeder`, etc. Asi el nombre del fichero identifica cliente+paquete sin
ambiguedad en el editor (framework: Core → `Core*`, Commander → `Commander*`). `make:package
<Cliente> <Paquete>` scaffolda con el prefijo. Un paquete puede `extends` el de otro cliente
(decisión manual del programador).

```
packages/Acme/Shop/
├── app/
│   ├── Providers/AcmeShopServiceProvider.php
│   ├── Http/Controllers/
│   │   ├── AcmeShopAuthController.php      # Extiende CoreAuthController
│   │   └── AcmeShopUsersController.php     # Extiende CoreUsersController
│   ├── Models/  Services/  Jobs/
├── config/scramble.php   # (opcional) docs
├── database/migrations + seeders/AcmeShopDatabaseSeeder.php
└── routes/api.php        # auth (heredada) + negocio
```

## Migraciones

- **Core** (`packages/Core/database/migrations/`, primer nivel): solo la **base Laravel**, que corre en la commander (para el operador) Y en CADA BD de paquete: roles/permisos (Spatie), schema base (`users`, `sessions`, `cache`, `jobs`...), tokens Sanctum.
- **Commander** (`packages/Commander/database/migrations/`, tablas `commander_*`): la **tabla única `commander_packages`** —la instancia viva 1:1 por par (cliente, paquete): `client_id` + `slug` + `name` + `database` + suscripción + estado + KPIs + `provisioned_at`, con `UNIQUE(client_id, slug)` (sin columna `subdomain`); **no existe tabla `commander_deployments`**— + el metering (`commander_request_logs`, `commander_usage_rollups`, por `package_id`) + el resto del panel (`commander_clients` —su `slug` ES el host del cliente—, `commander_subscriptions`, `commander_invoices`, `commander_quotas` —tabla única: el panel edita límites/política y el motor mide/aplica sobre la misma fila—, `commander_activity_events`...). Solo en la BD `commander`.
- **Negocio**: `packages/<Cliente>/<Paquete>/database/migrations/` (solo en la BD del paquete).

`core:migrate` (sin args) corre base + Commander en la BD `commander`; el provisioning corre `core:migrate --client=<cliente> --package=<X>` (base + negocio) en la BD del paquete. Regla: `--client` + `--package` juntos ⟹ tenant (BD `{cliente}_{paquete}`); sin ninguno ⟹ commander.

`core:migrate` (sin `--package`) corre la base + las del Commander en la BD `commander`; el provisioning corre `core:migrate --package=<X>` (base + negocio) en la BD del paquete. Regla: `--package` ⟹ tenant (BD = snake(package)); sin `--package` ⟹ commander.

### Seeder por defecto

- Rol `admin`, permisos sobre `users`, usuario admin `admin@snake.com` / `secret1234` (uno por BD: el del operador en la commander, el de cada paquete en su BD).

## Variables de entorno

```env
APP_NAME=SnakeLaravelApi
APP_ENV=local
APP_DEBUG=true
APP_URL=https://api.snake.test

BASE_DOMAIN=snake.test

# Multi-tenant: default=commander; cada subdominio conmuta a su BD (tenant).
# root (sin password) es necesario para acceder a todas las BD + CREATE DATABASE (SOLO local).
DB_CONNECTION=commander
DB_HOST=db
DB_PORT=3306
COMMANDER_DB_DATABASE=commander
DB_USERNAME=root
DB_PASSWORD=
# En tests: COMMANDER_DB_DRIVER=sqlite / TENANT_DB_DRIVER=sqlite (sin MariaDB).
```

## Docker

```bash
docker compose up -d
```

Servicios:

| Servicio | Imagen | Puerto |
|----------|--------|--------|
| `app` | `php:8.3-fpm-alpine` (un solo contenedor, sirve todos los paquetes) | 9000 (interno) |
| `nginx` | `nginx:alpine` (vhost wildcard `*.${BASE_DOMAIN}`) | `${HTTP_PORT:-80}:80` y `${HTTPS_PORT:-443}:443` (8080/8443 en local) |
| `db` | `mariadb:latest` (crea la BD commander `commander` en init; las de paquete las crea el provisioning) | `${DB_PORT:-3306}:3306` (3307 en local) |
| `commander-web` | `node:24-alpine` (SPA en `commander.<BASE_DOMAIN>`) | 80 (interno) |

El entrypoint: crea `.env` si falta, `composer install`, `APP_KEY`, fija permisos, y migra la **commander** (`core:migrate`, sin `--package`). Luego PHP-FPM.

## Desarrollo local

```bash
cd src
composer setup   # install + key + migrate
composer dev     # servidor de desarrollo
composer test    # tests
```

## Documentacion API

`{cliente}.dom/api/v1/{paquete}/docs` (RapiDoc UI, Scramble) y la spec en `/api/v1/docs.json` (Core, global).
