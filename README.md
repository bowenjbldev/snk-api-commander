# Snake Laravel API

API modular sobre Laravel 13. **Un solo despliegue (una app) sirve TODOS los paquetes de negocio**, resueltos por subdominio en cada request: `{paquete}.dominio.com`. Multi-tenant por **base de datos**: una BD commander (`commander`) con el registro + el panel de operador + el metering, y **una BD propia por paquete** (`shop`, `blog`...) con sus tablas de negocio + su base Laravel (users/roles/tokens). El Commander da de alta clientes y paquetes y los provisiona en un paso (crea la BD incluida), sin scripts de host.

> **Modelo centralizado.** Anteriormente cada paquete/cliente era un despliegue aislado con su propia BD (orquestado por un script de host). Ahora es **una app centralizada**: una sola instancia carga todos los paquetes y el paquete activo se resuelve por el `Host` de cada request (no por despliegue), conmutando a la BD de ese paquete.

## Stack

- **PHP 8.3+** / **Laravel 13**
- **Laravel Sanctum** — Autenticacion Bearer tokens
- **Spatie Laravel Permission** — Roles y permisos
- **Scramble** — Documentacion OpenAPI auto-generada con RapiDoc UI
- **Docker** — PHP-FPM (Alpine) + Nginx (vhost wildcard) + MariaDB
- **Commander** — SPA React (Carbon) operador en `commander.<BASE_DOMAIN>`

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
│   │   │   │   │   ├── CoreAuthController.php      # Login/Logout/Me (scoped por paquete)
│   │   │   │   │   ├── CoreHealthController.php    # Health check
│   │   │   │   │   └── CoreUsersController.php     # Users REST (scoped por paquete)
│   │   │   │   └── Middleware/
│   │   │   │       ├── CoreResolvePackageFromHostMiddleware.php  # subdominio -> paquete activo
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
│   └── Shop / Blog / Crm ...   # Paquetes de negocio (creados con make:package)
├── routes/web.php
└── tests/
```

## Arquitectura

### Concepto general

Monolito modular centralizado, multi-tenant por BD: **una app sirve todos los paquetes, cada uno con su propia base de datos**. Toda la logica de negocio vive en `packages/`, no en `app/`.

- **Core** es el paquete base, siempre activo. Provee infraestructura: autenticacion, CRUD base, permisos, descubrimiento automatico, scaffolding, metering, cuotas y kill-switch.
- Los paquetes de negocio se sirven cada uno en su subdominio `{paquete}.<BASE_DOMAIN>`. El paquete activo del request lo resuelve `CoreResolvePackageFromHostMiddleware` por el `Host`.
- **Una BD por paquete** (aislamiento fisico). Conexiones: `commander` (fija, BD `commander`) + `tenant` (BD dinamica). El default es `commander`; en un subdominio de negocio, `CoreResolvePackageFromHostMiddleware` conmuta la conexion default a la BD del paquete (`packages.database`). Seguro bajo PHP-FPM (cada request es share-nothing). Las tablas de la BD commander (registro, metering, operador — todas `commander_*`) van pinneadas a `commander`; el metering sigue indexado por `package_id`. Los `users` NO llevan `package_id`: el aislamiento es por BD.

### Resolucion del paquete activo

Al arrancar, `CoreServiceProvider` descubre y carga **Core + TODOS los paquetes** de `packages/` (providers, configs namespaced, rutas). El paquete activo NO se elige en boot: lo resuelve `CoreResolvePackageFromHostMiddleware` por el `Host` de cada request, y conmuta la conexion a la BD de ese paquete.

Las rutas de cada paquete se envuelven en `Route::domain('{sub}.<BASE_DOMAIN>')`; las de Core (health/docs) se registran globales (cualquier host). Un subdominio solo "esta vivo" si existe su fila en `commander_packages` (creada al provisionar); si no, `404`.

### `commander_packages`: registro de instancias

Tabla del registro, en la BD `commander`. Una fila por paquete provisionado: `name` (StudlyCase, = directorio en `packages/`), `subdomain` (snake), **`database`** (su BD propia), y el **estado runtime** (`active|grace|suspended` + reason/message/grace_until). El middleware la lee para saber a que BD conectar. El metering usa `package_id` (FK a `commander_packages`).

### Identidad por paquete

Aislamiento **fisico**: cada paquete tiene su propia tabla `users` + `personal_access_tokens` en su propia BD; el operador, las suyas en la commander. `admin@snake.com` existe en Shop y Blog por separado (BDs distintas). Un token de un paquete simplemente **no existe** en la BD de otro → 401 automatico, sin codigo de comprobacion. No hay `users.package_id` ni middleware de aislamiento: lo da la separacion de BD.

### Despliegue (IONOS Ubuntu 24.04, sin Plesk)

Un solo despliegue sirve todo:

```
*.dominio.com  ->  A-record wildcard  ->  el unico servidor
   shop.dominio.com      -> paquete Shop
   blog.dominio.com      -> paquete Blog
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
   - **Simple:** anadir cada subdominio al hosts de Windows (`C:\Windows\System32\drivers\etc\hosts`): `127.0.0.1  api.snake.test commander.snake.test shop.snake.test blog.snake.test ...` (son pocos y conocidos: uno por paquete).

Dentro de WSL, hasta tener DNS, prueba con `curl --resolve <sub>.snake.test:8443:127.0.0.1 ...`.

### Override de configuracion

- Core se aplica primero. **Solo Core puede escribir claves globales de Laravel** (`auth`, `cors`, `scramble`...) en boot: con todos los paquetes cargados a la vez, permitir overrides globales desde paquetes de negocio causaria colisiones no deterministas.
- Las configs de los paquetes de negocio van **siempre namespaced**: `config('shop.archivo.key')`.

#### Override de una clave GLOBAL por paquete (en runtime)

Si un paquete necesita un valor distinto en una clave **global** del framework, crea un config con el **nombre de esa clave del skeleton** (`app`, `session`, `cache`, `mail`...) y declara solo lo que cambia (se fusiona con `array_replace_recursive`). Para overridear `app.name`:

```php
// packages/Shop/config/app.php
return ['name' => 'Shop'];
```

El discovery lo carga namespaced (`shop.app`); `CoreApplyPackageConfigMiddleware` (global, **pre-routing**) resuelve el paquete por `Host` y **fusiona** ese config sobre la clave global `app` **solo en los requests de ese subdominio** (FPM share-nothing: muere al acabar el request). Aplica a config leida durante el request (incluida la pre-routing como `session`), no a la leida en boot/providers.

### Rutas

- Las rutas de cada paquete bajo `Route::domain('{sub}.<BASE_DOMAIN>')->prefix('api/v1')`. Las de Core (health) son globales (cualquier host). El Commander en `commander.<BASE_DOMAIN>`.

## Rutas API

| Metodo | Ruta | Descripcion | Auth |
|--------|------|-------------|------|
| `GET` | `/api/v1/` | Health check (Core, cualquier host) | - |
| `GET` | `/api/v1/docs` | Documentacion OpenAPI (RapiDoc UI) | - |
| `POST` | `{sub}.dom/api/v1/auth/login` | Login del paquete del subdominio (Bearer token) | - |
| `POST` | `{sub}.dom/api/v1/auth/logout` | Logout (revoca token) | Sanctum |
| `GET` | `{sub}.dom/api/v1/auth/me` | Usuario actual con roles y permisos | Sanctum |
| `*` | `{sub}.dom/api/v1/users` | CRUD usuarios (scoped al paquete) | Sanctum |
| `*` | `{sub}.dom/api/v1/{...}` | Rutas de negocio del paquete del subdominio | Sanctum |

## Autenticacion

Sanctum en modo **Bearer token exclusivamente**. No hay modo stateful ni sesiones.

**Flujo de login:**

1. `POST {sub}.dom/api/v1/auth/login` con `email` y `password`
2. Se busca el usuario en la **BD del subdominio** (la conexion ya conmuto); el operador, en la commander
3. Si las credenciales son validas, se genera un token Sanctum (en esa misma BD)
4. Se devuelve `{ token, token_type: "Bearer", user, token_id }`

Un token solo vale en su subdominio: en otra BD no existe → 401. El panel de operador (`commander.<BASE_DOMAIN>`) corre sobre la commander, asi que un token de tenant tampoco entra ahi.

## Descubrimiento automatico

`CoreServiceProvider` descubre y carga los recursos de Core + de TODOS los paquetes:

| Recurso | Patron glob | Accion |
|---------|-------------|--------|
| Providers | `packages/*/app/Providers/*ServiceProvider.php` | Registra en el container |
| Configs | `packages/*/config/*.php` | Namespaced (globales solo de Core) |
| Rutas | `packages/*/routes/api.php` | `Route::domain` por subdominio (Core globales) |
| Migraciones | `packages/*/database/migrations/` | Carga paths |
| Seeders | `packages/*/database/seeders/*DatabaseSeeder.php` | Ejecuta con `core:seed` |

## Provisioning (dar de alta un paquete)

El **codigo** de un paquete viaja por git (lo programas en local con `make:package` y haces deploy). El Commander instancia un paquete **existente** en un paso, in-process:

`POST /provisioning` (en `commander.<BASE_DOMAIN>`, con `plan_id` + cliente nuevo/existente):

1. Crea/resuelve cliente + suscripcion.
2. Crea la fila `commander_packages` (la instancia: enciende el subdominio).
3. **Crea la BD del paquete** (`CREATE DATABASE`) y corre `core:migrate --package=<X>` (base Laravel + negocio; BD = snake(package)) + `core:seed --package=<X>` (admin del paquete) **en esa BD**.
4. Activa + escribe las cuotas del plan (en la commander).

El subdominio queda vivo al terminar. De-provisionar (`DELETE /deployments/{id}`) hace `DROP DATABASE` + limpia la commander.

## Comandos Artisan

### Gestion de paquetes (desarrollo)

```bash
php artisan make:package NombrePaquete       # scaffold completo de un paquete nuevo (local)
php artisan remove:package NombrePaquete      # eliminar
composer dump-autoload
```

### Base de datos

```bash
php artisan core:migrate                  # COMMANDER: registro + metering + operador + base operador
php artisan core:seed                     # COMMANDER: admin + permisos del operador
php artisan core:migrate --package=Shop   # TENANT: base + negocio en la BD del paquete (BD = snake(package))
php artisan core:seed    --package=Shop   # TENANT: admin + seeders del paquete (en su BD)
php artisan core:migrate --rollback       # rollback de la commander
# Regla: --package => tenant (BD=snake(package)); sin --package => commander.
# (normalmente las BD de paquete las gestiona el provisioning del Commander, no a mano)
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
- El Commander cambia el estado de un paquete escribiendo `commander_packages` directamente: `POST /deployments/{id}/suspend|grace|resume`.

### Generadores package-aware

Todos los `make:*` soportan `--package`:

```bash
php artisan make:model MiModelo --package=Shop
php artisan make:controller MiController --package=Shop
php artisan make:migration create_products_table --package=Shop
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

**Cada clase lleva el nombre del paquete como prefijo** (StudlyCase): en `MiPaquete` →
`MiPaqueteClientModel`, `MiPaqueteAuthController`, `MiPaqueteServiceProvider`,
`MiPaqueteQuotaMeasurerService`, etc. Aplica a models, controllers, providers, services,
commands y seeders. Asi el nombre del fichero/clase identifica sin ambiguedad el paquete en
el editor (Core → `Core*`, Commander → `Commander*`). Los generadores `make:*` ya scaffolan
con el prefijo a partir de `--package`.

```
packages/MiPaquete/
├── app/
│   ├── Providers/MiPaqueteServiceProvider.php
│   ├── Http/Controllers/
│   │   ├── MiPaqueteAuthController.php     # Extiende CoreAuthController
│   │   └── MiPaqueteUsersController.php    # Extiende CoreUsersController
│   ├── Models/  Services/  Jobs/
├── config/scramble.php   # (opcional) docs
├── database/migrations + seeders/MiPaqueteDatabaseSeeder.php
└── routes/api.php        # auth (heredada) + negocio
```

## Migraciones

- **Core** (`packages/Core/database/migrations/`, primer nivel): solo la **base Laravel**, que corre en la commander (para el operador) Y en CADA BD de paquete: roles/permisos (Spatie), schema base (`users`, `sessions`, `cache`, `jobs`...), tokens Sanctum.
- **Commander** (`packages/Commander/database/migrations/`, tablas `commander_*`): el registro `commander_packages` + el metering (`commander_request_logs`, `commander_usage_rollups`, `commander_quota_metrics`, por `package_id`) + el panel del operador (`commander_clients`, `commander_deployments`...). Solo en la BD `commander`.
- **Negocio**: `packages/{Pkg}/database/migrations/` (solo en la BD del paquete).

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

`{sub}.dom/api/v1/docs` (RapiDoc UI, Scramble) y la spec en `/api/v1/docs.json`.
