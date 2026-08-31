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
Host:  diario-norte.kilkana.test        → client.slug = diario-norte
Path:  /api/v1/kilkana/public/posts     → application.slug = kilkana
Fila:  commander_instances.id           → identidad operativa estable
BD:    diario_norte_kilkana             → frontera de datos
Código src/packages/Kilkana             → application.package_name = Kilkana
```

Existe como máximo una instancia por `(client_id, application_id)`. Estado, plan, cuotas, logs y
jobs usan su ID numérico.

Los hosts reservados son `api`, `commander` y `www`. El cliente es un único label DNS de hasta 63
caracteres. Base y usuario se validan siempre contra `[a-z0-9_]{1,64}` antes de usarse en DDL.

### Dominios

El control plane privado usa `commander.<BASE_DOMAIN>`. Los tenants usan
`{cliente}.<TENANT_BASE_DOMAIN>`; si el segundo no existe, cae a `BASE_DOMAIN`.

En local:

```text
commander.snakepc.test       control plane
diario-norte.kilkana.test    tenant Kilkana
```

No existe `commander.kilkana.test`. Actualmente hay un único dominio tenant por despliegue; un
futuro segundo SaaS con dominio propio requerirá un mapa aplicación→dominio.

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
en dos diarios son dos usuarios locales y dos tokens independientes.

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
y escribe el log al terminar la respuesta. Un fallo de metering nunca modifica la respuesta.

## Procesos

| Proceso | Credenciales y función |
|---|---|
| `app` | HTTP y comandos sin DDL tenant |
| `provisioner` | cola `provisioning`, creación/borrado de bases y usuarios |
| `scheduler` | mediciones, rollups, Git y tareas Kilkana |
| `nginx` | TLS, wildcard tenant y proxy |
| `db` | MariaDB con `commander` y bases por instancia |

La conmutación de conexión/configuración es estado global mutable. Está soportada en PHP-FPM y
workers secuenciales. No debe usarse con requests concurrentes dentro del mismo proceso
(Octane/Swoole) sin contexto aislado por coroutine.

### Capacidad y conexiones

PDO no usa conexiones persistentes ni existe un pool externo. Cada request resuelve la instancia
en `commander`, abre la conexión tenant al primer acceso SQL y `DB::purge('tenant')` la cierra al
salir. Por tanto, el número de tenants registrados no determina las conexiones simultáneas; la
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
ese acumulado. Ese algoritmo no es viable a millones de peticiones/día con `memory_limit=128M` y
debe cambiarse antes de sostener ese volumen.

### Octane y FrankenPHP: evaluación futura

PHP-FPM sigue siendo el único runtime HTTP soportado. Más workers no ofrecen otro salto: ocho
fueron el óptimo medido y diez o doce empeoraron throughput y p95. FrankenPHP clásico simplificaría
Nginx/FastCGI, pero vuelve a arrancar Laravel por petición y no es candidato razonable a duplicar
el rendimiento. La prueba con potencial es Laravel Octane sobre FrankenPHP en worker mode, que
mantiene la aplicación cargada; no se ha implementado ni medido en este proyecto.

El riesgo es de aislamiento, no solo de memoria. El resolutor modifica la conexión por defecto,
credenciales tenant, configuración de package/overlay y el singleton de instancia activa. El
`finally` actual es una buena base para workers secuenciales, pero un estado propio no restaurado
podría contaminar la siguiente petición del mismo worker. Laravel reinicia su estado conocido,
no garantiza limpiar el estado global creado por la aplicación.

Una prueba de concepto deberá usar FrankenPHP con glibc, sin Swoole ni tareas concurrentes, y no
se considerará compatible hasta cumplir simultáneamente:

- alternar tenants, packages y tokens bajo respuestas normales, excepciones, `404`, `429`,
  streaming y desconexiones sin fuga de conexión, config, usuario ni permisos;
- mantener memoria estable durante al menos 100.000 peticiones, reciclando workers cada 500;
- repetir la misma matriz FPM y aceptar el cambio solo con mejora material de RPS y p95, sin
  `5xx`, timeouts ni errores de aislamiento;
- conservar la purga de PDO tenant y no introducir un pool ilimitado por credencial.

Octane puede reducir mucho el coste de bootstrap en lecturas simples, pero no elimina SQL,
metering ni hashing. Por eso un 2× es plausible para lecturas y tráfico mixto, no una expectativa
para todos los endpoints. Una segunda réplica en CPU separada continúa siendo la vía predecible
para duplicar capacidad agregada.

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
La referencia bcrypt anterior daba 56,37 RPS porque el usuario efímero se creó por error con el
default PHP de coste 10, no con el coste 12 de Laravel; `login-load` genera ahora el hash mediante
el driver configurado por la aplicación. Una repetición controlada con bcrypt-12 dio 24,32 RPS,
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
