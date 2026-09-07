# Historial de rendimiento de 2026

> Estado: evidencia histórica. Conserva las condiciones, resultados y límites de las pasadas
> locales. Para la configuración vigente y cómo repetir las pruebas, consulta
> [Rendimiento](../performance.md).

## Baseline local y tuning del 17-08-2026

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

## A/B PHP-FPM frente a Octane del 05-09-2026

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
[arquitectura](../architecture.md): aislamiento limpio,
soak estable, lectura muy por encima del +40 %, mixto por encima del +25 % y sin regresión en
escritura ni login. Swoole no lo supera en más de un 15 % en ningún escenario y usa el doble de
memoria residente. **`app` corre sobre FrankenPHP desde el 06-09-2026**: la imagen de
`docker/php/Dockerfile` lleva el entrypoint (Composer, migraciones, catálogo) y sirve también a
scheduler, queue y provisioner; php-fpm queda como perfil `fpm` para repetir esta matriz, que debe
repetirse en el hardware de producción antes de fijar `OCTANE_WORKERS` allí.

**Segunda pasada del 06-09-2026**, ya con `app` en FrankenPHP y con metering en lote
(`CORE_METERING_BATCH=20`), doce workers y keepalive de nginx al upstream. Misma máquina, mismo
guion (`RUNTIMES="franken:8443:app fpm:8444:fpm" bash scripts/octane-ab.sh bench`), mediana de
tres, sin `5xx` ni `429` en ninguna celda:

| Escenario               | Gen. |   c |   FPM | FrankenPHP     | p95 FPM → FrankenPHP | RAM pico FPM/Fr |
| ----------------------- | ---- | --: | ----: | -------------- | -------------------: | --------------: |
| lectura, un inquilino   | oha  |  10 | 187,4 | 527,1 (+181 %) |          81 → 32 ms |     67/427 MiB |
| lectura, un inquilino   | oha  |  25 | 158,7 | 515,8 (+225 %) |         212 → 71 ms |     65/429 MiB |
| lectura, dos inquilinos | oha  | 5+5 | 145,1 | 269,1 (+85 %)  |        131 → 111 ms |     74/429 MiB |
| escritura               | curl |  10 |  66,2 | 78,8 (+19 %)   |         130 → 89 ms |     83/430 MiB |
| login                   | curl |   8 |  51,6 | 73,8 (+43 %)   |        167 → 118 ms |    111/430 MiB |
| mixto                   | curl |   5 |  67,8 | 91,5 (+35 %)   |          75 → 56 ms |     73/448 MiB |

La lectura pasa de ×2 a casi ×3 sobre FPM y el p95 bajo carga cae a un tercio; escritura y login
siguen mandados por MariaDB y ganan lo mismo que el día anterior. Los absolutos de las dos pasadas
no se comparan entre sí (la máquina rendía distinto), solo cada pasada con su propio FPM.

## A/B con contenido y tráfico editorial

```bash
node --test scripts/realistic-load.test.mjs
bash scripts/realistic-ab.sh
# Prueba corta del harness y su limpieza, no una medida de capacidad:
SECONDS_PER_RUN=2 ROUNDS=1 bash scripts/realistic-ab.sh
```

`realistic-ab.sh` reutiliza las identidades efímeras de `simulate.sh`. Requiere runtimes locales
ya construidos y al menos un tenant con veinte noticias publicadas. Excluye los listados vacíos
en vez de poblar clientes existentes. Fija el código PHP al commit `HEAD` al arrancar, en un
snapshot de solo lectura compartido por los dos runtimes; conserva ese snapshot y el hash con
los resultados. No incluye cambios PHP sin commit.

Condiciones: PHP de las imágenes instaladas, ocho workers y reciclaje cada 500 en ambos,
metering activo con lote uno, `APP_DEBUG=false`, sin watcher ni caché de config/rutas en ambos.
Las rutas de caché temporales no sobrescriben las cachés existentes. Mantiene los límites de
CPU/RAM de Compose. Sigue siendo una comparación de despliegues: FPM usa Alpine/NTS y
FrankenPHP Debian/ZTS, con sus respectivos proxies FastCGI/HTTP; no aísla el efecto de la libc.

El generador usa Node sin dependencias, HTTP/1.1 con keepalive y `Accept-Encoding: identity` en
ambos. La mezcla sintética es 40 % detalles de noticias (rotando veinte URLs), 25 % listados
paginados, 15 % filtro por sección, 10 % listado editorial, 5 % mediateca, 4 % guardado de
borradores y 1 % login. Los borradores son nuevos y privados: el guardado envía unos 4 KB de
texto y ejercita saneado y revisiones. No publica, manda correo ni modifica artículos existentes.

Cuatro rondas, alternando FPM/FrankenPHP, con concurrencias ocho y veinticuatro. Cada celda
calienta la mezcla durante cinco segundos y mide cuarenta y cinco; `ROUNDS` y `SECONDS_PER_RUN`
permiten alargarla. Es carga a concurrencia fija sin pausas, no un modelo de llegadas de lectores.
RPS = respuestas válidas / tiempo monotónico global, incluido el drenaje final. Guarda cada
latencia, status, tamaño y validación de contenido; cualquier error HTTP, de red o de respuesta
invalida la tanda. El preflight verifica cuerpos no vacíos, IDs iguales en ambos runtimes,
identidad del usuario y rechazo de su token en otro tenant.

Los JSON y CSV quedan en `OUTPUT_DIR` (por defecto `/tmp/commander-benchmark-*`), con resumen
`report.json`, versiones, imágenes, snapshot y muestras de CPU/RAM. El resumen separa medianas,
rango entre rondas y latencias por endpoint. Puede regenerarse con
`node scripts/realistic-load.mjs report <directorio>`. Los resultados de calentamiento no entran.

Al salir elimina los borradores/revisiones, actividad e identidades temporales y sus tokens,
restaura los runtimes y el estado previo de FPM. Los logs de metering permanecen. No mide
subidas de medios, transformaciones de imágenes, CDN, cientos de tenants ni estabilidad de horas.
El generador y MariaDB comparten máquina: no ejecutar builds ni otras cargas durante la prueba.

### Resultado local: 6 de septiembre de 2026

Pasada completa sobre `cf07b03a2de08ddc72bf94f5e65b588a2174265e`, PHP 8.3.33 en ambos,
i7-7700K (4 núcleos/8 hilos), 31 GiB de RAM y MariaDB 11.5.2. Cada runtime conservó su límite
de 6 CPU y 2 GiB. Un tenant con contenido: `demo.commander.test`; `diario-norte` se excluyó por
estar vacío. Dataset: 532 artículos originales (476 publicados durante la pasada), veinte URLs
de detalle y borradores temporales. Se midieron **100.657 peticiones, sin errores**, en cuatro
rondas por combinación. Medianas entre rondas, sin sumar RPS de intervalos distintos:

| Concurrencia | FPM, req/s | FrankenPHP, req/s | Mejora | p95 FPM → FrankenPHP |
| -----------: | ---------: | ----------------: | -----: | ------------------: |
| 8            | 109,5      | 175,6             | +60,4 % | 113,0 → 77,8 ms     |
| 24           | 105,0      | 171,4             | +63,2 % | 307,3 → 201,5 ms    |

Rangos de throughput entre rondas: FPM 101,4–111,2 y FrankenPHP 172,1–178,6 req/s con c=8;
FPM 103,5–111,1 y FrankenPHP 150,3–180,0 con c=24. La concurrencia adicional no aumenta la
capacidad en esta mezcla y sí empeora la latencia. RAM máxima muestreada: **104 MiB FPM frente
a 443 MiB FrankenPHP**. El generador consumió como máximo el 13,6 % de un núcleo.

Los datos brutos locales están en `/tmp/commander-realistic-ab-20260906`, incluido `report.json`.
No extrapolar estas cifras a lectores simultáneos ni a cientos de tenants. Sustituyen como
referencia editorial a la prueba de listado vacío; los 527 req/s anteriores no representan
este trabajo. Tampoco prueban por sí solos aislamiento sostenido ni estabilidad de horas.

Incidencia del harness: la primera limpieza omitía el último tenant porque MariaDB consumía el
stdin del bucle. Durante esta pasada había 48 borradores sintéticos (24 de la prueba corta y
24 de la completa), iguales para ambos runtimes. Se eliminaron después junto con sus revisiones
y actividad, conservando los 532 artículos originales. El script corregido redirige stdin,
comprueba los residuos antes de borrar identidades y conserva el registro privado de recuperación
si falla la limpieza. La regresión está cubierta por el test del harness y una nueva ejecución
corta contra MariaDB: 532 artículos al terminar, ningún borrador ni usuario temporal restante,
sin actividad de estas pasadas y con la configuración normal restaurada.

## Coste común por petición del 06-09-2026

```bash
docker compose exec -T app php < scripts/common-cost.php   # un proceso con la app cargada, N=300
LABEL=antes bash scripts/common-cost-http.sh                 # oha: tres endpoints, c=10, 20 s × 2 rondas
```

Lo que toda petición tenant repite antes de tocar negocio —resolutor, conexión tenant, freno,
kill-switch, auth, permisos y metering— medido componente a componente dentro de un proceso con la
app cargada (lo que hace un worker de Octane, sin su sandbox ni nginx ni TLS), y por HTTP con tres
endpoints del mismo periódico que solo se diferencian en esa parte común: `public/site` (público),
`auth/me` (token) y `tags` (token y `can`). `common-cost.php` crea un token y lo borra, y borra las
filas de metering que deja; `common-cost-http.sh` levanta el freno recreando `app` como hace
`simulate.sh` y lo restaura al salir. Mismo i7-7700K, FrankenPHP con doce workers, caché `file`.

Antes, mediana por petición en un proceso:

| Componente | ms | Nota |
| --- | ---: | --- |
| resolutor: SELECT de la instancia con dos `whereHas` | 0,83 | conexión `commander` viva; en SQL crudo con un JOIN, 0,38 |
| descifrado, overrides de config y clave de permisos | 0,20 | `glob(config/*.php)` cuesta 0,01: caché de directorio |
| conexión tenant: abrir y cerrar | 0,87 | TCP, auth, `use` y `SET`; una ida y vuelta son 0,25 |
| freno `throttle:api` sobre caché `file` | 0,31 | dos lecturas y una escritura con `flock` |
| kill-switch sobre caché `file` | 0,06 | |
| metering, INSERT de veinte filas repartido | 0,16 | con lote 1 serían 2,1 por petición |
| Sanctum: token, usuario y UPDATE de `last_used_at` | 1,68 | sin el UPDATE, 1,38 |
| Spatie: leer la caché e hidratar 63 permisos y 7 roles | 0,62 | reconstruir desde SQL, 26; solo con la caché vacía |
| `can()`: permisos directos y roles, dos SELECT | 1,69 | `model_has_permissions` está vacía en todas las bases |

Unos 2,4 ms comunes en una petición pública y 6,4 en una autenticada con permiso, sobre un suelo
de framework de unos 3 ms (el `404` de un inquilino fantasma cuesta 3,7). Cinco cambios, todos en
Core y ninguno en el aislamiento: el resolutor consulta la instancia con un SQL de un JOIN
(0,83 → 0,38); la conexión tenant no manda el `use` tras el handshake (0,80 → 0,71);
`CorePermissionRegistrarSupport` recuerda por worker la colección hidratada de cada base y en cada
petición solo lee la caché y la compara (0,62 → 0,12); `CoreUserModel::hasPermissionTo` pregunta
primero al rol y a los directos solo si el rol dice que no (1,69 → 0,92, una consulta); y
`CorePersonalAccessTokenModel` escribe `last_used_at` al minuto (1,68 → 1,18, y una escritura
menos por cada lectura del panel).

| Petición completa, un proceso | Antes | Después |
| --- | ---: | ---: |
| `public/site` | 6,69 ms | 6,38 ms |
| `auth/me` | 15,96 ms | 15,51 ms |
| `tags` con `can index tags` | 22,31 ms | 19,40 ms, seis → cinco consultas |
| inquilino fantasma, `404` | 3,71 ms | 3,31 ms |

Y la pregunta directa, después de los cambios: cuánto tarda la API en llegar al controlador del
package. El harness engancha el `ControllerDispatcher` y parte cada petición en enrutar
(middleware global y matching), middleware de ruta (resolutor, freno, kill-switch, auth y `can:`)
y package. En una petición pública la conexión tenant (~0,7 ms) se abre en la primera consulta del
package y cuenta ahí; con token la abre Sanctum dentro del freno y cuenta en la entrada.

Con el JIT ya puesto y el proceso recién arrancado:

| Petición, un proceso | Enrutar | Middleware de ruta | Hasta el controlador | Package | Total |
| --- | ---: | ---: | ---: | ---: | ---: |
| `public/site` | 0,4 ms | 1,5 | **1,9** | 2,8 | 5,2 |
| `auth/me` | 0,6 | 4,1 | **4,7** | 6,7 | 12,0 |
| `tags`, `can` en el controlador | 0,9 | 4,1 | **5,0** | 10,0 | 15,6 |
| `settings`, `can:` en la ruta | 1,1 | 5,3 | **6,5** | 2,1 | 9,2 |

Sin JIT esas entradas eran 2,4 / 5,4 / 5,8 / 7,6. Y son el **suelo**, no la media: el mismo script
medido tras 1.500 peticiones en el mismo proceso da 3,4 en la pública, por presión de heap. Un
worker vive entre esos dos puntos según dónde esté de su ciclo de 500 peticiones.

La entrada de `settings` no es mayor porque haga más trabajo, sino porque su `can:` corre como
middleware de ruta; en `tags` esa misma comprobación cuenta como package. La frontera es útil, no
es una ley física.

Repartida esa entrada por piezas, con JIT: enrutado y middleware global 0,40; SELECT de la
instancia 0,42 (0,57 con los dos `whereHas` de antes); descifrado, `glob`, overrides y clave de
permisos 0,11; abrir y cerrar la conexión tenant 0,72; freno 0,23; kill-switch 0,05; Sanctum 1,06;
`can()` 1,05; metering amortizado 0,13. **Lo que se paga por ser multi-tenant son 1,25 ms** —
resolver quién es y conectarse a su base—, o 1,43 contando kill-switch y metering. El resto lo paga
cualquier API de Laravel con tokens y roles, tenga un inquilino o mil.

Lo que queda tras el controlador (0,7 a 1,2 ms) es el `finally` del resolutor, las cabeceras del
kill-switch y del freno y el `terminate` del metering.

Por HTTP, con `oha` a concurrencia 10 sobre los cuatro núcleos saturados, la diferencia entre
rondas seguidas de la misma variante llega al 15 %, así que solo vale intercalar: tres rondas
alternando el orden antes/después, quince segundos por celda y medianas. Y la máquina tiene que
estar quieta de verdad: la barra de estado de Claude Code (`npm exec claude-powerline`) quema un
núcleo un par de segundos con cada llamada, y una pasada hecha mientras se trabaja en la sesión
salió un 13 % por debajo de su propia línea base.

| Escenario HTTP, c=10 | Antes | Después | p95 antes → después |
| --- | ---: | ---: | ---: |
| `public/site` | 554,1 rps | 590,6 (+6,6 %) | 29,8 → 26,4 ms |
| `auth/me` | 264,6 rps | 289,2 (+9,3 %) | 62,0 → 53,8 ms |
| `tags` con `can` | 199,2 rps | 230,7 (+15,8 %) | 76,5 → 67,9 ms |

**JIT de opcache**, mismo día y mismo método, con `app` recreada con y sin un `.ini` extra
(`opcache.jit=tracing`, búfer 128M) e intercalando tres rondas. En proceso, con la máquina quieta:
`public/site` 5,86 → 5,44 ms, `auth/me` 14,3 → 12,3, `tags` 17,2 → 16,2, y la entrada al
controlador entre un 10 y un 15 % menos. Una primera pasada con otras aplicaciones abiertas en el
host exageraba el efecto al doble: la que vale es la quieta.

| Escenario HTTP, c=10 | JIT off | JIT tracing | p95 off → tracing |
| --- | ---: | ---: | ---: |
| `public/site` | 617,3 rps | 651,6 (+5,6 %) | 26,0 → 24,8 ms |
| `auth/me` | 300,1 rps | 325,6 (+8,5 %) | 51,8 → 49,0 ms |
| `tags` con `can` | 247,6 rps | 245,0 (−1 %) | 60,9 → 63,5 ms |

Gana donde manda PHP y no mueve nada donde manda MariaDB. Queda fijado en `docker/php/commander.ini`
con 64 MiB de búfer: 400 peticiones de una ruta usaron 1 MiB, y es memoria compartida del proceso,
una sola vez. Vale también para scheduler, queue y provisioner, que montan el mismo fichero.

## Qué le cuesta a la API cada página del BFF, del 06-09-2026

```bash
bash scripts/bff-cost.sh                 # calle y panel
SCOPE=public LABEL=despues bash scripts/bff-cost.sh
```

El frontend de Kilkana es un BFF: no toca ninguna base, llama a esta API. `bff-cost.sh` no
instrumenta nada —anota el último id de `commander_request_logs`, pide la página al BFF y se queda
con las filas que aparecieron entre medias—, así que atribuye a cada pantalla sus llamadas, su
tiempo y su peso. Recrea `app` con lote de metering 1, porque con el lote de 20 las filas se
quedan en el búfer del worker, y sin freno, porque un barrido de dieciocho páginas pasa de 240
llamadas. Restaura todo al salir.

Línea base, con `demo` y la máquina quieta:

| Página | Llamadas | ms de API | KB |
| --- | ---: | ---: | ---: |
| portada | 4 | 143 | 247 |
| artículo | 5 | 75 | 28 |
| sitemap | 2 | 84 | 59 |
| `/admin` tablero | 14 | 490 | 33 |
| `/admin/posts/{id}` | 16 | 445 | 116 |
| `/admin/front` | 13 | 420 | 260 |
| resto del panel | 10-12 | 206-344 | 23-78 |

Dos lecturas que condicionan el trabajo. **En la calle manda una sola llamada**: `public/posts`
con `count=100` son 114 de los 143 ms de la portada, y reaparece igual en `/admin/front`. **En el
panel manda el número de viajes**: nueve llamadas de layout antes de pintar nada, que suman entre
220 y 250 ms porque además compiten entre ellas por los cuatro núcleos. Cada una paga entre 4,7 y
6,5 ms de coste común antes de llegar a su lógica.

Dos avisos sobre el harness. El panel se mide por su ruta de datos (`__data.json`) y no por la
página: su layout exporta `ssr = false`, así que el servidor devuelve un armazón de 2 KB sin tocar
la API. Y calienta cada página una vez, lo que con doce workers deja fríos a la mayoría: una celda
suelta puede salir al triple, y esas se repiten en vez de creérselas.

Lo bajado hasta ahora contra esa línea base:

| Endpoint | Antes | Después | Qué era |
| --- | ---: | ---: | --- |
| `public/posts?count=100` | 77,0 ms · 235 KB | 66,4 ms · 217 KB | traía los cuerpos y los escondía al serializar |
| `public/sitemap` | 76 ms · 5 consultas | 29 ms · 1 consulta | el archivo entero, con seis cargas ansiosas, para devolver dos campos |
| `newsletter` (panel) | 66,6 ms | 26,5 ms | una consulta por número y cinco más por las relaciones del modelo |

Y el panel, tras los dos endpoints compuestos —`panel/bootstrap` y `panel/dashboard`—, contado
en llamadas, que es lo que ahí manda:

| Pantalla | Llamadas antes | Después | ms de API antes | Después |
| --- | ---: | ---: | ---: | ---: |
| tablero | 14 | 2 | 490 | 124 |
| posts | 10 | 2 | 260 | 90 |
| editor | 16 | 8 | 445 | 384 |
| mediateca | 12 | 4 | 344 | 196 |
| portada | 13 | 5 | 420 | 261 |
| cuentas | 12 | 5 | 327 | 174 |
| secciones | 11 | 3 | 325 | 120 |
| ajustes | 11 | 3 | 206 | 94 |
| boletín | 10 | 2 | 231 | 80 |
| papelera | 10 | 2 | 262 | 130 |
| **total** | **119** | **36** | | |

**La latencia de una pantalla apenas se mueve con esto, y hay que decirlo así.** Las llamadas ya
salían en paralelo, de modo que en una máquina quieta la novena no costaba reloj: `ms_pagina` se
queda donde estaba, y en alguna pantalla sale peor por el ruido de la propia medida. Lo que se
quita es carga —ochenta y tres peticiones con su peaje de 4,7 a 6,5 ms, su conexión a la base del
inquilino y su competencia por cuatro núcleos—, y eso se ve con gente dentro y con los workers
fríos, no en una tirada suelta. La columna de llamadas es la honesta.

Las dos primeras, aisladas y con la mediana de veinticinco pasadas. La del boletín, **alternando
A-B-A** contra el contenedor —la misma petición con y sin el cambio, recreando `app` entre
pasadas—, y hace falta hacerlo así: una tirada entera del harness deriva hasta un 30 % entre
ejecuciones, y esa deriva se come el resultado. En la tirada donde el boletín bajó de 81 a 45 ms,
los otros ocho endpoints de esa misma pantalla subieron todos. Medido con los cinco números de
`demo`; el listado pasó de 25 consultas con dos números y 45 con seis, a 12 con cualquiera de los
dos, así que la distancia crece con cada boletín que se manda.

## Campos elegidos por quien llama, del 06-09-2026

`?fields=` recorta la fila a lo que el cliente parsea. Medido contra `demo`, un listado de cien
piezas:

| | Campos por fila | Peso |
|---|---:|---:|
| sin `fields` | 23 | 222.236 b |
| con `fields` | 14 | 199.604 b |

Un 10 %. Los nueve que se van son los cuatro de SEO, la imagen social, la hora de retirada y
`noindex`: hacen falta al pintar una pieza, no cien. El bulto ya se había ido antes con los
cuerpos, que eran 239 KB.

La lista de campos vive en el BFF, al lado del parser que los lee. Antes eran dos listas gemelas
en dos repositorios y divergieron: seis campos pasaron a `undefined` sin que fallara nada.

## El store de caché, y por qué condiciona una segunda réplica

```bash
docker compose exec -T -e CACHE_STORE=file     app php < scripts/common-cost-cache.php
docker compose exec -T -e CACHE_STORE=database app php < scripts/common-cost-cache.php
```

`CACHE_STORE=file` es local al contenedor, así que una segunda réplica no comparte lo que de esa
caché cuelga: el freno, el kill-switch y la caché de permisos de Spatie. El sustituto previsto era
el store `database` anclado a `commander`. Medido el 06-09-2026, no sirve tal cual:

| Operación cruda del store | `file` | `database` |
| --- | ---: | ---: |
| leer una clave presente | 0,03 ms | 0,29 |
| escribir | 0,21 | 0,29 |
| `increment`, lo que hace el freno | 0,19 | **2,3** |

| Por petición | `file` | `database` |
| --- | ---: | ---: |
| kill-switch | 0,04 ms | 0,29 |
| freno (`tooManyAttempts` + `hit`) | 0,26 | **3,03** |
| memo de permisos (leer, comparar, copiar) | 0,11 | 0,35 |
| `public/site` completa | 5,73 | 9,65 (+68 %) |
| `auth/me` completa | 12,92 | 16,73 (+29 %) |
| `tags` completa | 16,71 | 21,55 (+29 %) |

El culpable es `DatabaseStore::increment`, que envuelve un `SELECT … FOR UPDATE` y un `UPDATE` en
una transacción: cuatro idas y vueltas y un commit durable por petición. Los casi 4 ms que suma a
una pública son el triple de todo el impuesto multi-tenant.

Lo que la medida sí deja claro es **qué necesita compartirse de verdad**, que no es lo mismo para
los tres:

- el **kill-switch** HTTP ya no lo necesita: reutiliza la fila recién leída por el resolutor.
  La medición de la tabla anterior es histórica; las consultas explícitas por ID de comandos y
  cuotas conservan su caché de 30 segundos;
- los **permisos** sí: su TTL es de 24 horas y `forgetCachedPermissions()` solo vacía la réplica
  que atendió la edición, de modo que otra serviría permisos viejos durante un día. Es la única
  rotura real, y moverlos solos al store compartido cuesta 0,25 ms por petición, no cuatro;
- el **freno** a medias: el general (240/min) pasaría a ser 240 por réplica, que degrada un
  control defensivo sin romperlo; el del login (cinco fallos por cuenta) sí es de seguridad, pero
  vive en una petición que ya cuesta 24 ms de Argon2, así que ahí los 3 ms no se notan.

Conclusión operativa: con una sola réplica, `file` es lo correcto y lo más rápido. Para la segunda,
Redis es la vía recomendada para permisos y frenos compartidos; su latencia debe medirse en ese
despliegue, no asumirse por debajo de 0,1 ms. Si no lo hubiera, el
apaño medido es dejar `file` de general y mover al store compartido solo los permisos y el freno
del login. Lo que no debe hacerse es cambiar `CACHE_STORE` a `database` y ya.

Lo que queda es estructural y no se ha tocado: abrir y cerrar la conexión tenant (0,8 ms, y el
criterio de [arquitectura](../architecture.md) es conservar la purga en vez de
un pool por worker), la consulta del resolutor (0,4, memorizable por worker a cambio de segundos de
retraso al desaprovisionar), el freno sobre `file` (0,25; no hay APCu y `array` no se comparte
entre workers) y el suelo del framework (~3 ms de enrutado, pipeline y respuesta).

## Regresión del contexto multitenant

```bash
docker compose exec -T app php artisan test --filter='CorePermissionRegistrarMemoTest|CoreResolverPermissionIsolationTest'
docker compose exec -T app php < scripts/core-resolver-check.php
```

Las pruebas fijan la copia independiente de permisos **y roles anidados**, el estado resuelto sin
caché/SQL adicional, los cambios de estado dentro del contexto y las consultas explícitas por ID.
También comprueban que cambios de credenciales, baja, cliente inactivo o código ausente se ven
sin memorizar el registro entre peticiones.

La comprobación MariaDB solo admite `APP_ENV=local` y hosts `.test`, requiere al menos dos tenants
ready y no escribe datos de negocio ni crea tokens. Alterna hasta tres tenants durante 330
resoluciones; provoca 66 excepciones con transacción abierta y deja una variable de sesión para
verificar que ninguna llega a la siguiente conexión. Debe conservar un PDO Commander, abrir 330
conexiones tenant distintas, purgarlas siempre y ejecutar exactamente una consulta Commander por
resolución. No añade un pool ni elimina `purge()`.

Pasada local del 06-09-2026: todas las comprobaciones correctas, tres tenants, mediana 2,74 ms y
p95 3,44 ms en las 300 iteraciones posteriores al calentamiento. Incluye consultas de diagnóstico
y variables de sesión: **no es throughput HTTP ni una mejora porcentual frente al A/B anterior**.
