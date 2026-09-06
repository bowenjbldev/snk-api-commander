# Arquitectura

## Forma del sistema

Snake API Commander es un monolito Laravel modular, multi-tenant por base de datos.

| Módulo | Responsabilidad | Ruta |
|---|---|---|
| Core | descubrimiento, routing, auth base, conexión tenant, estado y comandos | `src/packages/Core` |
| Commander | control plane, provisioning, billing, cuotas y observabilidad | `src/packages/Commander` |
| Kilkana | producto editorial compartido | `src/packages/Kilkana` |
| overlay | diferencias de un cliente sobre un producto | `src/packages/<Cliente>/<Paquete>` |

`src/app` es solo el shell de Laravel. El [modelo de Commander](commander-model.md) define la
semántica de cuenta, cliente, aplicación, instancia y contrato.

## Identidad y routing

```text
Host:  diario-norte.commander.test      → client.slug = diario-norte
Path:  /api/v1/kilkana/public/posts     → application.slug = kilkana
Fila:  commander_instances.id           → identidad operativa estable
BD:    diario_norte_kilkana             → frontera de datos
Código src/packages/Kilkana             → application.package_name = Kilkana
```

Existe como máximo una instancia por `(client_id, application_id)`. Estado, plan, cuotas, logs y
jobs usan su ID numérico.

Los hosts reservados son `api`, `commander` y `www`. El cliente es un único label DNS de hasta 63
caracteres. Base y usuario se validan siempre contra `[a-z0-9_]{1,64}` antes de usarse en DDL.

El cliente sale del `Host`, y solo del `Host`. De las cabeceras de proxy se confía en
`X-Forwarded-For`, `-Port` y `-Proto` (`bootstrap/app.php`), nunca en `X-Forwarded-Host` ni
`-Prefix`: con `TRUSTED_PROXIES='*'`, Symfony prefiere la reenviada sobre `Host` y cualquier
llamador elegiría la base de otro inquilino con una cabecera. Ningún proxy la necesita: todos fijan
`Host` al del inquilino. Las tres de confianza las escribe nginx pisando lo que traiga el cliente
en todos los vhosts —`X-Forwarded-For`, porque los frenos por IP y el conteo de lectores cuelgan de
ella, y `-Proto`, porque de ella salen las URL absolutas y los enlaces firmados—;
`simulate.sh security` y `octane-ab.sh isolation` comprueban que el `Host` manda.

### Dominios

El control plane privado usa `commander.<BASE_DOMAIN>`. La API de todos los productos usa
`{cliente}.<TENANT_BASE_DOMAIN>/api/v1/{aplicación}`; si `TENANT_BASE_DOMAIN` no existe, cae a
`BASE_DOMAIN`. El dominio pertenece a la plataforma y el segmento de la ruta elige el package.

En local:

```text
commander.snakepc.test                            control plane (BASE_DOMAIN=snakepc.test)
diario-norte.commander.test/api/v1/kilkana         producto Kilkana del cliente diario-norte
diario-norte.commander.test/api/v1/otro-producto   otro producto del mismo cliente, otra BD
```

`TENANT_BASE_DOMAIN=commander.test` es común a todas las aplicaciones. Añadir un producto no
requiere otro dominio ni un mapa aplicación→dominio. Kilkana es una implementación en
`src/packages/Kilkana`, no el dominio base de Commander.

La web pública de un periódico puede usar otro dominio, como `demo.kilkana.test` o uno propio.
Esa URL de producto (`kilkana_settings.tenant_url`) no cambia el host con el que Core resuelve
la API. `APP_URL` tampoco sustituye los dominios explícitos del router. Un frontend en otro
origen debe llamar o hacer proxy a la URL de API de plataforma; si llama desde el navegador,
su origen debe estar autorizado en CORS.

Los dominios propios de clientes aún no están implementados. Su modelo, flujo de alta, TLS,
seguridad y migración SEO están cerrados como diseño futuro en [Dominios propios](domains.md).

## Aplicaciones, packages y overlays

Una aplicación es provisionable si su implementación compartida o específica contiene
`routes/api.php`.

| Código | Resultado |
|---|---|
| `packages/Kilkana` | producto compartido para cualquier cliente |
| anterior + `packages/ElMundo/Kilkana` | base compartida con diferencias de El Mundo |
| solo `packages/Acme/Shop` | producto exclusivo de Acme |
| ninguno | alta `422` |

El orden de composición es:

```text
migraciones: Core → package compartido → overlay
seeders:      Core → package compartido → overlay
config:       compartida → overlay
rutas:        overlay exacto → compartida wildcard como fallback
```

Los providers compartidos pueden cargarse globalmente. Los providers de overlay no se cargan:
un binding global de un cliente contaminaría a los demás.

`commander:applications:sync` registra una aplicación por package de negocio y sincroniza sus
métricas. El comando marca como `missing` el código que desaparece; no borra historia. Las lecturas
de API no hacen sync ni escriben en la base.

## Resolución tenant

`CoreResolveInstanceFromHostMiddleware`:

1. extrae el cliente del host y recibe el slug de aplicación de la ruta;
2. busca una instancia `ready` con cliente/aplicación activos y código disponible;
3. valida base, usuario y contraseña runtime;
4. fija `CoreActiveInstanceSupport`;
5. configura y selecciona la conexión `tenant`;
6. aplica overrides de configuración compartidos y del overlay;
7. ejecuta la ruta;
8. restaura conexión, configuración y contexto en `finally`.

Pending, failed, deprovisioning y deprovisioned responden `404`; credenciales incompletas en una
fila ready responden `503`.

Los modelos Commander fijan `protected $connection = 'commander'`, por lo que siguen leyendo la
base de control después de cambiar la conexión por defecto.

## Aislamiento y autenticación

```text
commander
├── operador Commander y sus tokens
└── tablas commander_*

diario_norte_kilkana
├── usuarios, roles y tokens de Diario Norte
└── tablas kilkana_*

demo_kilkana
└── identidades y datos distintos
```

No hay `tenant_id` ni bypass por token. Sanctum busca el token en la base activa. El mismo correo
en dos diarios son dos usuarios locales y dos tokens independientes. El `last_used_at` del token
se escribe al minuto y no en cada petición (`CorePersonalAccessTokenModel`): es la «última
actividad» de una cuenta, no un libro mayor, y así una lectura del panel no es una escritura.

El proceso HTTP usa el usuario de control solo sobre `commander`. El worker `provisioner` recibe
la credencial DDL y crea un usuario runtime por instancia con únicamente `SELECT`, `INSERT`,
`UPDATE` y `DELETE` sobre su base. La contraseña se cifra en Commander y nunca se serializa.

## Estado y middleware

`CoreEnsureActiveMiddleware` consulta `service_status` por instancia:

- `active`: respuesta normal;
- `grace`: respuesta normal con `X-Instance-*`;
- `suspended` o grace vencida: `402`;
- error al verificar estado: `503` fail-closed.

El rate limit general usa `instance_id` más usuario autenticado o IP. El login limita únicamente
respuestas `401`: cinco fallos por cuenta normalizada y diez por IP cada minuto, siempre dentro de
la instancia. Así los accesos correctos de usuarios que comparten NAT no consumen el presupuesto,
pero ni rotar IP contra una cuenta ni rotar cuentas desde una IP evita el bloqueo. Los límites se
configuran bajo `core.rate_limits` y la cache compartida los mantiene coherentes entre réplicas.
Un identificador inexistente ejecuta una comprobación contra un hash dummy del mismo algoritmo,
de modo que respuesta y coste no revelen si la cuenta existe.
`CoreMeteringMiddleware` captura la misma identidad antes de que el resolutor restaure el contexto
y encola la fila al terminar la respuesta; `CoreMeteringBufferSupport` la escribe al momento o en
lote según `core.metering.batch`. Un fallo de metering nunca modifica la respuesta.
`memory_kb` es el pico del heap de la petición, reiniciado al entrar: distingue una ruta ligera de
una pesada dentro de un runtime, pero **no se compara entre runtimes** —el mismo endpoint da unos
2 MB bajo php-fpm y 7-9 bajo un worker que arrastra su heap, y crece un 25 % entre el worker recién
reciclado y el que lleva 500 peticiones—; lo comparable es la diferencia entre rutas.

## Procesos

| Proceso | Credenciales y función |
|---|---|
| `app` | HTTP sobre Octane/FrankenPHP (worker mode) y comandos sin DDL tenant |
| `provisioner` | cola `provisioning`, creación/borrado de bases y usuarios |
| `scheduler` | mediciones, rollups, Git y tareas Kilkana |
| `nginx` | TLS, wildcard tenant y proxy |
| `db` | MariaDB con `commander` y bases por instancia |

La conmutación de conexión/configuración es estado global mutable. Está soportada en PHP-FPM y en
workers secuenciales —Octane sobre FrankenPHP o Swoole, que sirven una petición por worker—. No
debe usarse con peticiones concurrentes dentro del mismo proceso (corrutinas de Swoole) sin
contexto aislado por corrutina.

### Capacidad y conexiones

PDO no usa conexiones persistentes ni existe un pool externo. Cada request resuelve la instancia
en `commander`, abre la conexión tenant al primer acceso SQL y `DB::purge('tenant')` la cierra al
salir. Esa apertura no manda el `use` que Laravel añade tras el handshake
(`use_db_after_connecting`): el DSN ya lleva la base y era una ida y vuelta más por petición.
Por tanto, el número de tenants registrados no determina las conexiones simultáneas; la
aproximación conservadora es dos conexiones por worker HTTP ocupado, más scheduler, provisioner
y operación. Sí existe churn de autenticación/conexión proporcional a las peticiones.

La baseline local de agosto de 2026, detallada en [Operación](operations.md), pasó de cinco workers
dinámicos a ocho estáticos calibrados sobre un i7-7700K. La lectura subió de 55 a 91 RPS, la
escritura de 30 a 52 y el escalón mixto aceptado por latencia de 45 a 74 RPS. Diez y doce workers
empeoraron throughput/p95: el límite actual son los cuatro núcleos físicos y el coste por request,
no memoria, cuota de CPU ni MariaDB. Pruebas de 1.200 lecturas con uno y tres tenants de Kilkana y
con dos packages quedaron dentro de un 2 %; el cambio de conexión/package no añadió una
penalización material.

Commander sigue siendo un punto central por petición: resolución, estado y un insert de metering.
Con `CORE_METERING_SAMPLE_RATE=1`, 100 RPS producen 8,64 millones de logs/día. La tabla local ocupa
aproximadamente 233 bytes por fila entre datos e índices, del orden de 2 GB/día y 60 GB para la
retención predeterminada de 30 días, antes de margen operativo. El rollup diario conserva todas
las duraciones del bucket en arrays PHP para calcular p95; `chunkById` limita cada lectura, pero no
ese acumulado. Ese algoritmo no es viable a millones de peticiones/día con `memory_limit=512M` y
debe cambiarse antes de sostener ese volumen.

### Octane: medido, y FrankenPHP es el candidato

Laravel Octane 2 mantiene la aplicación cargada y sirve una petición por worker. FrankenPHP en
worker mode (glibc) y Swoole (sin corrutinas) se midieron el 05-09-2026 contra PHP-FPM con el mismo
código, el mismo nginx y TLS, la misma cuota de CPU y los mismos ocho workers reciclados cada 500
peticiones; los tres conviven como perfiles Compose y la matriz completa, con el barrido de memoria
y workers, está en [Operación](operations.md#ab-php-fpm-frente-a-octane-del-05-09-2026). En
resumen: la lectura dobla el throughput con la mitad de p95, el tráfico mixto sube un 30 %,
escritura y login entre un 20 y un 30 % —los limitan SQL y Argon2, no el arranque—, y ni la
alternancia estricta de inquilinos ni 100.000 peticiones con reciclaje mostraron una fuga o un
crecimiento de memoria. FrankenPHP rinde igual o mejor que Swoole con la mitad de memoria
residente, es MIT, es proyecto oficial de PHP y no exige compilar una extensión. **Es el runtime
de `app` desde el 06-09-2026**; php-fpm y Swoole quedan como perfiles para repetir la matriz, y
el deploy termina con `octane:reload` porque los workers guardan la aplicación en memoria.

El riesgo era de aislamiento, no de memoria: el resolutor modifica la conexión por defecto, las
credenciales tenant, la configuración de package/overlay y el singleton de instancia activa, y
Laravel reinicia su estado conocido pero no el que crea la aplicación. Lo que hizo posible el
worker persistente también es correcto en FPM y en la CLI que recorre inquilinos:

- el resolutor vacía la colección de permisos de Spatie al conmutar
  (`clearPermissionsCollection()`): `initializeCache()` cambia clave y almacén pero no la
  colección en memoria, y un proceso que sirve dos inquilinos seguidos le daría al segundo los
  permisos del primero. `CoreResolverPermissionIsolationTest` lo fija. Lo que sí sobrevive por
  worker es la colección ya **hidratada** de cada base (`CorePermissionRegistrarSupport`): la
  clave es la de la caché, que lleva el nombre de la base, en cada petición se compara con el
  contenido de la caché y se sirven copias; un rol editado se ve en la siguiente petición y dos
  inquilinos no pueden compartir entrada. `CorePermissionRegistrarMemoTest` lo fija;
- el metering toma el inicio de `REQUEST_TIME_FLOAT`, que es por petición en cualquier SAPI; una
  constante de arranque sería el uptime del worker;
- el store de caché `database` se ancla a `commander` con `DB_CACHE_CONNECTION`: sin eso toma la
  conexión por defecto, que durante una petición es la del inquilino, y el gestor de caché la
  memoriza más allá de la petición. Esa ruta se estrenó el 06-09-2026 al medirla: con
  `CACHE_STORE=database` las seis claves de la prueba —freno, kill-switch y permisos— fueron a
  `commander.cache` y la tabla `cache` del inquilino se quedó a cero, que es lo que esa variable
  tiene que garantizar. Lo que la prueba desaconseja es usar ese store para todo:
  [cuesta un 68 % en una petición pública](operations.md#coste-común-por-petición-del-06-09-2026)
  y el culpable es el freno.

Lo que Octane ya cubre sin código propio: sandbox de configuración por petición, reinicio de los
guards de autenticación, caché `array` y una aplicación nueva para el gestor de base de datos. El
sandbox corta en los dos sentidos: un singleton que deba sobrevivir a la petición —la cola de
metering y el registrar de permisos— se resuelve al arrancar el worker (`octane.warm`), porque
resuelto dentro de una petición nace y muere en su sandbox. El
`finally` del resolutor sigue siendo la fuente de verdad en FPM y purga la conexión tenant en
ambos. `route()->forgetParameter('client')` no es un riesgo: `Route::bind()` reasigna los
parámetros en cada petición antes de que corra ningún middleware.

Los criterios que evalúa `scripts/octane-ab.sh`, y que cualquier runtime tiene que cumplir a la
vez para considerarse compatible:

- alternar tenants, packages y tokens bajo respuestas normales, excepciones, `404`, `429`,
  streaming y desconexiones sin fuga de conexión, config, usuario ni permisos;
- mantener memoria estable durante al menos 100.000 peticiones, reciclando workers cada 500;
- repetir la misma matriz FPM y aceptar el cambio solo con mejora material de RPS y p95, sin
  `5xx`, timeouts ni errores de aislamiento;
- conservar la purga de PDO tenant y no introducir un pool ilimitado por credencial.

Lo que queda vigilado: la conexión `commander` persiste por worker —un handshake menos por
petición; si MariaDB la cierra por `wait_timeout` tras horas ociosas, Laravel reconecta y repite
la consulta **solo fuera de transacción**: dentro de una, la excepción sale tal cual. Lo cubre el
orden de cada petición: la primera consulta a `commander` es la del resolutor o la del token, las
dos fuera de transacción, y ahí se reconecta antes de que ningún controlador abra una. Una ruta
cuya primera consulta a `commander` fuera transaccional fallaría una vez por worker tras el
timeout; si apareciera, `DisconnectFromDatabases` en `OperationTerminated` cierra la conexión por
petición al precio de un handshake—; `terminate()` corre antes de cerrar la respuesta, así que el
metering escribe en lote por worker (`CoreMeteringBufferSupport`, 20 filas o 5 s, volcado al parar
el worker) en vez de un `INSERT` por petición; y el límite es la CPU, no la
memoria: dieciséis workers ganan un 10-14 % en lectura sobre ocho y nada en mixto, mientras el pico
de FrankenPHP con dieciséis fue de 327 MiB frente a los 2 GiB del contenedor. Octane no elimina
SQL, metering ni hashing: el 2× es de la lectura, no de todos los endpoints, y una segunda réplica
en CPU separada sigue siendo la vía predecible para duplicar capacidad agregada.

Referencias: [Octane y estado persistente](https://laravel.com/docs/13.x/octane#dependency-injection-and-octane),
[FrankenPHP clásico frente a worker](https://frankenphp.dev/docs/migrate/#step-5-consider-worker-mode-optional)
y [tuning de FrankenPHP](https://frankenphp.dev/docs/performance/).

### Hash de contraseñas y capacidad de login

Commander usa Argon2id con 19 MiB, tiempo 2 y un hilo; `HASH_VERIFY=true` rechaza hashes de otro
algoritmo. No existe un censo fiable que permita afirmar qué usa la mayoría de SaaS: bcrypt sigue
siendo común y es el default de Laravel, mientras que OWASP recomienda Argon2id para sistemas
nuevos. El contenedor PHP 8.3 instalado soporta ambos.

Microbenchmark local del 17-08-2026 sobre el i7-7700K, diez verificaciones secuenciales por
configuración y sin HTTP:

| Algoritmo | Parámetros | Tiempo por `password_verify` |
|---|---|---:|
| bcrypt | coste 10, usado por el benchmark HTTP histórico | 43,38 ms |
| bcrypt | coste 12 | 171,41 ms |
| Argon2id Commander | 19 MiB, tiempo 2, 1 hilo | 24,16 ms |
| Argon2id anterior | 64 MiB, tiempo 2, 1 hilo | 87,17 ms |
| Argon2id default Laravel | 64 MiB, tiempo 4, 1 hilo | 178,81 ms |

La configuración de 19 MiB/2/1 cumple el mínimo OWASP. Reduce el margen adicional de resistencia
offline del perfil anterior, pero mejora el coste medido de bcrypt-12 y limita a unos 152 MiB la
memoria Argon2 simultánea con ocho workers. Debe recalibrarse sobre el hardware de producción y no
reducirse por debajo de este suelo solo para ganar RPS.

La carga HTTP real midió 79,23 MiB con una verificación concurrente, 136,90 MiB con cuatro y
156,90 MiB con ocho. El último valor es el 7,7 % del límite del contenedor. Con ocho workers se
procesaron 1.000 logins correctos a 58,63 RPS, p95 142 ms y sin errores. Frente a 64 MiB/2/1,
mejoró el throughput un 86 %, el p95 un 47 % y la RAM un 65 %.
Una cifra de bcrypt medida con un usuario efímero de coste 10 —el default de PHP— no es
comparable con estas: Laravel cifra a coste 12, y `login-load` genera el hash con el driver
configurado por la aplicación. Una repetición controlada con bcrypt-12 dio 24,32 RPS,
p95 377 ms y 62,65 MiB: Argon2id 19 MiB/2/1 mejora el throughput un 141 % y el p95 un 62 % frente
al coste real de Laravel, usando 2,5 veces más RAM con ocho verificaciones simultáneas.

Como todavía no existe producción, las identidades locales bcrypt se reinician directamente y no
se mantiene un modo mixto. Un despliegue futuro que ya contenga bcrypt necesitará una transición
explícita: verificar solo bcrypt/Argon2id, rehacer el hash tras un login correcto y terminar
forzando Argon2id. `HASH_VERIFY=false` no debe quedar permanente. El hash dummy para cuentas
inexistentes usa la configuración activa y conserva la resistencia a enumeración.

No se debe reducir el coste por debajo del suelo validado para ganar RPS. Passkeys o un proveedor
OIDC pueden reducir los logins locales y añadir resistencia al phishing, pero son cambios de
producto, recuperación de cuenta y operación; no sustituyen esta migración por sí solos.

Referencias: [hashing de Laravel](https://laravel.com/docs/13.x/hashing),
[almacenamiento de contraseñas de OWASP](https://cheatsheetseries.owasp.org/cheatsheets/Password_Storage_Cheat_Sheet.html).

## Invariantes de implementación

- Solo `provisioning_status = ready` enruta.
- El worker provisioner es el único con DDL.
- Las credenciales admin tenant son explícitas en cada alta o retry.
- Commander nunca genera ni clona código de producto.
- Cuenta agrupa contratos; cliente/instancia siguen siendo las fronteras de datos y operación.
- La conexión tenant, config runtime y contexto activo se restauran en `finally`.
- Storage tenant conocido usa `storage/app/public/media/{database}`.
- Los enlaces firmados tenant incluyen el host del cliente.

## Riesgos conocidos

- La publicación de jobs no usa outbox. Se despacha después del commit y existe estado `failed` +
  retry estable, pero no reconciliador automático.
- Una baja puede dejar recursos si el worker falla; la instancia queda `failed` con `last_error`.
- La restricción “plan/métrica/contrato pertenecen a la misma aplicación/cuenta” se valida en la
  API; no todas son expresables con una FK simple.
- Los tests SQLite no validan DDL, permisos, collation ni `information_schema` de MariaDB.
- El borrado solo conoce el storage `media/{database}`.
- Las clases Kilkana conservan el prefijo histórico `SnkKilkana*`; no afecta al aislamiento.
- El proceso HTTP y el arranque/migrador comparten `commander_app`: no tiene acceso a bases tenant,
  pero conserva DDL sobre `commander`. Producción debe separar credencial runtime y migrador.
- Solo `app` tiene presupuesto explícito de CPU/memoria/procesos. MariaDB, Nginx, scheduler y
  provisioner aún compiten sin cuotas en el host local.
- Nginx local emite `X-Content-Type-Options`, pero deja HSTS al terminador TLS de producción. CSP
  solo será relevante en hosts que sirvan contenido de navegador.
- El metering crudo y su rollup diario son el primer límite de almacenamiento/memoria al acercarse
  a 100 RPS, antes que el número de conexiones MariaDB.
- Los tokens Sanctum caducados dejan de autenticar, pero aún no existe una poda programada que
  recorra todas las bases tenant; debe añadirse si el volumen histórico de logins empieza a crecer.
