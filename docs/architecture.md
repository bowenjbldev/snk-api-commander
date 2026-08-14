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

diario_sur_kilkana
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

Rate limit y login incluyen `instance_id` en su clave. `CoreMeteringMiddleware` captura esa misma
identidad antes de que el resolutor restaure el contexto y escribe el log al terminar la respuesta.
Un fallo de metering nunca modifica la respuesta.

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
