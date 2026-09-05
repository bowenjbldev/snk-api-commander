# Flujos

## Request tenant

```text
GET https://diario-norte.commander.test/api/v1/kilkana/public/posts
  → host: client diario-norte
  → path: application kilkana
  → instance única (diario-norte, Kilkana), status ready
  → conexión diario_norte_kilkana
  → overlay exacto si existe; package compartido como fallback
  → estado, auth, endpoint y metering
  → restaurar conexión/config/contexto
```

Resultados de resolución:

| Condición | Resultado |
|---|---|
| cliente/aplicación/instancia inexistente o no ready | `404` |
| código de aplicación missing/inactivo | `404` |
| credenciales runtime incompletas en ready | `503` |
| verificación de estado falla | `503` |
| instancia suspended o grace vencida | `402` |
| grace vigente | respuesta normal con `X-Instance-*` |

El resolutor consume el parámetro wildcard `{client}` para que no llegue como argumento al
controller. Sanctum y Spatie consultan después la base tenant activa.

## Sincronización de aplicaciones

```bash
php artisan commander:applications:sync
```

El comando:

1. descubre packages de negocio compartidos o específicos con `routes/api.php`;
2. excluye Core y Commander;
3. upserta `commander_applications` por `package_name`;
4. sincroniza métricas técnicas y las declaradas por el package;
5. marca `missing` lo que ya no está desplegado;
6. conserva planes, instancias e historia.

El entrypoint lo ejecuta después de migrar. `GET /applications` es solo lectura.

## Crear un plan

`POST /api/v1/plans` recibe `application_id`, metadatos e `limits[]`. Cada límite referencia una
métrica de la misma aplicación. Un plan activo puede usarse en altas; uno inactivo conserva las
instancias existentes, pero no acepta nuevas.

Los límites son plantillas. No se propagan al editar el plan: se materializan cuando una instancia
se provisiona o cambia de plan.

## Provisioning de cliente normal

Endpoint privado: `POST /api/v1/provisioning`.

```json
{
  "plan_id": 7,
  "admin_email": "admin@acme.test",
  "admin_password": "una-clave-de-12-o-mas",
  "client": {
    "slug": "acme",
    "name": "Acme",
    "email": "contacto@acme.test",
    "billing_email": "facturas@acme.test",
    "tax_id": "ESA12345678"
  }
}
```

### Parte síncrona

1. exige `provision instances`;
2. valida plan/aplicación activos, código desplegado, slug, base y credenciales admin;
3. crea una cuenta 1:1 con los datos comerciales;
4. crea el cliente;
5. crea un contrato `catalog`, con precio del plan y `max_instances = 1`;
6. crea la instancia `pending` y credenciales runtime cifradas;
7. crea el ítem que une contrato, instancia y plan;
8. crea una cuota por cada métrica activa; métricas sin límite quedan en `none`;
9. registra todo en una transacción;
10. despacha `CommanderProvisionInstanceJob` después del commit y responde `202`.

También puede recibirse `client_id` para añadir otra aplicación a un cliente existente.

### Worker privilegiado

El job usa un lock `tenant-instance:{id}`, un intento y timeout de 600 segundos:

1. marca `provisioning`;
2. crea base y usuario sin privilegios;
3. configura `tenant` con la credencial provisioner;
4. ejecuta migraciones Core, package compartido y overlay;
5. crea el admin tenant con el email/contraseña explícitos;
6. ejecuta seeders y asigna rol `admin`;
7. concede CRUD al usuario runtime;
8. marca `ready`, `active` y `provisioned_at`;
9. restaura siempre conexión y config.

Ante excepción intenta borrar base/usuario, marca `failed`, conserva `last_error` y relanza. No
borra cuenta, cliente, contrato ni instancia.

## Provisioning de grupo

Primero se crea una cuenta y un contrato negociado:

```json
{
  "account_id": 3,
  "application_id": 1,
  "name": "Kilkana Grupo 2026",
  "pricing_mode": "negotiated",
  "monthly_amount": 180,
  "currency": "EUR",
  "max_instances": 3,
  "status": "active"
}
```

Cada cabecera repite provisioning con `subscription_id` y un cliente nuevo que usa el mismo
`account_id`. Commander valida:

- contrato y cliente pertenecen a la misma cuenta;
- contrato y plan pertenecen a la misma aplicación;
- contrato activo/trialing;
- capacidad `max_instances` disponible.

La cuarta cabecera de un contrato con capacidad tres responde `422` y la transacción no deja un
cliente huérfano.

Una cuota común se crea después sobre la suscripción:

```json
{
  "subscription_id": 3,
  "metric_id": 9,
  "limit_value": 10000000,
  "warning_percent": 80,
  "action": "recommend",
  "consecutive_periods": 2
}
```

`POST /api/v1/quotas` solo crea cuotas compartidas y exige que la métrica pertenezca a la
aplicación del contrato. Solo puede existir una por `(subscription_id, metric_id)`. Las cuotas de
instancia siguen naciendo exclusivamente del provisioning o de un cambio de plan.

## Retry

`POST /api/v1/instances/{id}/retry` acepta únicamente `failed` o `deprovisioned`, exige nuevas
credenciales admin y comprueba de nuevo el código.

- reutiliza el mismo ID, base derivada y relaciones;
- regenera credenciales runtime si la baja las anuló;
- reactiva ítem y contrato de catálogo;
- vuelve a `pending` y encola el job.

No se crea una segunda instancia para esconder un fallo.

## Cambio de plan

`POST /api/v1/instances/{id}/change-plan`:

1. valida plan activo de la misma aplicación;
2. cambia `subscription_item.plan_id`;
3. aplica la plantilla de límites a todas las métricas activas;
4. crea cuotas para métricas nuevas;
5. deja métricas retiradas sin límite, conservando su historia;
6. escribe `plan_changed`.

El importe del contrato no cambia. Esto es esencial en grupos: plan operativo y precio negociado
son decisiones distintas. No hay prorrateo.

## Estado manual

```text
POST /instances/{id}/grace
POST /instances/{id}/suspend
POST /instances/{id}/resume
```

Las acciones usan el ID numérico, escriben la instancia y crean un evento. Grace usa `hours` o el
default de Core. `effectiveStatus()` trata una grace vencida como suspended sin necesitar una
escritura en la ruta de lectura.

## Medición de cuotas

`commander:quota:measure` mide primero las cuotas agrupadas por instancia `ready`:

1. fija contexto e instancia;
2. configura credenciales runtime tenant;
3. resuelve la clase medidora declarada por la métrica;
4. persiste valor actual y muestra diaria;
5. registra warning al alcanzar el porcentaje configurado;
6. aplica `none`, `warn`, `suspend` o `recommend`;
7. aísla el fallo de una métrica y continúa;
8. restaura contexto y conexión en `finally`.

Después suma cada cuota de suscripción usando la misma métrica de todos sus ítems activos con
instancia `ready`. Si alguna cabecera no se pudo medir, el agregado queda sin muestra en vez de
usar un total parcial. La cuota compartida conserva su propio valor actual e histórico diario.
`warn` y `recommend` generan un único evento de contrato; `suspend` aplica grace o suspensión a
todas sus cabeceras activas. Al volver bajo el límite, solo revierte estados originados por cuotas.

`suspend` entra primero en grace y solo suspende al vencer. Al bajar todas las cuotas que causaron
ese estado puede hacer auto-resume; nunca cura una suspensión manual o por billing.

Para `recommend`, una evaluación mensual puede incrementar o reiniciar
`consecutive_exceeded`. Kilkana usa media de tres meses completos para lecturas y última muestra
del mes completo para asientos. Al alcanzar el número configurado crea `upgrade_recommended`, sin
cambiar plan ni servicio.

## Logs y rollups

`CoreMeteringMiddleware` inserta por `instance_id` método, ruta, status, duración, actor, IP, bytes
y memoria. Captura también `402` y `429`; excluye tráfico sin instancia.

`commander:usage:rollup --prune` agrega buckets cerrados de hora/día. `commander:refresh` actualiza
`requests_24h`, `quota_health` y `last_seen`. La API puede filtrar logs por instancia, aplicación o
cuenta.

Git se sincroniza por aplicación con `commander:git:sync`; el mismo código compartido no se duplica
por cada instancia.

## Factura

`POST /api/v1/invoices` recibe cuenta y líneas. El servidor valida pertenencia de contratos/ítems,
calcula cada importe y suma `total_amount`. Una línea sin item factura el contrato consolidado; con
item identifica una instancia.

Cambiar estado de factura no ejecuta cobro ni suspensión automática.

## Deprovisioning

`DELETE /api/v1/instances/{id}`:

1. marca `deprovisioning` y encola un job con el mismo lock;
2. borra base y usuario runtime;
3. elimina `media/{database}` y logs crudos;
4. reinicia estado actual de cuotas, pero conserva muestras y rollups;
5. termina el ítem;
6. cancela el contrato si era catálogo 1:1; deja vivo un contrato negociado de grupo;
7. anula credenciales y marca `deprovisioned`;
8. conserva IDs, cliente, aplicación, billing y auditoría.

Si falla, queda `failed` con `last_error` para inspección y retry.

## Deploy y migración

1. despliega código e instala dependencias;
2. `core:migrate --force` migra `commander`;
3. `commander:applications:sync` sincroniza catálogo/métricas;
4. recachea config y rutas;
5. desde el provisioner ejecuta `core:migrate --all-tenants --force`;
6. el comando hace preflight completo antes del primer DDL.

No se combina `--all-tenants` con fresh, rollback, seed, client o package.
