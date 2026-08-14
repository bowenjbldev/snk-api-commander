# Arquitectura

## Forma del sistema

Snake API Commander es un monolito modular y multi-tenant por base de datos. Un despliegue
Laravel carga la infraestructura y todos los productos disponibles, pero resuelve la instancia
activa en cada petición.

| Pieza | Responsabilidad | Ubicación |
|---|---|---|
| Core | descubrimiento, rutas, auth base, permisos, conexión tenant, metering, estado y comandos | `src/packages/Core` |
| Commander | control plane: cuentas comerciales, clientes, planes, provisioning, billing, cuotas y observabilidad | `src/packages/Commander` |
| Kilkana | producto editorial compartido y sus migraciones | `src/packages/Kilkana` |
| Overlay | diferencias de un cliente sobre un producto compartido | `src/packages/<Cliente>/<Paquete>` |

La carpeta `src/app` es el shell mínimo de Laravel. La lógica de plataforma o de negocio vive
en `src/packages`.

## Identidad y direccionamiento

Una instancia se identifica por el par `(cliente, paquete)`:

```text
Host:  elmundo.example.com       → commander_clients.slug = elmundo
Path:  /api/v1/kilkana/...       → commander_packages.slug = kilkana
Fila:  commander_packages.id     → identidad operativa estable
BD:    elmundo_kilkana           → datos aislados de la instancia
```

Una `commander_account` es la identidad comercial superior obligatoria. En el caso normal tiene
una cabecera; en un grupo puede contener varias. No interviene en el host, el nombre de BD ni la
resolución:

```text
account Grupo Demo
├── client diario-norte  → diario-norte.<dominio>  → diario_norte_kilkana
├── client diario-centro → diario-centro.<dominio> → diario_centro_kilkana
└── client diario-sur    → diario-sur.<dominio>    → diario_sur_kilkana
```

No existen clientes sin `account_id`. Si el alta recibe un cliente nuevo sin una cuenta existente,
Commander crea una cuenta 1:1 con sus datos comerciales. Las cuotas, estado, suscripción y
credenciales continúan perteneciendo a cada fila de instancia, no a la cuenta agrupadora.

Los subdominios `api`, `commander` y `www` están reservados. El slug de cliente acepta un único
nivel DNS, de 1 a 63 caracteres, con letras minúsculas, números y guiones. El nombre de la base
se normaliza a `[a-z0-9_]{1,64}`.

`name` identifica el tipo lógico en StudlyCase (`Kilkana`); `slug` identifica su segmento de
ruta (`kilkana`); `id` identifica una fila concreta. Las acciones de estado, uso, logs, plan y
de-provisioning usan siempre el `id` numérico para no confundir dos instancias del mismo producto.

### Ámbitos de dominio: SnakePC y Kilkana

`snakepc.test` representa el entorno local y portfolio técnico del propietario. Commander forma
parte de ese ámbito y su URL actual es `commander.snakepc.test`.

Kilkana es un SaaS con identidad propia. La estructura local actual es:

```text
commander.snakepc.test          control plane privado
elmundo.kilkana.test            instancia Kilkana de El Mundo
elconfidencial.kilkana.test     instancia Kilkana de El Confidencial
```

No existe `commander.kilkana.test`: Commander administra Kilkana, pero no pertenece a su marca
pública. `BASE_DOMAIN` gobierna el control plane y `TENANT_BASE_DOMAIN` las rutas tenant; Core,
nginx y TLS respetan esa separación. Si `TENANT_BASE_DOMAIN` no está definido, Core cae a
`BASE_DOMAIN` para mantener compatibles los despliegues anteriores.

El límite deliberado actual es un dominio tenant por despliegue. Cuando exista un segundo SaaS
con dominio de marca propio, se sustituirá por un mapa producto → dominio; no se anticipa hasta
que aparezca ese segundo caso.

## Productos compartidos y overlays

Hay tres formas válidas de implementar un producto:

| Código desplegado | Resultado para el cliente |
|---|---|
| `packages/Kilkana` | implementación compartida |
| compartido + `packages/ElMundo/Kilkana` | compartido con diferencias de El Mundo |
| solo `packages/Acme/Shop` | producto exclusivo de Acme |
| ninguno | no provisionable; respuesta `422` |

El overlay no es una copia del producto. Debe contener únicamente rutas, configuración,
migraciones, seeders o clases que cambian. Sus clases pueden extender las compartidas.

Para que una implementación sea descubrible debe existir `routes/api.php` en el compartido o en
el producto exclusivo. Un directorio que solo contiene configuración no crea un producto nuevo;
sí puede actuar como overlay de un producto compartido que ya tiene rutas.

### Orden de composición

El orden es siempre base y después overlay:

```text
migraciones: Core → producto compartido → overlay
seeders:      Core → producto compartido → overlay
config:       producto compartido → overlay
rutas:        overlay exacto → compartido wildcard como fallback
```

Las rutas del overlay se registran primero para su dominio exacto. La ruta compartida se registra
después sobre `{client}.<TENANT_BASE_DOMAIN>`. Si el overlay no redefine un endpoint, la petición llega a
la ruta compartida.

Los service providers de productos compartidos se cargan globalmente. Los providers de overlays
se omiten deliberadamente: un binding global de El Mundo podría contaminar una petición de El
Confidencial. Un overlay entra por sus rutas y clases acotadas, no mediante bindings globales.

## Resolución de una petición tenant

`CoreResolvePackageFromHostMiddleware` realiza la frontera multi-tenant:

1. Extrae un cliente válido del `Host` y recibe el slug del producto desde la ruta registrada.
2. Busca una fila de `commander_packages` con `provisioned_at` no nulo, ese slug y un cliente
   activo.
3. Rechaza con `404` si la instancia no existe o aún está pendiente.
4. Valida que nombre de base, usuario y contraseña runtime estén disponibles; si no, responde
   `503`.
5. Configura la conexión `tenant`, la convierte en conexión por defecto y fija la instancia en
   `CoreActivePackageSupport`.
6. Fusiona la configuración compartida y la del overlay.
7. Ejecuta la ruta y restaura conexión, configuración y contexto en un `finally`.

La fila de Commander se consulta siempre mediante el modelo fijado a la conexión `commander`, de
modo que cambiar la conexión por defecto no cambia el control plane.

Core y el health check son globales. Commander usa `commander.<BASE_DOMAIN>` y nunca pasa por el
resolutor tenant; los productos usan `{client}.<TENANT_BASE_DOMAIN>`.

## Configuración

Durante el boot, solo Core puede fusionar claves globales de Laravel. Los productos se cargan bajo
namespaces:

```text
kilkana.kilkana.*                 configuración compartida
elmundo.kilkana.kilkana.*         configuración del overlay
```

En una petición tenant, un fichero cuyo nombre coincide con una clave del skeleton (`app.php`,
`mail.php`, `session.php`, etc.) puede modificar esa clave solo durante la petición. El resolutor
restaura después el valor anterior. La configuración propia del overlay se expone en el namespace
compartido para que el código base la consuma sin conocer al cliente.

## Aislamiento de datos y credenciales

La separación principal es física:

- `commander`: operador, cuentas, clientes, catálogo, planes, billing, estado, cuotas y métricas.
- `{cliente}_{paquete}`: usuarios, tokens, roles y tablas de negocio de una instancia.

Un usuario o token de Kilkana no existe en la base de otra instancia ni en Commander. Sanctum
devuelve `401` de forma natural al buscarlo en la conexión activa; no hay un `tenant_id` que pueda
olvidarse en una consulta.

El proceso HTTP usa un usuario restringido para Commander. El worker `provisioner` es el único que
recibe credenciales DDL. Crea un usuario runtime por instancia y le concede solo `SELECT`, `INSERT`,
`UPDATE` y `DELETE` sobre su base. La contraseña se guarda cifrada mediante el cast de Eloquent y
se oculta de la serialización API.

Los ficheros de Kilkana se separan por nombre de base en
`storage/app/public/media/{database}`. Esta convención evita cruces entre sus instancias, aunque no
es todavía un contrato genérico de almacenamiento para productos futuros.

## Estado, límites y observabilidad

`commander_packages.status` implementa `active | grace | suspended`.

- `active`: la API sirve normalmente.
- `grace`: sirve y añade cabeceras `X-Package-*`.
- `suspended`: el kill-switch devuelve `402` en rutas tenant.
- si el estado de un tenant no puede verificarse, el middleware falla cerrado con `503`.

El metering se ejecuta al terminar la respuesta y escribe en `commander_request_logs`. No rompe la
respuesta si falla. Los rollups alimentan los gráficos y las cuotas. Rate limits y límites de
login incluyen `commander_packages.id` en su clave para que dos tenants con la misma IP o usuario
no compartan presupuesto.

Los enlaces firmados de Kilkana incluyen el host del cliente; no dependen de `APP_URL`. Los
comandos programados recorren solo instancias Kilkana provisionadas, con cliente activo y estado
operativo o grace vigente, y restauran la conexión entre tenants.

## Modelo de proceso soportado

La conmutación usa configuración y conexión por defecto mutables. Es segura con PHP-FPM y con
workers que procesan una unidad cada vez, porque el estado se restaura en `finally`. No se debe
activar concurrencia de requests dentro del mismo proceso —por ejemplo Octane/Swoole concurrente—
sin sustituir ese estado global por contexto aislado por coroutine/request.

## Límites conocidos

- El ciclo de vida catálogo/pending/live/de-provisioned comparte una tabla y no tiene estado de
  provisioning explícito; se detalla en [Base de datos](database.md).
- Un fallo al publicar el job después de confirmar el registro puede dejar una fila pending sin
  consumidor. De-provisioning tiene el caso simétrico. Falta reconciliación/outbox.
- Los providers de overlay no están soportados. Si una personalización necesita bindings, debe
  resolverse por clases/rutas tenant o requerirá otro mecanismo de contenedor acotado.
- Git Insights se asocia a cada fila de instancia. Varias instancias que apuntan al mismo código
  compartido pueden contabilizar la misma actividad más de una vez si se configuran igual.
- El borrado de almacenamiento presupone `media/{database}`. Un producto que escriba en otra ruta
  necesita declarar y limpiar su propio almacenamiento antes de generalizar el de-provisioning.
- Las clases de Kilkana conservan el prefijo histórico `SnkKilkana*` aunque el namespace vigente es
  `Packages\Kilkana`; es deuda de naming, no aislamiento por cliente.
