# Snake Laravel API

API modular sobre Laravel 13. Un despliegue sirve exactamente un paquete de negocio (mas Core), seleccionado por la variable de entorno `API_PACKAGE`. Cada cliente/suscripcion se despliega en su propio subdominio con su propio `.env` y su propia base de datos.

## Stack

- **PHP 8.3+** / **Laravel 13**
- **Laravel Sanctum** — Autenticacion Bearer tokens
- **Spatie Laravel Permission** — Roles y permisos
- **Scramble** — Documentacion OpenAPI auto-generada con RapiDoc UI
- **Docker** — PHP-FPM (Alpine) + Nginx + MariaDB

## Puesta en marcha desde cero

Solo necesitas Docker (composer, PHP y migraciones corren dentro del contenedor):

```bash
git clone <repo> && cd snake-laravel-api
cp .env.example .env          # puertos, dominios (ajusta si quieres)
bash scripts/setup-ssl.sh     # certs nginx con mkcert (se auto-descarga si falta)
docker compose up -d          # build + composer install + APP_KEY + migrate, todo automatico
curl -k https://localhost:8443/api/v1   # health check
```

La instancia principal arranca agnostica (sin paquete, BD sqlite). Para servir un paquete: crea uno (`docker compose exec app php artisan make:package MiPaquete`) y levanta una suscripcion (ver "Simulacion local de suscripciones") o define `API_PACKAGE` en `src/.env`.

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
│   │   │   ├── Console/
│   │   │   │   ├── Commands/   # core:migrate, core:seed, make:package, 35+ make:*
│   │   │   │   └── Concerns/   # Traits para generadores y resolucion de paquete
│   │   │   ├── Exceptions/
│   │   │   │   └── CorePackageConfigurationException.php
│   │   │   ├── Http/
│   │   │   │   └── Controllers/
│   │   │   │       ├── CoreBaseApiController.php   # CRUD abstracto con permisos
│   │   │   │       ├── CoreAuthController.php      # Login, Logout, Me (bearer)
│   │   │   │       ├── CoreHealthController.php    # Health check
│   │   │   │       └── CoreUsersController.php     # Users REST API
│   │   │   ├── Models/
│   │   │   │   └── CoreUserModel.php               # User con roles y tokens
│   │   │   ├── Providers/
│   │   │   │   ├── CoreServiceProvider.php         # Orquestador principal
│   │   │   │   ├── CoreRouteServiceProvider.php    # Rutas Core + paquete activo
│   │   │   │   ├── CoreConsoleServiceProvider.php  # Comandos y seeders
│   │   │   │   └── CoreScrambleServiceProvider.php # OpenAPI docs
│   │   │   ├── Scramble/                           # Transformers OpenAPI
│   │   │   └── Support/
│   │   │       ├── CorePackageDiscoverySupport.php # Auto-descubrimiento
│   │   │       ├── CorePackageSelectorSupport.php  # Paquete activo (API_PACKAGE)
│   │   │       └── CoreTemplateRendererSupport.php # Renderizado de templates
│   │   ├── config/             # core, auth, cors, permission, sanctum, scramble
│   │   ├── database/
│   │   │   ├── migrations/     # Roles, schema base, tokens
│   │   │   └── seeders/        # Rol admin, permisos, usuario admin
│   │   ├── resources/
│   │   │   ├── templates/      # Templates para make:package
│   │   │   └── views/          # Vista RapiDoc personalizada
│   │   └── routes/
│   │       └── api.php         # Ruta Core: health check
│   └── MiPaquete/              # Paquete de negocio (creado con make:package)
│       ├── app/
│       │   ├── Providers/MiPaqueteServiceProvider.php
│       │   └── Http/Controllers/
│       ├── config/
│       ├── database/
│       │   ├── migrations/     # Tablas de negocio propias
│       │   └── seeders/
│       └── routes/
│           └── api.php         # Rutas del paquete (auth + negocio)
├── routes/
│   └── web.php
└── tests/
```

## Arquitectura

### Concepto general

Monolito modular: **un despliegue = un cliente = un paquete = una base de datos**. Toda la logica de negocio vive dentro de `packages/`, no en `app/`.

- **Core** es el paquete base, siempre activo. Provee la infraestructura: autenticacion, CRUD base, permisos, descubrimiento automatico y scaffolding.
- El paquete activo se define en `.env` con `API_PACKAGE` (ej. `API_PACKAGE=MiPaquete`). Solo se cargan Core + ese paquete: providers, configs, rutas, migraciones y seeders del resto de paquetes en disco se ignoran.
- La base de datos es la conexion estandar de Laravel (`DB_*` en `.env`). No hay conexiones dinamicas ni resolucion externa.

### Resolucion del paquete activo

Al arrancar, `CoreServiceProvider` valida `API_PACKAGE`:

| Valor | Comportamiento |
|-------|----------------|
| `API_PACKAGE=MiPaquete` (existe `packages/MiPaquete/`) | Carga Core + MiPaquete |
| `API_PACKAGE=` (vacio o ausente) | Carga solo Core (health + docs) |
| `API_PACKAGE=NoExiste` | Falla en boot con `CorePackageConfigurationException` y mensaje claro (HTTP y artisan) |

El valor se lee via `config('core.package')` (definido en `packages/Core/config/core.php`), compatible con `config:cache`.

### Despliegue multi-cliente (IONOS VPS)

Cada suscripcion/cliente = un subdominio = una copia desplegada con su propio `.env`:

```
cliente1.midominio.com  ->  .env: API_PACKAGE=MiPaquete,  DB_DATABASE=cliente1
cliente2.midominio.com  ->  .env: API_PACKAGE=MiPaquete,  DB_DATABASE=cliente2
otro.midominio.com      ->  .env: API_PACKAGE=OtroPaquete, DB_DATABASE=otro
```

Nota: si usas `php artisan config:cache` / `route:cache` en produccion, los valores quedan horneados. Tras cambiar `API_PACKAGE` o `DB_*`, ejecuta `php artisan optimize:clear` y vuelve a cachear.

### Simulacion local de suscripciones (Docker + WSL2)

Para probar el modelo de produccion sin VPS: una sola stack Docker simula N suscripciones. Cada cliente es un subdominio `<cliente>.<BASE_DOMAIN>` con su propio contenedor PHP-FPM (misma imagen y mismo `src/` montado, configurado solo por variables de entorno: `API_PACKAGE`, `DB_*`...), su base de datos MariaDB y su vhost nginx.

**Requisitos:**

1. `BASE_DOMAIN` definido en el `.env` raiz (default `snake.test`; evita `.local`, colisiona con mDNS).
2. Certificado wildcard: `bash scripts/setup-ssl.sh` (genera `*.BASE_DOMAIN` con mkcert; si no esta instalado se auto-descarga a `~/.local/bin`). En WSL el script pide **una sola elevacion UAC** que deja Windows configurado para siempre: importa la CA de mkcert al store de maquina (navegador confia sin dialogos) y concede a tu usuario permiso de escritura sobre el hosts de Windows, de modo que `subscription.sh` añade/quita subdominios sin volver a pedir permisos. (Nota: ese permiso permite a procesos de tu usuario editar el hosts sin UAC — compromiso aceptable en una maquina de desarrollo.)
3. Stack principal arrancada al menos una vez (`docker compose up -d`) para que exista la imagen.

**Uso:**

```bash
bash scripts/subscription.sh add cliente1 MiPaquete   # alta completa
bash scripts/subscription.sh list                     # listado y estado
bash scripts/subscription.sh remove cliente1 --drop-db
```

`add` hace todo el ciclo: crea BD + usuario MariaDB, genera el bloque de servicio en `docker-compose.clients.yml` (gitignored, regenerado desde `docker/clients/*.env`), genera el vhost nginx, levanta el contenedor (el entrypoint migra Core + paquete automaticamente), ejecuta seeders, y añade el subdominio al hosts de Windows (directo, sin UAC, gracias a la elevacion unica de `setup-ssl.sh`; sin ese permiso, cae a una ventana UAC puntual o imprime la linea para añadir a mano).

**Credenciales de BD por suscripcion**: database, usuario y password son el nombre del paquete en snake_case (`MiPaquete` → `mi_paquete`). Por eso cada paquete admite una sola suscripcion. El root de MariaDB corre sin password (`MARIADB_ALLOW_EMPTY_ROOT_PASSWORD`) — todo esto es SOLO para local; en produccion cada deploy define credenciales reales en su `.env`.

**Aislamiento por suscripcion:**

- BD y usuario MariaDB propios (cache de Laravel incluida: `CACHE_STORE=database`).
- `bootstrap/cache` aislado por contenedor (volumen anonimo).
- Logs por contenedor (`LOG_CHANNEL=stderr`): `docker compose logs -f app-<cliente>`.
- Shell de una instancia: `docker compose exec app-<cliente> bash` (artisan opera contra SU BD: `docker compose exec app-<cliente> php artisan tinker`). Ojo: `src/` es el mismo bind mount en todas — editar codigo afecta a todas las instancias; lo aislado son las variables de entorno y la BD.
- `storage/` se comparte entre contenedores pero no guarda estado por cliente (sin sesiones — auth Bearer — y cache en BD).

**Advertencias:**

- El wildcard cubre un solo nivel (`a.snake.test` si, `a.b.snake.test` no) y no el apex.
- Solo se edita el hosts de Windows (fuente de verdad): WSL lo copia a su `/etc/hosts` en cada arranque. Hasta reiniciar WSL, dentro de WSL prueba con `curl --resolve <cliente>.snake.test:8443:127.0.0.1 ...` (el navegador de Windows funciona al instante).
- `APP_KEY` se comparte entre suscripciones (un solo `src/.env`); en produccion cada deploy tiene la suya.
- Los volumenes anonimos huerfanos se limpian con `docker volume prune`.

### Override de configuracion

- Core se aplica primero, el paquete activo despues → **el paquete gana**.
- Si el nombre del archivo config coincide con uno global de Laravel (`auth`, `cors`, `session`, etc.), sobreescribe la config global via `array_replace_recursive`.
- Si no coincide, se registra con namespace: `config('mipaquete.archivo.key')`.

### Rutas

Core y el paquete activo se registran ambos bajo el prefijo `/api/v1`, Core primero. En colision exacta (mismo metodo + URI) gana la ruta del paquete; en patrones solapados no identicos gana el primero registrado.

## Rutas API

| Metodo | Ruta | Descripcion | Auth |
|--------|------|-------------|------|
| `GET` | `/api/v1/` | Health check (Core) | - |
| `GET` | `/api/v1/docs` | Documentacion OpenAPI (RapiDoc UI) | - |
| `GET` | `/api/v1/docs.json` | Especificacion OpenAPI JSON | - |
| `POST` | `/api/v1/auth/login` | Login (devuelve Bearer token) | - |
| `POST` | `/api/v1/auth/logout` | Logout (revoca token) | Sanctum |
| `GET` | `/api/v1/auth/me` | Usuario actual con roles y permisos | Sanctum |
| `*` | `/api/v1/users` | CRUD usuarios (apiResource) | Sanctum |
| `*` | `/api/v1/{...}` | Rutas de negocio del paquete activo | Sanctum |

## Autenticacion

Sanctum en modo **Bearer token exclusivamente**. No hay modo stateful ni sesiones.

**Flujo de login:**

1. `POST /api/v1/auth/login` con `email` y `password`
2. Se busca el usuario en la BD del despliegue
3. Si las credenciales son validas, se genera un token Sanctum
4. Se devuelve `{ token, token_type: "Bearer", user, token_id }`

**Opciones adicionales en login:**

- `name` — nombre del token (default: `login`)
- `abilities` — array de abilities del token (default: `["*"]`)
- `expires_at` — fecha de expiracion del token

## Descubrimiento automatico

`CoreServiceProvider` descubre recursos de Core + el paquete activo (el resto de `packages/` se ignora):

| Recurso | Patron glob | Accion |
|---------|-------------|--------|
| Providers | `packages/*/app/Providers/*ServiceProvider.php` | Registra en el container |
| Configs | `packages/*/config/*.php` | Merge o override global |
| Rutas | `packages/*/routes/api.php` | Todas en `/api/v1/` (Core primero) |
| Migraciones | `packages/*/database/migrations/` | Carga paths |
| Seeders | `packages/*/database/seeders/*DatabaseSeeder.php` | Ejecuta con `core:seed` |

## Comandos Artisan

### Gestion de paquetes

```bash
# Crear un nuevo paquete (scaffold completo)
php artisan make:package NombrePaquete

# Eliminar un paquete (rechaza eliminar el API_PACKAGE activo)
php artisan remove:package NombrePaquete
```

Tras crear/eliminar: `composer dump-autoload`. Para activar un paquete: `API_PACKAGE=NombrePaquete` en `.env`.

### Base de datos

Los comandos `core:*` operan sobre la conexion default del `.env` y por defecto usan el paquete de `API_PACKAGE` (la opcion `--package` es opcional y debe coincidir con el activo).

```bash
# Migraciones (Core + paquete activo)
php artisan core:migrate

# Migraciones con seed
php artisan core:migrate --seed

# Fresh (drop + re-migrate)
php artisan core:migrate --fresh

# Rollback
php artisan core:migrate --rollback

# Refresh (rollback + migrate)
php artisan core:migrate:refresh

# Seeders
php artisan core:seed

# Seeder especifico
php artisan core:seed --class=MiPaqueteProductSeeder
```

### Control plane (estado, metering, cuotas)

Cada deploy mide su uso y conoce su estado. Detalle completo en [`docs/CONTROL-PLANE.md`](docs/CONTROL-PLANE.md).

```bash
php artisan core:status                       # estado del deploy + cuotas
php artisan core:status --set=suspended --reason=manual --message="Pago pendiente"   # kill-switch (402)
php artisan core:usage:rollup --prune         # agrega metricas crudas en rollups hora/dia
php artisan core:quota:measure                # mide disco/requests/usuarios y aplica on_exceed
```

- Estados: `active` | `grace` (sirve con headers `X-Package-Status`) | `suspended` (402 en rutas de negocio; health, docs y `/control` siguen respondiendo).
- Metering por request en `core_request_logs` (middleware terminable, cero latencia con FPM) + rollups en `core_usage_rollups`.
- Endpoints de gestion remota `/api/v1/control/*` (firma HMAC, para el futuro CMS): solo existen si `CORE_CONTROL_SECRET` esta definido.

### Generadores package-aware

Todos los comandos `make:*` soportan `--package` para generar codigo dentro de un paquete:

```bash
php artisan make:model MiModelo --package=MiPaquete
php artisan make:controller MiController --package=MiPaquete
php artisan make:migration create_products_table --package=MiPaquete
php artisan make:seeder ProductSeeder --package=MiPaquete
```

## Convencion de un paquete

```
packages/MiPaquete/
├── app/
│   ├── Providers/MiPaqueteServiceProvider.php
│   ├── Http/
│   │   └── Controllers/
│   │       ├── MiPaqueteAuthController.php     # Extiende CoreAuthController
│   │       └── MiPaqueteUsersController.php    # Extiende CoreUsersController
│   ├── Models/
│   ├── Services/
│   └── Jobs/
├── config/
│   └── scramble.php      # (opcional) personaliza docs
├── database/
│   ├── migrations/       # Tablas de negocio propias
│   └── seeders/MiPaqueteDatabaseSeeder.php
├── routes/
│   └── api.php           # Rutas: auth (heredadas) + negocio
└── tests/
```

Cada paquete genera su propio `AuthController` y `UsersController` que extienden los de Core. Esto permite personalizar la logica de autenticacion o usuarios por cliente si es necesario.

## Migraciones del Core

Se ejecutan junto con las del paquete activo sobre la BD del despliegue:

1. **Roles y permisos** — Tablas de Spatie Permission (`roles`, `permissions`, `model_has_roles`, etc.)
2. **Schema base** — `users`, `sessions`, `cache`, `jobs`, `failed_jobs`, `password_reset_tokens`
3. **Tokens** — `personal_access_tokens` de Sanctum

### Seeder por defecto

- Rol: `admin`
- Permisos: `index`, `show`, `create`, `update`, `delete` sobre `users`
- Usuario admin: `admin@snake.com` / `secret1234`

## CoreBaseApiController

Controlador abstracto que provee CRUD completo con:

- Verificacion de permisos por accion (`indexPermission`, `storePermission`, etc.)
- Carga de relaciones via query param `?with=relation1,relation2`
- Validacion con reglas interpolables (`unique:users,email,{id}`)
- Soporte para Form Requests custom (`storeRequestClass`, `updateRequestClass`)

Los controladores solo necesitan definir propiedades:

```php
class MiController extends CoreBaseApiController
{
    protected string $modelClass = MiModelo::class;
    protected string $indexPermission = 'index mi-recurso';
    protected array $allowedWith = ['relacion'];
    protected array $validationRulesStore = [
        'campo' => 'required|string',
    ];
}
```

## Variables de entorno

```env
APP_NAME=SnakeLaravelApi
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost

# Paquete activo de este deploy (un paquete por despliegue)
API_PACKAGE=MiPaquete

DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=laravel
DB_USERNAME=laravel
DB_PASSWORD=root
```

`API_PACKAGE` selecciona el paquete de negocio. `DB_*` es la unica base de datos del despliegue: framework, usuarios, tokens, roles y tablas de negocio.

## Docker

```bash
# Levantar el entorno
docker compose up -d

# El entrypoint automaticamente (con flock: N contenedores arrancando a la
# vez no se pisan al mutar el src/ compartido):
# 1. Crea .env si no existe
# 2. Instala dependencias (composer install)
# 3. Genera APP_KEY si esta vacio
# 4. Fija permisos de storage/cache/database (ug+rwX, sin tocar modos de git)
# 5. Crea database/database.sqlite si la conexion es sqlite y falta
# 6. Ejecuta migraciones (Core + paquete activo via API_PACKAGE)
# 7. Inicia PHP-FPM
```

### Servicios

| Servicio | Imagen | Puerto |
|----------|--------|--------|
| `app` | `php:8.3-fpm-alpine` | 9000 (interno) |
| `nginx` | `nginx:alpine` | 8000:80 |
| `db` | `mariadb:latest` | 3306:3306 |

## Desarrollo local

```bash
cd src

# Setup inicial
composer setup

# Servidor de desarrollo
composer dev

# Tests
composer test
```

## Documentacion API

Accede a `/api/v1/docs` para ver la documentacion interactiva generada por Scramble con RapiDoc UI. La especificacion OpenAPI esta disponible en `/api/v1/docs.json`.
