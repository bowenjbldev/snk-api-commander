# Snake Laravel API

API modular sobre Laravel 13. **Un solo despliegue (una app) sirve TODOS los paquetes de negocio**, resueltos por subdominio en cada request: `{paquete}.dominio.com`. Multi-tenant por **base de datos**: una BD central (`commander`) con el registro + el panel de operador + el control-plane, y **una BD propia por paquete** (`shop`, `blog`...) con sus tablas de negocio + su base Laravel (users/roles/tokens). El Commander da de alta clientes y paquetes y los provisiona en un paso (crea la BD incluida), sin scripts de host.

> **Modelo centralizado.** Anteriormente cada paquete/cliente era un despliegue aislado con su propia BD (orquestado por `subscription.sh`). Ahora es **una app centralizada**: el paquete activo se resuelve por el `Host` del request, no por despliegue. `API_PACKAGE` vacío = centralizado (carga todos los paquetes); fijarlo bloquea la app a un único paquete (deploy aislado / tests).

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
cp src/.env.example src/.env  # API_PACKAGE vacio (centralizado) + BD central + root multi-BD
bash scripts/setup-ssl.sh     # cert wildcard *.BASE_DOMAIN con mkcert (se auto-descarga si falta)
docker compose up -d          # build + composer install + APP_KEY + migrate central, automatico
curl -k https://localhost:8443/api/v1   # health check (Core, agnostico de paquete)
```

El entrypoint migra el deploy **central** (`core:migrate`, sin `--package`): registro, control-plane, commander y la base del operador. Las BD de cada paquete las crea el provisioning. Para que el panel de operador tenga un admin, siémbralo una vez:

```bash
docker compose exec app php artisan core:seed --force   # central; admin@snake.com / secret1234
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
│   │   │   │   ├── CorePackageModel.php            # registro core_packages (identidad + estado)
│   │   │   │   └── CoreUserModel.php               # User (en la BD del paquete), roles y tokens
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
- **Una BD por paquete** (aislamiento fisico). Conexiones: `central` (fija, BD `commander`) + `tenant` (BD dinamica). El default es `central`; en un subdominio de negocio, `CoreResolvePackageFromHostMiddleware` conmuta la conexion default a la BD del paquete (`core_packages.database`). Seguro bajo PHP-FPM (cada request es share-nothing). Las tablas centrales (registro, control-plane, `commander_*`) van pinneadas a `central`; el control-plane sigue indexado por `package_id`. Los `users` NO llevan `package_id`: el aislamiento es por BD.

### Resolucion del paquete activo

Al arrancar, `CoreServiceProvider` carga los paquetes segun `API_PACKAGE`:

| Valor | Comportamiento |
|-------|----------------|
| `API_PACKAGE=` (vacio) | **Centralizado**: carga Core + TODOS los paquetes. El paquete activo se resuelve por subdominio en cada request. |
| `API_PACKAGE=Shop` (existe `packages/Shop/`) | **Lock**: carga solo Core + Shop, rutas planas (deploy aislado de un paquete / tests). |
| `API_PACKAGE=NoExiste` | Falla en boot con `CorePackageConfigurationException` y mensaje claro. |

En centralizado, las rutas de cada paquete se envuelven en `Route::domain('{sub}.<BASE_DOMAIN>')`; las de Core (health/docs) se registran globales (cualquier host). Un subdominio solo "esta vivo" si existe su fila en `core_packages` (creada al provisionar); si no, `404`.

### `core_packages`: registro de instancias

Tabla del registro, en la BD `central`. Una fila por paquete provisionado: `name` (StudlyCase, = directorio en `packages/`), `subdomain` (snake), **`database`** (su BD propia), y el **estado runtime** (`active|grace|suspended` + reason/message/grace_until). El middleware la lee para saber a que BD conectar. Reemplaza al antiguo singleton `core_package_state`. El control-plane usa `package_id` (FK a `core_packages`).

### Identidad por paquete

Aislamiento **fisico**: cada paquete tiene su propia tabla `users` + `personal_access_tokens` en su propia BD; el operador, las suyas en la central. `admin@snake.com` existe en Shop y Blog por separado (BDs distintas). Un token de un paquete simplemente **no existe** en la BD de otro → 401 automatico, sin codigo de comprobacion. No hay `users.package_id` ni middleware de aislamiento: lo da la separacion de BD.

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
php artisan core:migrate --force   # central (sin --package; las BD de paquete las crea el provisioning)
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

- Core se aplica primero. **Solo Core puede escribir claves globales de Laravel** (`auth`, `cors`, `scramble`...): con todos los paquetes cargados a la vez, permitir overrides globales desde paquetes de negocio causaria colisiones no deterministas.
- Las configs de los paquetes de negocio van **siempre namespaced**: `config('shop.archivo.key')`.

### Rutas

- **Centralizado**: las rutas de cada paquete bajo `Route::domain('{sub}.<BASE_DOMAIN>')->prefix('api/v1')`. Las de Core (health) son globales (cualquier host). El Commander en `commander.<BASE_DOMAIN>`.
- **Lock** (`API_PACKAGE` fijo): rutas planas bajo `/api/v1`, Core primero.

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
2. Se busca el usuario en la **BD del subdominio** (la conexion ya conmuto); el operador, en la central
3. Si las credenciales son validas, se genera un token Sanctum (en esa misma BD)
4. Se devuelve `{ token, token_type: "Bearer", user, token_id }`

Un token solo vale en su subdominio: en otra BD no existe → 401. El panel de operador (`commander.<BASE_DOMAIN>`) corre sobre la central, asi que un token de tenant tampoco entra ahi.

## Descubrimiento automatico

`CoreServiceProvider` descubre recursos de Core + los paquetes a cargar (todos en centralizado, o solo el de `API_PACKAGE` en lock):

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
2. Crea la fila `core_packages` (la instancia: enciende el subdominio).
3. **Crea la BD del paquete** (`CREATE DATABASE`) y corre `core:migrate --package=<X>` (base Laravel + negocio; BD = snake(package)) + `core:seed --package=<X>` (admin del paquete) **en esa BD**.
4. Activa + escribe las cuotas del plan (en la central).

Sin `host_command`, sin `subscription.sh`. El subdominio queda vivo al terminar. De-provisionar (`DELETE /deployments/{id}`) hace `DROP DATABASE` + limpia la central.

## Comandos Artisan

### Gestion de paquetes (desarrollo)

```bash
php artisan make:package NombrePaquete       # scaffold completo de un paquete nuevo (local)
php artisan remove:package NombrePaquete      # eliminar
composer dump-autoload
```

### Base de datos

```bash
php artisan core:migrate                  # CENTRAL: registro + control-plane + commander + base operador
php artisan core:seed                     # CENTRAL: admin + permisos del operador
php artisan core:migrate --package=Shop   # TENANT: base + negocio en la BD del paquete (BD = snake(package))
php artisan core:seed    --package=Shop   # TENANT: admin + seeders del paquete (en su BD)
php artisan core:migrate --rollback       # rollback de la central
# Regla: --package => tenant (BD=snake(package)); sin --package => central.
# (normalmente las BD de paquete las gestiona el provisioning del Commander, no a mano)
```

### Control plane (estado, metering, cuotas)

Todo in-process (Commander y deploys viven en la misma app/BD). Detalle en [`docs/CONTROL-PLANE.md`](docs/CONTROL-PLANE.md).

```bash
php artisan core:status                       # estado + cuotas (por paquete activo)
php artisan core:usage:rollup --prune         # agrega core_request_logs en rollups hora/dia (por package)
php artisan core:quota:measure                # mide disco/requests/usuarios por package y aplica on_exceed
```

- Estados por paquete: `active` | `grace` (headers `X-Package-Status`) | `suspended` (402 en rutas de negocio; health y docs siguen respondiendo).
- Metering por request en `core_request_logs` (middleware terminable, cero latencia con FPM, columna `package_id`) + rollups en `core_usage_rollups`.
- El Commander cambia el estado de un paquete escribiendo `core_packages` directamente (sin HMAC): `POST /deployments/{id}/suspend|grace|resume`.

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

## Migraciones del Core

Separadas en `packages/Core/database/migrations/{central,tenant}` por su destino:

- **central/** (BD `commander`): `core_packages` (registro) + control-plane (`core_request_logs`, `core_usage_rollups`, `core_quota_metrics`, por `package_id`). Mas las `commander_*`.
- **tenant/** (corre en la central para el operador Y en CADA BD de paquete): Roles/permisos (Spatie), schema base (`users`, `sessions`, `cache`, `jobs`...), tokens Sanctum.
- **negocio**: `packages/{Pkg}/database/migrations/` (solo en la BD del paquete).

`core:migrate` (sin `--package`) corre central+tenant-base+commander; el provisioning corre `core:migrate --package=<X>` (tenant-base + negocio) en la BD del paquete. Regla: `--package` ⟹ tenant (BD = snake(package)); sin `--package` ⟹ central.

### Seeder por defecto

- Rol `admin`, permisos sobre `users`, usuario admin `admin@snake.com` / `secret1234` (uno por BD: el del operador en la central, el de cada paquete en su BD).

## Variables de entorno

```env
APP_NAME=SnakeLaravelApi
APP_ENV=local
APP_DEBUG=true
APP_URL=https://api.snake.test

# Vacio = centralizado (sirve todos los paquetes por subdominio).
# Fijar (ej. Shop) bloquea la app a un unico paquete.
API_PACKAGE=
BASE_DOMAIN=snake.test

# Multi-tenant: default=central; cada subdominio conmuta a su BD (tenant).
# root (sin password) es necesario para acceder a todas las BD + CREATE DATABASE (SOLO local).
DB_CONNECTION=central
DB_HOST=db
DB_PORT=3306
CENTRAL_DB_DATABASE=commander
DB_USERNAME=root
DB_PASSWORD=
# En tests: CENTRAL_DB_DRIVER=sqlite / TENANT_DB_DRIVER=sqlite (sin MariaDB).
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
| `db` | `mariadb:latest` (crea la BD central `commander` en init; las de paquete las crea el provisioning) | `${DB_PORT:-3306}:3306` (3307 en local) |
| `commander-web` | `node:24-alpine` (SPA en `commander.<BASE_DOMAIN>`) | 80 (interno) |

El entrypoint: crea `.env` si falta, `composer install`, `APP_KEY`, fija permisos, y migra la **central** (`core:migrate`, sin `--package`). Luego PHP-FPM.

## Desarrollo local

```bash
cd src
composer setup   # install + key + migrate
composer dev     # servidor de desarrollo
composer test    # tests
```

## Documentacion API

`{sub}.dom/api/v1/docs` (RapiDoc UI, Scramble) y la spec en `/api/v1/docs.json`.
