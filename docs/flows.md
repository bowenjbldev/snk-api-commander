# Flujos

## Petición tenant

Ejemplo: `GET https://elmundo.kilkana.test/api/v1/kilkana/public/posts`.

```text
nginx
  → ruta compartida Kilkana o ruta exacta del overlay
  → resolver host=elmundo + slug=kilkana
  → buscar fila viva en commander_packages
  → configurar conexión tenant con credenciales de esa fila
  → aplicar config Kilkana + ElMundo/Kilkana
  → throttle → kill-switch → auth de la ruta → controller
  → responder
  → metering terminable
  → restaurar conexión/config/contexto
```

Resultados de resolución:

| Condición | Respuesta |
|---|---|
| host fuera de `TENANT_BASE_DOMAIN`, subdominio reservado o multinivel | la ruta tenant no coincide / `404` |
| cliente inexistente o inactivo | `404` |
| fila sin `provisioned_at` | `404` |
| credenciales o identificador de BD inválidos | `503` |
| estado no verificable | `503` fail-closed |
| instancia suspendida | `402` con motivo y mensaje |
| grace vigente | respuesta normal con cabeceras `X-Package-*` |

La consulta de la instancia ocurre antes de cambiar la conexión. Tras el cambio, los modelos de
negocio, Sanctum y Spatie leen exclusivamente la base tenant; los modelos Commander siguen fijados
a `commander`.

`{client}` pertenece únicamente al wildcard del dominio: el resolutor lo consume y lo elimina de
los parámetros antes del dispatch. Así, `{layout}`, `{slug}`, `{post}` y demás argumentos de path
llegan al controlador en su posición real.

## Fallback de implementación

El fallback es de código y rutas, no de datos:

```text
ElMundo/Kilkana define GET custom-report
  → esa ruta usa el overlay

ElMundo/Kilkana no define GET public/posts
  → cae en la ruta de packages/Kilkana
  → sigue usando elmundo_kilkana, nunca una BD compartida

ElConfidencial no tiene carpeta propia
  → todas sus rutas usan packages/Kilkana
  → siguen usando elconfidencial_kilkana
```

Una fila creada en Commander no inventa una implementación. Para crear un plan, el producto debe
estar desplegado como compartido o existir para algún cliente. Al provisionar, debe existir el
compartido o la implementación específica del cliente elegido; si no, se devuelve `422`.

## Sincronización de catálogo y planes

`GET /api/v1/packages` en Commander:

1. descubre directorios de producto que contienen `routes/api.php`;
2. excluye Core, Commander y paraguas vacíos de cliente;
3. crea, si falta, una fila catálogo por nombre lógico;
4. devuelve todas las filas —catálogo, pendientes, vivas y dadas de baja— enriquecidas con
   `on_disk` y número de planes.

Crear o cambiar un plan valida que `package` siga disponible en disco y normaliza el nombre a
StudlyCase. Un plan inactivo no se puede usar para una nueva alta. `listing_status` pertenece a la
presentación del catálogo; el provisioning actual no lo usa como kill-switch.

## Provisioning

Endpoint de operador: `POST https://commander.<BASE_DOMAIN>/api/v1/provisioning`.

Payload esencial:

```json
{
  "plan_id": 7,
  "admin_email": "admin@elmundo.example",
  "admin_password": "una-clave-de-12-o-mas",
  "client_id": 12
}
```

Para crear una cabecera dentro de una cuenta comercial existente, el objeto nuevo acepta
`account_id`:

```json
{
  "plan_id": 7,
  "admin_email": "admin@diario-norte.example",
  "admin_password": "una-clave-de-12-o-mas",
  "client": {
    "account_id": 3,
    "slug": "diario-norte",
    "name": "Diario Norte",
    "email": "redaccion@diario-norte.example"
  }
}
```

La cuenta no cambia la URL ni la BD: siguen derivándose de `client.slug`. Las siguientes
cabeceras de la misma cuenta repiten el flujo con otro cliente y otra base.

En lugar de `client_id` puede enviarse un objeto `client` con `slug`, `name`, `email` y datos
opcionales de contacto/billing. Si omite `account_id`, Commander crea automáticamente su cuenta
comercial 1:1. Un `account_id` existente se usa solo para añadir la cabecera a ese grupo; nunca se
deduce por coincidencia de nombre o correo.

### Fase síncrona

El controller:

1. exige permiso `provision packages`;
2. valida plan activo, cliente activo, slug DNS, longitud de la BD y contraseña del admin;
3. resuelve código compartido y/o específico;
4. rechaza otro `(client_id, slug)` registrado;
5. dentro de una transacción Commander crea la cuenta 1:1 cuando haga falta, el cliente, la fila
   de instancia, el término de suscripción y cuatro cuotas;
6. genera `database_username = tenant_<package_id>` y una contraseña aleatoria cifrada;
7. deja `provisioned_at = null`;
8. cifra temporalmente la contraseña del admin para el job, encola en `provisioning` y responde
   `202`.

La fila pending no puede recibir tráfico aunque ya exista su host y su ruta.

### Fase privilegiada

`CommanderProvisionPackageJob` usa un lock compartido por `package_id`, un solo intento y timeout
de 600 segundos:

1. crea la base con `utf8mb4_unicode_ci`;
2. crea el usuario runtime sin permisos iniciales;
3. ejecuta migraciones Core, compartidas y overlay con la credencial provisioner;
4. crea el admin tenant elegido;
5. ejecuta seeders Core, compartidos y overlay;
6. asigna el rol `admin` al usuario elegido;
7. concede al usuario runtime solo CRUD sobre su base;
8. marca `status = active` y `provisioned_at = now()`;
9. restaura siempre conexión y configuración global.

Si algo falla, intenta borrar la base y el usuario y elimina cuotas, término e instancia. Si el
cliente se creó para ese alta y no tiene otra fila de paquete, también lo elimina; la cuenta 1:1
creada automáticamente se elimina si quedó sin clientes. Una cuenta de grupo nunca se borra desde
ese rollback. Después relanza la excepción para que quede registrada como job fallido.

### Hueco operativo actual

La transacción termina antes de publicar el job. Si el enqueue falla, queda una fila pending sin
trabajo. No hay outbox ni reconciliador que la reencole o la marque `failed`. Hasta añadirlo, una
fila con `client_id` y sin `provisioned_at` exige inspeccionar la cola y los jobs fallidos antes de
decidir si reintentar o limpiar.

## Cambio de plan

`POST /api/v1/packages/{id}/change-plan`:

1. exige una instancia viva y un plan activo del mismo producto;
2. bloquea el término vigente con `lockForUpdate`;
3. si ya usa ese plan, responde `422` sin cambios;
4. cierra el término actual y abre otro con snapshot de nombre/precio;
5. actualiza el puntero `subscription_id`;
6. crea o ajusta cuotas según el plan nuevo;
7. escribe un evento `change_plan`.

No hay prorrateo ni cargo automático. El nuevo término empieza con billing `pending`.

## Estado manual y facturación

Acciones operativas:

```text
POST /packages/{id}/grace
POST /packages/{id}/suspend
POST /packages/{id}/resume
```

Todas exigen una fila viva y escriben un evento. Grace recibe una fecha o duración; si no, usa la
duración configurada. Al vencer, `effectiveStatus()` la trata como suspendida aunque la columna
siga diciendo `grace` hasta que otro proceso la actualice.

Las facturas pueden quedar `paid`, `pending` u `overdue`. Commander permite aplicar grace o
suspensión al marcar un impago y ofrecer resume al cobrar, pero billing y estado siguen siendo
decisiones del operador: no hay cobro ni conciliación externos.

## Medición y enforcement de cuotas

El scheduler ejecuta `commander:quota:measure` cada seis horas.

Por cada `package_id`, el medidor:

1. restaura la configuración tenant base y purga la conexión anterior;
2. configura la conexión con las credenciales runtime de esa instancia;
3. mide todas sus métricas y actualiza sus filas;
4. evalúa enforcement en una segunda pasada, ya con todos los valores frescos;
5. restaura conexión y contexto al terminar.

Política:

| Estado de la métrica | `on_exceed` | Acción |
|---|---|---|
| bajo límite | cualquiera | limpia `exceeded_at`; auto-resume solo si esa métrica causó el estado y ninguna otra excede |
| excedida | `none` | registra medición, sin transición |
| excedida | `warn` | warning en log |
| excedida desde active | `suspend` | entra en grace por `quota_exceeded:<metric>` |
| misma grace vencida | `suspend` | pasa a suspended |

Una suspensión manual o por impago no se cura por bajar una cuota.

## Metering y rollups

`CoreMeteringMiddleware` captura la instancia resuelta y, al terminar la respuesta, inserta método,
ruta, status, duración, usuario/token, IP, bytes y memoria. Incluye `402` y `429`; excluye requests
sin instancia tenant. Cualquier error se reporta, pero nunca cambia la respuesta del cliente.

`commander:usage:rollup --prune` agrega logs por hora/día, ruta, método y clase HTTP. La vista de
plataforma suma esos rollups al vuelo. `commander:refresh` actualiza cada diez minutos
`requests_24h`, `quota_health` y `last_seen`.

## De-provisioning

`DELETE /api/v1/packages/{id}`:

1. exige confirmación en la UI y permiso de borrado;
2. pone `provisioned_at = null` antes de encolar, cortando tráfico de inmediato;
3. encola `CommanderDeprovisionPackageJob` con el mismo lock de provisioning;
4. el worker elimina base y usuario runtime;
5. borra `storage/app/public/media/{database}`;
6. elimina logs, cuotas y rollups; cierra el término vigente;
7. anula columnas de cliente, slug, conexión, estado y KPIs;
8. conserva la fila, metadatos de catálogo y billing histórico.

Si el job falla, la instancia ya no enruta pero puede conservar recursos. No existe todavía un
estado `deprovision_failed` ni reconciliación automática; se revisan jobs fallidos y MariaDB antes
de reintentarlo.

## Despliegue de código y migraciones

El código llega por git, no por Commander:

1. se despliega el commit nuevo;
2. se instala Composer y se recachea configuración/rutas;
3. `core:migrate --force` actualiza `commander`;
4. `core:migrate --all-tenants --force` carga todas las instancias y hace preflight completo;
5. si falta cliente, nombre, BD, credenciales o implementación, aborta antes del primer DDL;
6. si el preflight pasa, migra cada tenant en orden de id.

`BASE_DOMAIN` y `TENANT_BASE_DOMAIN` quedan horneados en `route:cache`; hay que regenerarlo si
cambia cualquiera de los dos o las rutas.

## Trabajos programados

| Frecuencia | Comando | Alcance |
|---|---|---|
| cada minuto | `kilkana:publish-scheduled` | instancias Kilkana activas o grace vigente |
| cada 10 min | `commander:refresh` | KPIs de Commander |
| cada hora | `commander:usage:rollup --prune` | logs y retención |
| cada hora | `commander:git:sync` | commits e insights |
| cada 6 h | `commander:quota:measure` | medición y enforcement |

El servicio Docker `scheduler` ejecuta `schedule:work`. Un tenant roto se registra y no impide que
los comandos Kilkana continúen con los demás.
