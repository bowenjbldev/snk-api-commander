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

| Servicio        | Función                                                           |
| --------------- | ----------------------------------------------------------------- |
| `app`           | Octane sobre FrankenPHP (worker mode) y comandos sin DDL tenant   |
| `fpm`           | perfil opt-in: la misma API sobre php-fpm, nginx `:8444`          |
| `octane-swoole` | perfil opt-in: la misma API sobre Octane/Swoole, nginx `:8445`    |
| `watch`         | recarga los workers de Octane al cambiar código, config o `.env`  |
| `scheduler`     | `php artisan schedule:work`                                       |
| `provisioner`   | asegura usuarios runtime y consume la cola `provisioning` con DDL |
| `nginx`         | TLS, wildcard tenant y proxy                                      |
| `db`            | MariaDB: `commander` más una base por instancia                   |
| `db-init`       | usuario no privilegiado de Commander                              |
| `mailpit`       | SMTP local de pruebas                                             |
| `commander-web` | perfil opt-in vacío para un futuro cliente web                    |

MariaDB local permite root sin contraseña para el provisioner. No copies esa configuración a
producción.

### Perfil del contenedor HTTP

El perfil local está calibrado para el i7-7700K de 4 núcleos/8 hilos con MariaDB en el mismo host:

- `app`: cuota de 6 CPU, 2 GiB y 128 procesos, sin capabilities y con `no-new-privileges`;
- Octane sobre FrankenPHP en worker mode: 8 workers reciclados cada 500 peticiones y corte a 60 s
  (`max_execution_time` de `config/octane.php`); `OCTANE_WORKERS` los recalibra sin tocar Compose,
  y 12-16 rinden más en lectura sobre este hardware
  ([A/B de runtimes](#ab-php-fpm-frente-a-octane-del-05-09-2026));
- PHP: 512 MiB por request, OPcache 192 MiB/16 MiB de strings también en CLI, JIT desactivado y
  subida máxima de 64 MiB para respetar el contrato Kilkana de 50 MiB;
- Nginx: proxy HTTP/1.1 a `api-app:8000`, compresión de JSON, sesiones TLS y
  `X-Content-Type-Options`.

**Los workers guardan la aplicación en memoria**: un cambio en un fichero PHP no se ve hasta
`docker compose exec app php artisan octane:reload` (o reiniciar `app`). Config, rutas y
`.env` igual. Es la única diferencia de trabajo diario con php-fpm, y `watch` la quita: arranca
con el resto, es un sidecar con `chokidar` que comparte la red de `app`, vigila `app/`,
`packages/`, `config/`, `bootstrap/`, `routes/`, `.env` y `composer.lock`, y al cambiar algo pide
al puerto de administración de Caddy —que solo escucha en el loopback del contenedor— que reinicie
los workers: dos segundos y la API contesta con el código nuevo. Si `watch` no está arriba,
`octane:reload` a mano. Producción no pasa por Docker y no lleva nada de esto.

Los perfiles `fpm` y `swoole` levantan la misma API sobre php-fpm (`docker/php/fpm.Dockerfile`,
8 workers estáticos de `fpm.conf`) y sobre Octane/Swoole, con el mismo código montado, la misma
cuota y el mismo reciclaje; nginx los expone en `:8444` y `:8445` con el mismo certificado para
repetir la matriz contra `app`. Con `fpm` no actives keepalive FastCGI: ocho conexiones
persistentes inmovilizaron los ocho workers y provocaron 76 timeouts en 1.200 operaciones.

`APP_CONTAINER_CPUS`, `APP_CONTAINER_MEMORY` y `APP_CONTAINER_PIDS` permiten recalibrar el
presupuesto sin editar Compose. `opcache.validate_timestamps=1` se conserva por los comandos de
consola, que sí leen el bind mount en cada arranque; en una imagen de producción inmutable puede
pasar a `0`.

## DNS, TLS y URLs

```bash
bash scripts/setup-ssl.sh
bash scripts/dns-wildcard.sh
```

Resuelve `*.<BASE_DOMAIN>` y `*.<TENANT_BASE_DOMAIN>` a `127.0.0.1`. El fichero hosts no admite
wildcards; en ese caso añade `api`, `commander` y cada cliente.

| Recurso | URL local |
| --- | --- |
| API Commander | `https://commander.snakepc.test/api/v1` |
| API tenant, producto Kilkana | `https://{cliente}.commander.test/api/v1/kilkana` |
| health | `https://api.snakepc.test/api/v1/` |
| OpenAPI | `https://api.snakepc.test/api/v1/docs.json` |
| Mailpit | `http://localhost:8025` |

Configura `BASE_DOMAIN=snakepc.test` y `TENANT_BASE_DOMAIN=commander.test` tanto en `.env`
como en `src/.env`. El segundo es el dominio de plataforma para todos los packages; Kilkana se
selecciona mediante `/api/v1/kilkana`. Las URLs públicas de los periódicos son independientes.

Al cambiar el dominio en un entorno existente, regenera el certificado con `setup-ssl.sh`,
recrea Nginx y recachea config/rutas de Laravel. Reinicia los workers que conservan configuración
en memoria. Actualiza los destinos API de los frontends que consumen este backend. Los enlaces
firmados anteriores incluyen el host antiguo: no se convierten cambiando solo el dominio.

Compose publica también HTTPS en `127.0.0.2:443` para las URLs limpias de esta plataforma;
resuelve `*.commander.test` a esa IP si `127.0.0.1:443` lo ocupa el frontend de un producto.
Sin DNS puede comprobarse con:

```bash
curl --resolve demo.commander.test:443:127.0.0.2 \
  https://demo.commander.test/api/v1/kilkana/public/posts
```

Health sin DNS en el puerto alternativo:

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
para algunos roles deja al resto pareciendo correctos. Pasó con `view others posts`: las bases dadas de alta antes del cambio
se quedaron sin él, los roles que editan lo ajeno seguían viendo el sumario
—no les hace falta el permiso, la consulta ya se lo da— y el redactor lo perdía en silencio.

**Y en local vale lo mismo.** Los inquilinos aprovisionados no reciben una migración por
existir: una columna nueva sin `core:migrate --all-tenants` abre el panel local con «Unknown
column», y una tabla nueva con su recurso de permisos pide además el catálogo. Una migración nueva
en `packages/Kilkana` pide ese comando el mismo día, y un cambio del catálogo de roles, el de
permisos.

Los dos son idempotentes y reaplicarlos repara lo tocado a mano, así que ante la duda se corren.
Y los dos sueltan la caché persistente del reparto, igual que hace `core:seed` al sembrar los
permisos: la de consola es `array` y muere con el proceso, así que sin soltarla los workers HTTP
vivos siguen repartiendo el reparto anterior hasta que caduque.

**El seeder de Kilkana no es de esa clase, y son DOS cosas que conviene saber antes de perseguir
un fantasma.**

La primera es que **el contenido de demo no se siembra por defecto**: todo lo que va después de los
permisos, los temas y los ajustes —categorías, piezas, firmas, comentarios— cuelga de
`config('kilkana.kilkana.seed_demo')`, que es `KILKANA_SEED_DEMO` y viene en `false`. Sin la
variable, `core:seed` termina con éxito y **no toca ni una fila** de contenido:

```bash
docker compose exec -T -e KILKANA_SEED_DEMO=1 provisioner php artisan core:seed   --client=demo --package=Kilkana --force
```

Y la variable sola no basta, porque **qué periódico se siembra no lo decide quien lanza el comando,
lo decide la base**. `database/seeders/data/profiles/*.json` declara un periódico por fichero —su
cabecera, su tema y de qué clientes es—, y el seeder busca el que reclame al cliente de la conexión.
Un perfil con `"demo": false` se queda en la cabecera aunque la variable esté puesta, y una base sin
perfil no recibe ni identidad ni contenido: es lo que le toca a un cliente real, que se nombra a sí
mismo al darse de alta.

Las dos puertas hacen falta. Con solo la variable, `core:seed --client=otro` vuelca el archivo de un
periódico dentro de la base de otro, y eso no se ve hasta que alguien cuenta las piezas: 523 de El
Diario acabaron dentro de dos bases que no eran suyas. De las dos puertas cuelgan también **las dos
cuentas de demo** —el administrador y el lector—, porque una contraseña conocida dentro de la base
de un cliente es un acceso ajeno a su panel.

De ahí sale una consecuencia que muerde al recrear una base a mano: **un inquilino con `demo: false`
se queda sin administrador**. En un alta de verdad no pasa —el provisioning crea la cuenta elegida y
fija `core.auth.admin_email` antes de llamar a `core:seed`—, pero quien recree una base local sin
saberlo se queda mirando un login que no abre nadie. La cuenta se pone a mano, con su rol `admin`.

La segunda es **qué reescribe y qué no**, porque no es todo igual:

| Se **sincroniza** en cada siembra                                                                                                 | Se **rellena solo si está vacío**                                   |
| --------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------- |
| La estructura que declara el catálogo: el estado, la madre y la posición de una sección; la clase de una firma y si tiene cuenta. | Lo editorial: nombre, cargo, biografía, web, redes, correo público. |
| De cada pieza del snapshot: su sección, su firma, su estado, su fecha y si va destacada.                                          | El titular y la entradilla de una pieza que ya existe.              |

El reparto no es capricho. La estructura es lo que hace que la demo enseñe lo que dice enseñar
—una sección retirada del menú, una agencia que firma sin asiento— y un juego de datos que solo
estrena un periódico nuevo es una foto del pasado: una sección que cambia de estado o una firma
de agencia que deja de tener cuenta tienen que llegar a las bases ya sembradas sin tocarlas a
mano. Lo editorial, en cambio, es justo lo que alguien toca al probar la demo, y pisarlo en cada
siembra sería perder su trabajo.

**Y retira las cuentas de servicio que una firma de agencia no necesita**, y solo si siguen
siendo lo que eran: sin rol, sin firma y con el correo que él les puso. Ojo con lo que arrastran:
`kilkana_posts.created_by` es `ON DELETE SET NULL`, así que las piezas que apunten a esas cuentas
**se quedan sin creador**, que es lo correcto para un teletipo que no tecleó nadie en el CMS pero
no se deshace.

Si algo del juego de datos no aparece donde el código dice que debería, la primera pregunta sigue
siendo si corrió con `KILKANA_SEED_DEMO`.

### Qué trae el juego de datos, y por qué esas cosas

No es «datos de relleno»: es lo que hace que las quince pantallas del panel se puedan **probar**.
Una pantalla cuya tabla está vacía no se puede mirar, y una funcionalidad construida que el juego
de datos no ejercita se rompe sin que nadie lo note.

| Lo que trae                                                                                               | Para qué existe                                                                                                                                                                                                                                                                                                                            |
| --------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Las **seis cuentas del panel**: admin, dirección, mesa, cuatro redactoras, dos colaboraciones y comunidad | Son los seis roles que abren el panel. Sin dirección y sin mesa no hay forma de probar lo que los separa: publicar lo ajeno, componer la portada, tocar la identidad. Y el cargo de cada firma cuadra con su rol —la editora jefe es `director`— porque una ficha que dice una cosa y unos permisos que dicen otra es una demo que engaña. |
| Nueve cuentas de **lector**, sin acceso al panel                                                          | El otro lado de la puerta: reciben token y no entran.                                                                                                                                                                                                                                                                                      |
| Dos **firmas sin cuenta** —colectivo y agencia— y ocho con ella                                           | Una firma es una página pública; un asiento es acceso. Sin las dos clases, la pantalla de firmas no enseña la diferencia que existe para enseñar.                                                                                                                                                                                          |
| **Ocho retratos** servidos desde el disco del inquilino                                                   | El retrato es uno de los cuatro requisitos de una página de autor. Con ninguno puesto, la pantalla solo enseña su lista de trabajo; con todos, no enseña la lista. Son caras reales de un banco de fotos y van con el juego de datos: sirven para probar el panel, no para ilustrar material comercial.                                    |
| **Dos revisiones** de una pieza y **tres rutas retiradas** (pieza, sección y firma)                       | El historial del editor y los tres bloques de «Route history». Las revisiones se crean **editando de verdad** con el servicio: es la misma transacción que corre en producción.                                                                                                                                                            |
| **Dos fotos en la papelera**                                                                              | `kilkana_media` es la única tabla con `deleted_at`: es lo único que una papelera puede tener dentro.                                                                                                                                                                                                                                       |
| Una sección **oculta**, una **en preparación** y una **viva y vacía**                                     | Los tres estados que decide la pantalla de secciones, y el caso legítimo que se confunde con un defecto.                                                                                                                                                                                                                                   |
| Fotos **sin usar** y **sin alt**                                                                          | Las dos vistas de la mediateca que existen para encontrar trabajo pendiente. Con todo completo salen a cero y no se pueden ni mirar.                                                                                                                                                                                                       |

Lo que **no** trae, y por qué: portadas compuestas (`kilkana_layouts`) ni revisiones de portada,
porque una demo que enseña el gesto de publicar la portada tiene que empezar sin nada publicado; ni
etiquetas de mediateca, que nacen con el uso.

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

| Frecuencia        | Comando                                                                                 |
| ----------------- | --------------------------------------------------------------------------------------- |
| cada minuto       | `kilkana:publish-scheduled`                                                             |
| cada noche, 04:10 | `kilkana:purge-trash` — lo que lleva más de treinta días en la papelera, fichero y fila |

Y un proceso que no es del scheduler: el contenedor `queue` corre `queue:work database` sobre la
base de la instalación (`DB_QUEUE_CONNECTION=commander`) y procesa lo que un periódico encola desde
una petición, hoy las tandas del boletín. Sin él, un número se marca enviado y no sale ningún
correo hasta que arranque; los trabajos esperan en `commander.jobs`.
| cada 10 min | `commander:refresh` |
| cada hora | `commander:usage:rollup --prune` |
| cada hora | `commander:git:sync` |
| cada 6 h | `commander:quota:measure` |

Los recorridos Kilkana reutilizan `SnkKilkanaTenantRunnerService`; un tenant roto no debe impedir
procesar los demás.

## Simulación de capacidad multi-tenant

`scripts/simulate.sh` caracteriza routing, cambio de conexión, carga y aislamiento con las
herramientas ya instaladas. Solo admite `APP_ENV=local` y dominios `.test`. Descubre las instancias
`ready/active` que haya: exige al menos dos Kilkana, y usa DemoApp si existe.

```bash
bash scripts/simulate.sh smoke
bash scripts/simulate.sh load read cross-package 10 300
bash scripts/simulate.sh load write same-package 5 100
bash scripts/simulate.sh login-load 5 100
bash scripts/simulate.sh security
bash scripts/simulate.sh rate-limit
bash scripts/simulate.sh matrix
```

`read`, `write` y `mixed` —una escritura cada veinte operaciones— se pueden cruzar con:

- `single`: una instancia Kilkana;
- `same-package`: todas las bases Kilkana en round-robin;
- `cross-package`: todas las instancias, DemoApp incluida.

`login-load` mide por separado el driver de hashing configurado y la emisión de tokens sobre un
usuario efímero. Genera el hash mediante Laravel, eleva temporalmente los límites API y de login
para que el throttle no falsee la capacidad y elimina al usuario con todos sus tokens al terminar.
No admite credenciales de usuarios existentes.

`matrix` prueba concurrencias `1, 5, 10, 25, 50`. Para que los `429` no oculten el techo de la
aplicación, recrea solo el servicio medido —`BENCH_SERVICE`, `app` por defecto— con límites altos
mediante un override Compose temporal y restaura la configuración normal en `trap`. Con
`TENANT_PORT=8444 BENCH_SERVICE=octane` (o `8445`/`octane-swoole`) el harness entero apunta a una
variante Octane. `rate-limit` comprueba por separado que doce accesos correctos
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

| Perfil/escenario            | Concurrencia |     Antes |  Ajustado | Cambio | p95 antes → ajustado |
| --------------------------- | -----------: | --------: | --------: | -----: | -------------------: |
| lectura cross-package       |           10 | 54,89 RPS | 91,24 RPS |  +66 % |         191 → 121 ms |
| escritura cross-package     |           10 | 30,12 RPS | 52,03 RPS |  +73 % |         327 → 176 ms |
| mixto aceptado por criterio |            5 | 45,19 RPS | 74,49 RPS |  +65 % |          125 → 76 ms |
| mixto máximo bruto          |           25 | 49,35 RPS | 94,45 RPS |  +91 % |         550 → 293 ms |

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

| Concurrencia | Logins |   RPS |    p95 | CPU PHP pico | RAM `app` pico | Errores |
| -----------: | -----: | ----: | -----: | -----------: | -------------: | ------: |
|            1 |    200 | 14,16 |  66 ms |         69 % |      79,23 MiB |       0 |
|            4 |    500 | 45,25 |  85 ms |        280 % |     136,90 MiB |       0 |
|            8 |  1.000 | 58,63 | 142 ms |        435 % |     156,90 MiB |       0 |

Los picos son muestras de `docker stats`; la reserva teórica de ocho verificaciones simultáneas es
152 MiB, más el proceso Laravel. El pico observado usa el 7,7 % del límite de 2 GiB de `app`.
`pm.max_children=8` limita las reservas Argon2 simultáneas; subir workers debe volver a medirse
porque el perfil aún consume 4,35 de los cuatro núcleos físicos bajo carga.

La referencia HTTP anterior de 56,37 RPS, p95 142 ms y 62,57 MiB con ocho workers correspondía en
realidad a bcrypt coste 10: el simulador histórico usaba el default de `password_hash`, aunque la
documentación indicaba coste 12. La comparación controlada posterior, con 1.000 logins y
concurrencia 8 en ambos casos, dio:

| Driver                       |   RPS |    p95 | CPU PHP pico | RAM `app` pico | Errores |
| ---------------------------- | ----: | -----: | -----------: | -------------: | ------: |
| bcrypt coste 12              | 24,32 | 377 ms |       ~600 % |      62,65 MiB |       0 |
| Argon2id anterior 64 MiB/2/1 | 31,58 | 267 ms |       ~600 % |     446,70 MiB |       0 |
| Argon2id actual 19 MiB/2/1   | 58,63 | 142 ms |        435 % |     156,90 MiB |       0 |

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

### A/B PHP-FPM frente a Octane del 05-09-2026

```bash
docker compose --profile octane --profile swoole up -d --build
bash scripts/octane-ab.sh all        # isolation, bench, soak, tune → compare.md en OUTPUT_DIR
```

Mismo i7-7700K y misma MariaDB de la baseline, metering activo. Medido cuando `app` aún era
php-fpm; hoy `app` es FrankenPHP y php-fpm es el perfil `fpm` en `:8444`, así que
`RUNTIMES` por defecto es `franken:8443:app fpm:8444:fpm swoole:8445:octane-swoole`. `scripts/octane-ab.sh` presta de
`simulate.sh` los usuarios efímeros, el modo capacidad y el muestreo de recursos, y añade `oha`
(contenedor, keepalive real) como generador de lectura; escritura, login y mixto siguen con curl
porque necesitan cuerpos únicos, y solo se compara generador contra generador: las filas curl de
FPM reproducen la baseline de agosto (74,7 RPS mixtos frente a 74,49), y las de oha enseñan el techo
que curl-por-proceso no alcanza. Los tres runtimes conviven detrás del mismo nginx (`:8443`,
`:8444`, `:8445`), se intercalan en cada iteración y se toma la mediana de tres.

| Escenario                  | Gen. |   c |   FPM | FrankenPHP     | Swoole        | p95 FPM → FrankenPHP → Swoole | RAM pico FPM/Fr/Sw |
| -------------------------- | ---- | --: | ----: | -------------- | ------------- | ----------------------------: | -----------------: |
| lectura, un inquilino      | oha  |  10 | 217,3 | 439,2 (+102 %) | 424,5 (+95 %) |                61 → 31 → 40 ms |     69/379/499 MiB |
| lectura, un inquilino      | oha  |  25 | 212,3 | 408,6 (+93 %)  | 406,4 (+91 %) |               146 → 79 → 95 ms |     63/381/482 MiB |
| lectura, dos inquilinos    | oha  | 5+5 | 169,3 | 248,0 (+46 %)  | 242,7 (+43 %) |                97 → 79 → 94 ms |     72/381/496 MiB |
| escritura                  | curl |  10 |  73,9 | 90,3 (+22 %)   | 91,1 (+23 %)  |               100 → 69 → 66 ms |     67/379/492 MiB |
| login                      | curl |   8 |  61,8 | 81,1 (+31 %)   | 75,0 (+21 %)  |              131 → 96 → 100 ms |    142/379/556 MiB |
| mixto                      | curl |   5 |  74,7 | 97,0 (+30 %)   | 99,5 (+33 %)  |                67 → 50 → 48 ms |     69/381/496 MiB |

Sin `5xx` ni timeouts en ninguna celda. Las dos lecturas simultáneas sobre inquilinos distintos
son el caso que un worker persistente puede mezclar: cada worker cambia de base en turnos
consecutivos, y aun así FrankenPHP gana un 46 %.

**Aislamiento.** Cien vueltas de alternancia estricta a concurrencia 1, 2, 4 y 8 —identidad por
token en A, token de A rechazado en B, identidad en B, inquilino fantasma `404` seguido del conteo
de piezas de B, `422` en A seguido del nombre del diario de B, desconexión a mitad de respuesta
seguida de identidad en A— dieron cero discrepancias en los tres runtimes. Doce logins fallidos en
A frenaron A con `429` y B siguió admitiendo un login correcto: la clave del freno lleva el
inquilino.

**Soak.** 100.000 peticiones repartidas entre dos inquilinos a concurrencia 4, reciclando workers
cada 500: FPM 177,6 RPS y 70,8 → 80,9 MiB; FrankenPHP 328,4 RPS y 223,2 → 225,0 MiB; Swoole 314,9
RPS y 489,0 → 489,2 MiB. Sin un solo fallo y sin crecimiento.

**Memoria × workers.** Lectura a concurrencia 25 con oha y mixto a 10 con curl por celda, en RPS
lectura / RPS mixto:

| Runtime    | Workers | 2 GiB     | 3 GiB     | 4 GiB     | RAM pico |
| ---------- | ------: | --------- | --------- | --------- | -------: |
| FrankenPHP |       8 | 440 / 115 | 411 / 107 | 408 / 119 |  245 MiB |
| FrankenPHP |      12 | 451 / 111 | 453 / 123 | 485 / 107 |  278 MiB |
| FrankenPHP |      16 | 501 / 117 | 491 / 114 | 465 / 122 |  327 MiB |
| Swoole     |       8 | 411 / 113 | 422 / 107 | 403 / 119 |  479 MiB |
| Swoole     |      12 | 450 / 112 | 426 / 118 | 481 / 121 |  605 MiB |
| Swoole     |      16 | 461 / 112 | 488 / 120 | 447 / 123 |  735 MiB |

La memoria del contenedor no mueve nada: las columnas quedan dentro del ruido entre corridas y el
pico nunca pasa de 740 MiB, así que 2 GiB sobra con holgura y no hay punto dulce que buscar por
ahí. Los workers sí: dieciséis dan a FrankenPHP un 10-14 % más de lectura que ocho, a diferencia
de FPM, donde diez y doce empeoraron. El mixto no se mueve en ninguna celda (110-123 RPS): con una
escritura cada veinte, mandan SQL y los cuatro núcleos físicos. El punto dulce local es FrankenPHP
con 12-16 workers y el límite de 2 GiB que ya tiene; `OCTANE_WORKERS` lo fija sin tocar Compose.

**Decisión.** FrankenPHP cumple todos los criterios de
[arquitectura](architecture.md#octane-medido-y-frankenphp-es-el-candidato): aislamiento limpio,
soak estable, lectura muy por encima del +40 %, mixto por encima del +25 % y sin regresión en
escritura ni login. Swoole no lo supera en más de un 15 % en ningún escenario y usa el doble de
memoria residente. **`app` corre sobre FrankenPHP desde el 06-09-2026**: la imagen de
`docker/php/Dockerfile` lleva el entrypoint (Composer, migraciones, catálogo) y sirve también a
scheduler, queue y provisioner; php-fpm queda como perfil `fpm` para repetir esta matriz, que debe
repetirse en el hardware de producción antes de fijar `OCTANE_WORKERS` allí.

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

**Desde el provisioner, y no desde `app`, a propósito.** El comando migra cada inquilino con la
conexión `provisioner` (`PROVISIONER_DB_*`), que es la credencial con DDL que solo tiene ese
servicio; el usuario de ejecución de cada periódico se queda con `SELECT, INSERT, UPDATE, DELETE`
sobre su base, y así una inyección o un proceso comprometido no puede tirar las tablas de un
diario. Lanzado desde `app`, que no lleva esa credencial, falla con `Access denied for user ''`:
no es un fallo del comando, es la separación funcionando. Un inquilino con el registro de
provisioning roto para el despliegue entero antes del primer DDL, que es lo que se quiere.

Requisitos adicionales:

- DNS y certificado wildcard;
- PHP-FPM, u Octane con una petición por worker (FrankenPHP worker mode o Swoole sin corrutinas);
  nunca corrutinas concurrentes dentro del proceso;
- la imagen de PHP con GD compilado con JPEG, WebP y AVIF, y con `ffmpeg` (el `Dockerfile` la
  trae): sin ellos la mediateca no abre un JPEG ni mide un vídeo;
- scheduler permanente o cron `schedule:run`, y reiniciar el worker de colas cada vez que cambie
  `config/kilkana.php` (guarda la configuración en memoria);
- `octane:reload` al final de cada deploy: los workers guardan la aplicación en memoria y sin él
  siguen sirviendo el código anterior;
- `TRUSTED_PROXIES` **y** un nginx que escriba él mismo `X-Forwarded-For`
  (`proxy_set_header X-Forwarded-For $remote_addr` hacia Octane, como en
  `docker/nginx/conf.d/app.conf.template`, o `fastcgi_param HTTP_X_FORWARDED_FOR $remote_addr`
  con php-fpm). Son dos piezas y no una: sin la variable,
  `Request::ip()` es la IP del proxy para todo el mundo y el contador de lectores distintos de
  una pieza (`readers`) colapsa a uno por navegador y día, con `opens` y `engaged` sumando bien,
  así que nadie lo nota, y los frenos públicos por IP (`kilkana-subscribe`, `kilkana-view`) pasan a
  ser un solo cubo por inquilino: una pieza con tráfico corta el alta y la lectura a todos. Con la
  variable y sin la línea de nginx, la cabecera la pone el cliente y la IP la elige quien llama,
  que es peor: de ella cuelgan `throttle:login`, `throttle:api` y
  los enlaces firmados del boletín. Estrechar la variable a la red de docker no lo arregla:
  Symfony devuelve la primera entrada no confiable de la cadena, y con una sola entrada puesta
  por el cliente esa es la que gana;
- worker exclusivo de provisioning con DDL;
- `retry_after` mayor que el timeout del job;
- backup de Commander, bases tenant y storage antes de cambios relevantes.

## Gotchas

- `CoreUserModel.password` ya tiene cast `hashed`; no uses `Hash::make` al asignarlo.
- El usuario es `Packages\Core\Models\CoreUserModel`, no `App\Models\User`.
- Dominios quedan en route cache; regenera tras cambiarlos.
- Los workers de Octane guardan la aplicación en memoria: un cambio de código, config o `.env` no
  se ve hasta `php artisan octane:reload` dentro de `app`.
- Un package nuevo requiere autoload/recache y sync de aplicaciones.
- Un token tenant no autentica en Commander ni viceversa.
- Cambiar un plan de catálogo no propaga límites a instancias hasta provisionar/cambiar plan.
- Una suscripción negociada agrupa billing y puede tener cuotas compartidas; no agrupa bases,
  usuarios ni las cuotas operativas propias de cada instancia.
