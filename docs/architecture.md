# Arquitectura

> Estado: implementado. Las cifras y decisiones de capacidad están en
> [Rendimiento](performance.md).

## Forma del sistema

Snake API Commander es un monolito Laravel modular con aislamiento por base de datos.

| Módulo | Responsabilidad | Ruta |
|---|---|---|
| Core | discovery, routing, autenticación base y conexión tenant | `src/packages/Core` |
| Commander | control plane, provisioning, contratos, cuotas y observabilidad | `src/packages/Commander` |
| Kilkana | producto editorial compartido | `src/packages/Kilkana` |
| overlay | diferencias de cliente sobre un producto | `src/packages/<Cliente>/<Paquete>` |
| Commander Web | SPA privado del operador, actualmente sobre mock | `commander-web` |

`src/app` es el shell de Laravel. La semántica de negocio está en
[Modelo de Commander](commander-model.md).

## Identidad y routing

```text
Host  diario-norte.commander.test         → client.slug = diario-norte
Path  /api/v1/kilkana/public/posts        → application.slug = kilkana
Fila  commander_instances.id              → identidad operativa
BD    diario_norte_kilkana                 → frontera de datos
Código src/packages/Kilkana               → package_name = Kilkana
```

Existe como máximo una instancia por `(client_id, application_id)`. Estado, plan, cuotas, logs y
jobs usan su ID numérico.

El control plane usa `commander.<BASE_DOMAIN>`. La API tenant usa
`{cliente}.<TENANT_BASE_DOMAIN>/api/v1/{aplicación}`; si no se configura
`TENANT_BASE_DOMAIN`, cae a `BASE_DOMAIN`.

En local:

```text
commander.snakepc.test                              control plane
diario-norte.commander.test/api/v1/kilkana          Kilkana
diario-norte.commander.test/api/v1/otro-producto    otro producto y otra BD
```

Kilkana es un package, no un dominio. Una URL pública como `demo.kilkana.test` pertenece al
frontend y no determina la conexión de la API.

## Frontera del Host

El cliente sale exclusivamente de `Host`. Laravel no confía en `X-Forwarded-Host` ni
`X-Forwarded-Prefix`.

Nginx sobrescribe `X-Forwarded-For` y `X-Forwarded-Proto` antes de enviarlos a Laravel. Esto es
necesario porque la IP alimenta rate limits y medición de lectores, y el protocolo entra en URLs
absolutas y firmas.

Los hosts reservados son `api`, `commander` y `www`. El cliente es un label DNS válido de
hasta 63 caracteres. Base y usuario se validan contra `[a-z0-9_]{1,64}` antes de usarse en DDL.

Los dominios propios aún no están implementados. Su contrato futuro está en
[Diseño de dominios](design/custom-domains.md).

## Query string

La resuelve `spatie/laravel-query-builder` con sus nombres por defecto: `include`, `fields`,
`sort`, `filter`. No hay vocabulario propio encima; quien conoce el paquete conoce esta API.

`CoreBaseApiController::queryBuilder()` los aplica y el controlador solo declara qué admite:
`$allowedIncludes`, `$allowedFields`, `$allowedSorts`, `$allowedFilters`. Todo es lista blanca y
lo que no se declara no se aplica.

`newQuery()` sigue siendo el gancho por el que un recurso acota lo suyo —por propiedad, por
permiso— antes de que nadie pida nada. Quien llama elige dentro de eso, nunca fuera.

`decorate()` es el otro gancho, y va **después**: agregados y precargas —un `withCount`, una
subconsulta con `addSelect`— se cuelgan ahí porque `allowedFields()` hace `select()` y borra
todo lo seleccionado antes. Lo que se cuelga no es una columna: no se pide por su nombre y sale
siempre que el recurso lo calcule (`seat`, `posts_count`, `variants`, `views_total`).

Tres piezas no son del paquete:

- `summary` pide agregados de la colección; los del paquete van por relación.
- El techo de `?fields=` es de la puerta, no del modelo: `SnkKilkanaPostService::FIELDS` para el
  panel, `SnkKilkanaPublicService::FIELDS` para la calle, `SnkKilkanaMediaService::FIELDS` para
  la mediateca y el `$allowedFields` de cada catálogo. En una puerta anónima el techo es
  **además el valor por defecto**, porque el paquete no recorta cuando nadie se lo pide.
- `per_page` y el sobre de la paginación: el paquete no pagina.

Un valor desconocido cae al defecto y nunca produce 422. La excepción es `?fields=`, que no tiene
bandera para ignorarlo: un campo no declarado lanza.

## Forma de salida

La declara un `JsonResource` por recurso, en `Http/Resources/` del paquete. Cada campo va con
`whenHas()` —`array_key_exists()` sobre lo cargado—, así que **lo que la consulta no seleccionó
sale ausente, no nulo**: quien lee distingue «no lo pedí» de «está vacío».

El modelo no declara la forma. `$with`, `$appends` y el `$hidden` de presentación son globales por
modelo, y la misma fila la leen un anónimo, el listado del panel y el editor: un solo juego de
reglas para tres preguntas obliga a poner listas blancas encima. `$hidden` de secretos sí se
queda, como red de los caminos que todavía serializan el modelo directo.

Un Resource no consulta la base: la precarga y los agregados los hace el servicio en una tanda para
toda la página y los cuelga de la fila. Lo que compone un documento en vez de serializar un modelo
—el plan, los ajustes, la composición, la papelera— no lleva Resource.

## Packages y overlays

Una aplicación es descubrible cuando su implementación compartida o específica contiene
`routes/api.php`.

| Código | Resultado |
|---|---|
| `packages/Kilkana` | producto compartido para cualquier cliente |
| compartido + `packages/Acme/Kilkana` | Acme usa diferencias concretas |
| solo `packages/Acme/Shop` | producto exclusivo de Acme |
| ninguna implementación | provisioning responde 422 |

Orden de composición:

```text
migraciones  Core → package compartido → overlay
seeders      Core → package compartido → overlay
config       compartida → overlay
rutas        overlay exacto → compartida wildcard
```

Los providers compartidos se pueden cargar globalmente. Los providers de overlays no: un binding
global de un cliente contaminaría al resto.

`commander:applications:sync` registra aplicaciones y métricas desde el código. Si una
implementación desaparece, la marca como `missing` sin borrar planes ni historia.

## Resolución tenant

`CoreResolveInstanceFromHostMiddleware` concentra el flujo:

1. extrae cliente del host y aplicación de la ruta;
2. busca una instancia `ready` con cliente y aplicación activos;
3. valida base, usuario y contraseña runtime;
4. fija `CoreActiveInstanceSupport`;
5. configura la conexión `tenant`;
6. aplica configuración compartida y overlay;
7. ejecuta el endpoint;
8. restaura conexión, configuración, permisos y contexto en `finally`.

Pending, failed, deprovisioning y deprovisioned responden 404. Una instancia ready con
credenciales incompletas o una conexión no verificable responde 503.

El wildcard `{client}` se consume antes de llamar al controlador. Sanctum y Spatie consultan
después la base tenant activa.

## Conexiones

Los modelos `Commander*` fijan la conexión `commander`. Core y los modelos de producto usan la
conexión tenant seleccionada durante la petición.

PDO no es persistente y no existe un pool externo. Una petición ocupada puede mantener:

- una conexión Commander del worker;
- una conexión tenant abierta al primer acceso SQL.

La conexión tenant se purga al terminar. El número de tenants registrados no crea conexiones
permanentes; mandan la concurrencia y el número de workers.

El usuario HTTP de Commander accede a la base de control. Cada tenant usa una credencial runtime
cifrada con SELECT, INSERT, UPDATE y DELETE sobre su base. Solo el provisioner recibe DDL.

## Aislamiento y autenticación

```text
commander
├── operador y tokens Commander
└── tablas commander_*

diario_norte_kilkana
├── usuarios, roles y tokens del diario
└── tablas kilkana_*

demo_kilkana
└── identidades y datos independientes
```

No hay `tenant_id` ni bypass por token. Sanctum busca el token en la base activa. El mismo correo
en dos instancias representa dos usuarios distintos.

La caché de permisos incorpora la base tenant en su clave. Al conmutar, el resolutor reinicia la
colección del registrar; la memoización por worker conserva copias independientes de permisos y
roles por base.

`CorePersonalAccessTokenModel` actualiza `last_used_at` como máximo una vez por minuto para no
convertir cada lectura autenticada en una escritura.

## Estado, rate limits y metering

`CoreEnsureActiveMiddleware` usa el estado de la instancia recién resuelta:

| Estado | Resultado |
|---|---|
| `active` | petición normal |
| `grace` vigente | normal con cabeceras `X-Instance-*` |
| `suspended` o grace vencida | 402 |
| error de verificación | 503 fail-closed |

No ejecuta una segunda consulta Commander ni memoriza la instancia entre peticiones.

El rate limit general usa `instance_id` y usuario o IP. El login limita fallos por cuenta e IP;
los accesos correctos no consumen ese presupuesto. Una cuenta inexistente verifica un hash dummy
del algoritmo activo para reducir enumeración por tiempo.

`CoreMeteringMiddleware` conserva instancia, ruta, estado, duración, actor, IP, bytes y memoria.
En Octane, `CoreMeteringBufferSupport` escribe en lote por worker y se vuelca al detenerlo. Un
fallo de metering nunca cambia la respuesta.

## Procesos

| Proceso | Responsabilidad |
|---|---|
| `app` | Octane/FrankenPHP y comandos sin DDL tenant |
| `scheduler` | tareas periódicas Commander y Kilkana |
| `queue` | trabajos generales sobre la cola Commander |
| `provisioner` | jobs de alta/baja y DDL tenant |
| `nginx` | TLS, hosts y proxy |
| `db` | MariaDB de control y tenants |

La conexión, configuración y contexto son estado mutable. El diseño admite PHP-FPM y workers
secuenciales de FrankenPHP o Swoole. No admite varias peticiones concurrentes dentro del mismo
proceso sin aislamiento por corrutina.

Octane reinicia configuración, guards y servicios conocidos entre peticiones. Los singletons que
deben sobrevivir —buffer de metering y registrar de permisos— se calientan al arrancar el worker.
El `finally` del resolutor sigue siendo la fuente de verdad tanto en FPM como en Octane.

## Límites conocidos

- Enqueue no usa outbox; un fallo puede dejar pending o failed hasta retry.
- Deprovisioning puede dejar recursos y conserva `last_error`.
- Algunas igualdades cuenta/aplicación/plan se validan en API, no con una FK simple.
- El storage genérico no es declarativo; la baja solo conoce `media/{database}`.
- La credencial HTTP conserva DDL sobre Commander; producción debe separar runtime y migrador.
- Metering crudo y cálculo de p95 necesitan evolucionar antes de millones de peticiones diarias.
- Una segunda réplica necesita permisos y frenos compartidos; cambiar todo el cache store a
  database no es una solución eficiente.
- Los tokens tenant expirados dejan de autenticar, pero aún falta una poda que recorra todas las
  bases tenant.
- `Model::preventAccessingMissingAttributes()` no está activo. Con él, leer un atributo cuya
  columna no se seleccionó lanza en vez de devolver nulo, que es la clase de fallo que se comió
  el sitemap. Probado: rompe 25 pruebas en 10 ficheros, cada una un sitio que lee lo que no pidió
  o una proyección corta. Es trabajo propio, no un interruptor.
