# Operación

## Arranque local

```bash
cp .env.example .env
cp src/.env.example src/.env
bash scripts/setup-ssl.sh
docker compose up -d
docker compose exec -T app php artisan core:seed --force
```

El entrypoint:

1. instala Composer si falta y genera `APP_KEY`;
2. prepara storage y cachés;
3. ejecuta `core:migrate --force` sobre `commander`;
4. ejecuta `commander:applications:sync`;
5. limpia la caché de aplicación.

No migra automáticamente las bases tenant existentes.

## Servicios Docker

| Servicio | Función |
|---|---|
| `app` | PHP-FPM y comandos sin DDL tenant |
| `scheduler` | `php artisan schedule:work` |
| `provisioner` | asegura usuarios runtime y consume la cola `provisioning` con DDL |
| `nginx` | TLS, wildcard tenant y proxy |
| `db` | MariaDB: `commander` más una base por instancia |
| `db-init` | usuario no privilegiado de Commander |
| `mailpit` | SMTP local de pruebas |
| `commander-web` | perfil opt-in vacío para un futuro cliente web |

MariaDB local permite root sin contraseña para el provisioner. No copies esa configuración a
producción.

### Perfil del contenedor HTTP

El perfil local está calibrado para el i7-7700K de 4 núcleos/8 hilos con MariaDB en el mismo host:

- `app`: cuota de 6 CPU, 2 GiB y 128 procesos, sin capabilities y con `no-new-privileges`;
- FPM: 8 workers estáticos, backlog 1024, reciclaje cada 500 requests, slowlog a 5 s y corte a
  60 s;
- PHP: 128 MiB por request, OPcache 192 MiB/16 MiB de strings, JIT desactivado y subida máxima
  de 64 MiB para respetar el contrato Kilkana de 50 MiB;
- Nginx: compresión de JSON, buffer FastCGI, sesiones TLS y `X-Content-Type-Options`.

`APP_CONTAINER_CPUS`, `APP_CONTAINER_MEMORY` y `APP_CONTAINER_PIDS` permiten recalibrar el
presupuesto sin editar Compose. `opcache.validate_timestamps=1` se conserva porque local monta el
código; en una imagen de producción inmutable puede pasar a `0`. No actives keepalive FastCGI con
esta topología: ocho conexiones persistentes inmovilizaron los ocho workers FPM y provocaron 76
timeouts en 1.200 operaciones de calibración.

## DNS, TLS y URLs

```bash
bash scripts/setup-ssl.sh
bash scripts/dns-wildcard.sh
```

Resuelve `*.<BASE_DOMAIN>` y `*.<TENANT_BASE_DOMAIN>` a `127.0.0.1`. El fichero hosts no admite
wildcards; en ese caso añade `api`, `commander` y cada cliente.

| Recurso | URL local |
|---|---|
| API Commander | `https://commander.snakepc.test/api/v1` |
| tenant Kilkana | `https://{cliente}.kilkana.test/api/v1/kilkana` |
| health | `https://api.snakepc.test/api/v1/` |
| OpenAPI | `https://api.snakepc.test/api/v1/docs.json` |
| Mailpit | `http://localhost:8025` |

Prueba sin DNS:

```bash
curl -k --resolve api.snakepc.test:8443:127.0.0.1 \
  https://api.snakepc.test:8443/api/v1/
```

## Operador y admins tenant

```bash
docker compose exec -T app php artisan core:seed --force
```

En local, el operador por defecto es `admin@snake.com` / `secret1234`. Producción exige una
contraseña configurada. El admin de cada tenant es distinto: provisioning y retry exigen su email
y contraseña explícitos. Un seed tenant nunca copia el operador Commander.

`KILKANA_SEED_DEMO=false` es el valor seguro. Si se activa, configura también
`KILKANA_DEMO_READER_PASSWORD`.

El login no penaliza respuestas correctas. Por defecto bloquea tras cinco `401` por cuenta o diez
por IP en un minuto mediante `CORE_LOGIN_ACCOUNT_RATE_LIMIT` y `CORE_LOGIN_IP_RATE_LIMIT`; ambos
controles se aplican a la vez y no deben sustituirse por un límite alto solo por IP. El límite API
general es `CORE_API_RATE_LIMIT=240` por usuario autenticado o IP. Si hay un balanceador delante de
Nginx, configura la IP real únicamente para sus CIDR de confianza: aceptar `X-Forwarded-For` desde
cualquier origen permite falsificar la clave del rate limit.

## Catálogo de aplicaciones

```bash
docker compose exec -T app php artisan commander:applications:sync
```

Ejecuta el comando tras añadir, quitar o renombrar un package/overlay descubrible. Es idempotente;
marca código ausente como `missing` y no borra historia. Nunca dependas de un `GET` para hacer el
sync.

## Migraciones

### Commander

```bash
docker compose exec -T app php artisan core:migrate --force
docker compose exec -T app php artisan commander:applications:sync
docker compose exec -T app php artisan core:seed --force
```

Sin `--client` y `--package`, `core:migrate` ejecuta Core + Commander sobre la conexión de control.

### Una instancia

```bash
docker compose exec -T provisioner php artisan core:migrate \
  --client=elmundo --package=Kilkana --force

docker compose exec -T provisioner php artisan core:seed \
  --client=elmundo --package=Kilkana --force
```

Los flags son inseparables. El orden es Core, package compartido y overlay.

### Todas tras un deploy

```bash
docker compose exec -T provisioner php artisan core:migrate --all-tenants --force
```

El comando carga instancias ready y hace preflight de cliente, aplicación, código, base y
credenciales antes del primer DDL. No combina `--all-tenants` con client, package, seed, fresh o
rollback.

**Y por eso migrar no basta: `--all-tenants` rechaza `--seed` por diseño.** Lo que vive
replicado por base y no lo escribe una migración —el catálogo de temas y el reparto de roles—
no llega a un periódico que ya existía. Son dos comandos, y van tras el mismo deploy:

```bash
docker compose exec -T provisioner php artisan kilkana:sync-themes
docker compose exec -T provisioner php artisan kilkana:sync-permissions
```

El de permisos, siempre que un despliegue traiga un permiso o cambie qué lleva un rol. Saltárselo
**no rompe nada de forma visible**, que es lo que lo hace peligroso: los periódicos dados de alta
después del cambio tienen el reparto nuevo y los de antes el viejo, y un permiso que solo decide
para algunos roles deja al resto pareciendo correctos. Pasó con `view others posts`: tres de las
cuatro bases locales se quedaron sin él, los roles que editan lo ajeno seguían viendo el sumario
—no les hace falta el permiso, la consulta ya se lo da— y el redactor lo perdía en silencio.

Los dos son idempotentes y reaplicarlos repara lo tocado a mano, así que ante la duda se corren.

### Fresh local

El esquema Commander de esta rama parte limpio y no mantiene compatibilidad con datos anteriores.
Para reconstruir un entorno local:

```bash
docker compose exec -T app php artisan core:migrate --fresh --force
docker compose exec -T app php artisan commander:applications:sync
docker compose exec -T app php artisan core:seed --force
```

`--fresh` destruye todas las tablas del destino. No lo uses en producción. Una evolución después
de desplegar este esquema debe usar una nueva migración forward.

## Provisioning

El flujo normal usa la API Commander, no comandos manuales:

1. despliega el package;
2. sincroniza aplicaciones;
3. crea plan y límites;
4. para grupo, crea antes cuenta y contrato negociado;
5. envía provisioning con credenciales admin;
6. observa hasta `provisioning_status = ready`.

Inspección:

```bash
docker compose ps
docker compose logs --tail=200 provisioner
docker compose exec -T app php artisan queue:failed
docker compose exec -T app php artisan commander:status
docker compose exec -T app php artisan commander:status <instance_id>
```

Sin ID, `commander:status` lista instancias. Con ID muestra estado; `--set`, `--reason`, `--message`
y `--grace-until` permiten operación manual.

Una instancia failed conserva `last_error`. Reinténtala por
`POST /api/v1/instances/{id}/retry` con credenciales admin nuevas; no crees una segunda fila.

## Scheduler

```bash
docker compose logs --tail=200 scheduler
docker compose exec -T app php artisan schedule:list
docker compose exec -T app php artisan commander:refresh
docker compose exec -T app php artisan commander:usage:rollup --prune
docker compose exec -T app php artisan commander:quota:measure
docker compose exec -T app php artisan commander:quota:measure --dry-run
docker compose exec -T app php artisan commander:git:sync
docker compose exec -T app php artisan kilkana:publish-scheduled
```

| Frecuencia | Comando |
|---|---|
| cada minuto | `kilkana:publish-scheduled` |
| cada 10 min | `commander:refresh` |
| cada hora | `commander:usage:rollup --prune` |
| cada hora | `commander:git:sync` |
| cada 6 h | `commander:quota:measure` |

Los recorridos Kilkana reutilizan `SnkKilkanaTenantRunnerService`; un tenant roto no debe impedir
procesar los demás.

## Simulación de capacidad multi-tenant

`scripts/simulate.sh` caracteriza routing, cambio de conexión, carga y aislamiento con las
herramientas ya instaladas. Solo admite `APP_ENV=local` y dominios `.test`. Descubre instancias
`ready/active` y el escenario actual exige tres Kilkana y una DemoApp.

```bash
bash scripts/simulate.sh smoke
bash scripts/simulate.sh load read cross-package 10 300
bash scripts/simulate.sh load write same-package 5 100
bash scripts/simulate.sh login-load 5 100
bash scripts/simulate.sh security
bash scripts/simulate.sh rate-limit
bash scripts/simulate.sh matrix
```

`read`, `write` y `mixed` —90/10 por operaciones— se pueden cruzar con:

- `single`: una instancia Kilkana;
- `same-package`: tres bases Kilkana en round-robin;
- `cross-package`: las tres Kilkana más DemoApp.

`login-load` mide por separado el driver de hashing configurado y la emisión de tokens sobre un
usuario efímero. Genera el hash mediante Laravel, eleva temporalmente los límites API y de login
para que el throttle no falsee la capacidad y elimina al usuario con todos sus tokens al terminar.
No admite credenciales de usuarios existentes.

`matrix` prueba concurrencias `1, 5, 10, 25, 50`. Para que los `429` no oculten el techo de la
aplicación, recrea solo `app` con límites altos mediante un override Compose temporal y restaura
la configuración normal en `trap`. `rate-limit` comprueba por separado que doce accesos correctos
desde una IP pasan y que los fallos posteriores reciben `429`. Cada
ejecución crea usuarios admin efímeros con contraseña aleatoria dentro de las bases locales,
genera y borra tags o tokens, y los elimina al salir. No modifica cuentas existentes. Los request
logs de metering sí permanecen y el preflight rechaza cuotas `requests_day` capaces de suspender
un target, salvo `ALLOW_QUOTA_EFFECTS=1` explícito.

Los CSV de cliente contienen status, conexión, TTFB y total; los de servidor muestrean CPU,
memoria y conexiones MariaDB. Se escriben en `/tmp/commander-benchmark-*` o `OUTPUT_DIR`. Se
pueden ajustar `REQUESTS`, `WARMUP_REQUESTS`, `CONCURRENCIES` y `TARGET_RPS`.

### Baseline local y tuning del 17-08-2026

Entorno: i7-7700K de 4 núcleos/8 hilos y 31 GiB; `app` pasó de no tener límites y usar 5 workers
dinámicos sin reciclaje a 6 CPU/2 GiB y 8 workers estáticos. MariaDB 11.5 mantuvo
`max_connections=151` y buffer pool de 128 MiB. Se enviaron 300 operaciones por escalón, con
metering activo y rate limit separado.

| Perfil/escenario | Concurrencia | Antes | Ajustado | Cambio | p95 antes → ajustado |
|---|---:|---:|---:|---:|---:|
| lectura cross-package | 10 | 54,89 RPS | 91,24 RPS | +66 % | 191 → 121 ms |
| escritura cross-package | 10 | 30,12 RPS | 52,03 RPS | +73 % | 327 → 176 ms |
| mixto aceptado por criterio | 5 | 45,19 RPS | 74,49 RPS | +65 % | 125 → 76 ms |
| mixto máximo bruto | 25 | 49,35 RPS | 94,45 RPS | +91 % | 550 → 293 ms |

No hubo `5xx`, timeouts ni `429`. El ajuste alcanzó 358 % CPU y 77 MiB en `app`, 38 % CPU y
293 MiB en MariaDB, con 17 conexiones activas como máximo. Diez y doce workers rindieron menos
que ocho: el cuello pasó de la cola artificial de cinco workers a los cuatro núcleos físicos y al
coste por request. Memoria, la cuota de 6 CPU y `max_connections` conservaron margen.

MariaDB no se retocó: los 10,4 MiB actuales caben completos en su buffer pool de 128 MiB, no hubo
esperas por buffer y su CPU quedó lejos de saturarse. Aumentar memoria o relajar durabilidad no
mejoraría este perfil medido.

Una comparación más larga de 1.200 lecturas a concurrencia 5 dio 90,29 RPS para un tenant, 89,53
para tres bases Kilkana y 88,64 para Kilkana+DemoApp. La diferencia inferior al 2 % no muestra una
penalización material por cambiar BD/package.

La repetición posterior a la migración de contraseñas confirmó que Argon2id no afecta al camino
normal con token: a concurrencia 10 se obtuvieron 111,69 RPS de lectura y 55,45 RPS de escritura;
el escalón mixto aceptado fue 79,41 RPS a concurrencia 5. Un package dio 91,52 RPS y tres bases del
mismo package 90,80 RPS a concurrencia 5. No hubo `5xx`, timeouts ni `429`, y el cambio de conexión
continúa sin penalización material.

El login se midió aparte con Argon2id de 19 MiB, tiempo 2 y un hilo:

| Concurrencia | Logins | RPS | p95 | CPU PHP pico | RAM `app` pico | Errores |
|---:|---:|---:|---:|---:|---:|---:|
| 1 | 200 | 14,16 | 66 ms | 69 % | 79,23 MiB | 0 |
| 4 | 500 | 45,25 | 85 ms | 280 % | 136,90 MiB | 0 |
| 8 | 1.000 | 58,63 | 142 ms | 435 % | 156,90 MiB | 0 |

Los picos son muestras de `docker stats`; la reserva teórica de ocho verificaciones simultáneas es
152 MiB, más el proceso Laravel. El pico observado usa el 7,7 % del límite de 2 GiB de `app`.
`pm.max_children=8` limita las reservas Argon2 simultáneas; subir workers debe volver a medirse
porque el perfil aún consume 4,35 de los cuatro núcleos físicos bajo carga.

La referencia HTTP anterior de 56,37 RPS, p95 142 ms y 62,57 MiB con ocho workers correspondía en
realidad a bcrypt coste 10: el simulador histórico usaba el default de `password_hash`, aunque la
documentación indicaba coste 12. La comparación controlada posterior, con 1.000 logins y
concurrencia 8 en ambos casos, dio:

| Driver | RPS | p95 | CPU PHP pico | RAM `app` pico | Errores |
|---|---:|---:|---:|---:|---:|
| bcrypt coste 12 | 24,32 | 377 ms | ~600 % | 62,65 MiB | 0 |
| Argon2id anterior 64 MiB/2/1 | 31,58 | 267 ms | ~600 % | 446,70 MiB | 0 |
| Argon2id actual 19 MiB/2/1 | 58,63 | 142 ms | 435 % | 156,90 MiB | 0 |

Frente al perfil Argon2id anterior, 19 MiB/2/1 aporta un 86 % más throughput, reduce el p95 un 47 %
y la RAM un 65 %. Frente a bcrypt-12 aporta un 141 % más throughput y reduce el p95 un 62 %, usando
2,5 veces más RAM. Cumple el mínimo OWASP, pero elimina el margen adicional de resistencia offline
que daba 64 MiB/2/1. El simulador usa el driver y coste reales de Laravel. Es throughput sostenido,
no una promesa de aceptar 1.000 sockets en el mismo instante; repite `login-load` sobre el hardware
de producción.

El objetivo de 100 RPS mixtos queda cerca en bruto, pero no cabe con 30 % de margen y el criterio
de latencia. La medición da dos réplicas equivalentes; no deben competir en este mismo i7, porque
una ya consume hasta 3,58 de sus cuatro núcleos físicos. Necesitan CPU adicional o un segundo
nodo. Con ocho workers por réplica, reserva hasta 16 conexiones MariaDB por réplica —Commander +
tenant—, más scheduler, provisioner y operación.

Los 100 tenants registrados no crean 100 conexiones permanentes: mandan la concurrencia y los
workers. Esta línea base solo tiene cuatro bases; no mide backups, migraciones, filesystem ni
`information_schema` con 100 esquemas. Además, el generador usa procesos `curl` y conexiones
cortas desde el mismo host Docker, por lo que es una medida conservadora extremo a extremo, no un
sustituto de una prueba distribuida.

## Comprobaciones

```bash
docker compose exec -T app php artisan test
cd src
vendor/bin/pint --test
composer audit
php artisan config:cache
php artisan route:cache
```

Reporta test, formato y audit por separado. SQLite no cubre DDL/privilegios/collation; prueba esos
cambios también con MariaDB.

## Cliente web

`commander-web/` es el SPA del operador: React 19 + TypeScript + Carbon Design System v11, según el
[diseño funcional](design/commander-spa.md). **Corre entero sobre un mock determinista**
(`src/services/api/mock/`) y no llama a la API; el store vive en memoria y se pierde al recargar.

El perfil `spa` no arranca por defecto, así que hay que pedirlo:

```bash
docker compose --profile spa up -d commander-web nginx
```

Queda en `https://commander.snakepc.test/`. `/api/*` sigue llegando a Laravel y `/` al servidor de
Vite. Sin el perfil, `/` responde 404 y la API continúa disponible.

Dentro de `commander-web/` (o con `docker compose exec commander-web`):

```bash
npm run check   # tsc -b --noEmit
npm test        # node --test, lógica de negocio de src/**/model
npm run build   # typecheck + vite build
```

La lógica de negocio vive en `features/<dominio>/model/` justo para poder probarla sin navegador;
los componentes de `pages/` solo pintan.

## MariaDB

```bash
docker compose exec -T db mariadb -uroot commander -e 'SHOW TABLES;'
docker compose exec -T db mariadb -uroot elmundo_kilkana -e 'SHOW TABLES;'
```

No incluyas secretos reales en comandos: quedan en history y en la lista de procesos.

## Deploy

Secuencia mínima:

```bash
git pull --ff-only
cd src
composer install --no-dev --optimize-autoloader
php artisan core:migrate --force
php artisan commander:applications:sync
php artisan config:cache
php artisan route:cache
```

Después, desde el provisioner:

```bash
php artisan core:migrate --all-tenants --force
```

Requisitos adicionales:

- DNS y certificado wildcard;
- PHP-FPM sin concurrencia interna tipo Octane;
- scheduler permanente o cron `schedule:run`;
- worker exclusivo de provisioning con DDL;
- `retry_after` mayor que el timeout del job;
- backup de Commander, bases tenant y storage antes de cambios relevantes.

## Gotchas

- `CoreUserModel.password` ya tiene cast `hashed`; no uses `Hash::make` al asignarlo.
- El usuario es `Packages\Core\Models\CoreUserModel`, no `App\Models\User`.
- Dominios quedan en route cache; regenera tras cambiarlos.
- Un package nuevo requiere autoload/recache y sync de aplicaciones.
- Un token tenant no autentica en Commander ni viceversa.
- Cambiar un plan de catálogo no propaga límites a instancias hasta provisionar/cambiar plan.
- Una suscripción negociada agrupa billing y puede tener cuotas compartidas; no agrupa bases,
  usuarios ni las cuotas operativas propias de cada instancia.
