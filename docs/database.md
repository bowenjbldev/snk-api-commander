# Base de datos

> Estado: implementado.

## Topología

La plataforma usa una base de control y una base por instancia; no una tabla compartida con
`tenant_id`.

```text
commander
├── users, roles, permissions y tokens del operador
├── catálogo y modelo comercial commander_*
└── observabilidad de todas las instancias

diario_norte_kilkana
├── users, roles, permissions y tokens de esa cabecera
└── tablas kilkana_*

demo_kilkana
└── otro esquema completo
```

No hay FKs entre bases. `user_id` y `token_id` de los logs son referencias informativas a la base
de la instancia.

## Conexiones

| Conexión | Uso | Credencial |
|---|---|---|
| `commander` | control plane y operador | usuario HTTP limitado a `commander` |
| `tenant` | instancia activa | usuario runtime guardado cifrado en la instancia |
| `provisioner` | CREATE/DROP DATABASE, migraciones y grants | solo worker/operación |

Todos los modelos `Commander*Model` fijan la conexión `commander`. Core y modelos de producto usan
la conexión por defecto, que el resolutor conmuta temporalmente a `tenant`.

## Grafo de control

```text
accounts ─< clients ─< instances >─ applications ─< application_metrics
    │                    │                 └─< plans ─< plan_limits >─ metrics
    ├─< subscriptions ─< subscription_items >─ instances/plans
    │         └─< quotas >─ metrics
    └─< invoices ─< invoice_lines >─ subscriptions/items

instances ─< quotas >─ metrics
quotas ─< metric_measurements
instances ─< request_logs / usage_rollups / activity_events
applications ─ application_repos ─< application_commits/activity_daily
```

## Tablas Commander

### Comercial y catálogo

| Tabla | Responsabilidad | Claves y restricciones principales |
|---|---|---|
| `commander_accounts` | pagador, contacto y dirección de facturación | `id`; estado indexado |
| `commander_clients` | tenant/cabecera y subdominio | FK `account_id` restrict; `slug` único |
| `commander_applications` | producto code-first | `package_name` y `slug` únicos; `status`, `code_status` |
| `commander_application_metrics` | métricas declaradas | FK aplicación cascade; único `(application_id, key)` |
| `commander_plans` | plan de una aplicación | FKs aplicación restrict; único `(application_id, slug)` |
| `commander_plan_limits` | plantilla de límite por métrica | FKs plan/metric; único `(plan_id, metric_id)` |

`code_status` distingue código `available` y `missing`; no expresa el ciclo de vida de una
instancia. Una aplicación inactiva o sin código conserva planes e historia, pero no enruta ni se
provisiona.

La BD no puede expresar con una FK simple que la métrica de un límite pertenece a la misma
aplicación que el plan. La API valida esa igualdad.

### Instancias y contratos

| Tabla | Responsabilidad | Claves y restricciones principales |
|---|---|---|
| `commander_instances` | par cliente-aplicación y recursos operativos | FKs restrict; único `(client_id, application_id)` |
| `commander_subscriptions` | contrato cuenta-aplicación | FKs account/application restrict; estado indexado |
| `commander_subscription_items` | plan operativo de una instancia dentro del contrato | `instance_id` único; FKs subscription cascade, instance/plan restrict |

Columnas relevantes de `commander_instances`:

```text
database, database_username, database_password(encrypted)
provisioning_status, last_error, requested_at, provisioned_at, deprovisioned_at
service_status, reason, message, grace_until, changed_at
requests_24h, quota_health, last_seen
```

Separar `provisioning_status` de `service_status` evita convertir pending/failed en estados
implícitos del kill-switch. La fila no se borra al dar de baja.

`commander_subscriptions` contiene `pricing_mode`, `monthly_amount`, `currency`, `max_instances` y
fechas contractuales. La igualdad cuenta/aplicación entre contrato, cliente, plan e instancia se
valida al crear el ítem. No hay endpoint CRUD directo para ítems.

### Cuotas

| Tabla | Responsabilidad | Claves |
|---|---|---|
| `commander_quotas` | configuración y estado actual por instancia o suscripción/métrica | únicos `(instance_id, metric_id)` y `(subscription_id, metric_id)` |
| `commander_metric_measurements` | muestra diaria histórica | único `(quota_id, bucket_date)` |

Una cuota tiene exactamente un ámbito: `instance_id` para el límite materializado desde un plan o
`subscription_id` para un límite compartido. La API valida que la métrica compartida pertenezca a
la aplicación del contrato. La cuota guarda valor, límite nullable, porcentaje de aviso, acción,
periodos consecutivos y sellos de warning/exceso/recomendación. Límite `null` significa
observación sin enforcement.

MariaDB impone con `commander_quotas_scope` que exactamente una de las dos FKs tenga valor. Los
escritores de aplicación conservan la misma regla en SQLite, cuyo `ALTER TABLE` no permite añadir
este `CHECK` durante la migración forward.

El valor compartido es la suma de la misma métrica en los ítems activos cuya instancia está
`ready`. No se copian usuarios ni datos tenant a Commander: se agregan las mediciones ya obtenidas
por instancia. Una medición fallida impide guardar un total parcial.

La fecha de medición se normaliza a `Y-m-d` también en SQLite para que el unique diario y las
consultas mensuales se comporten igual que una columna `DATE` de MariaDB.

### Billing

| Tabla | Responsabilidad | Claves |
|---|---|---|
| `commander_invoices` | cabecera de factura por cuenta | FK account restrict; `number` único |
| `commander_invoice_lines` | detalle consolidado o por instancia | FK invoice cascade; subscription obligatoria; item opcional |

`total_amount` se calcula en servidor. Una línea solo acepta contratos de la cuenta de la factura;
si apunta a un ítem, este debe pertenecer al contrato. Facturas no draft y contratos con historia
no se eliminan desde la API.

### Observabilidad

| Tabla | Responsabilidad | Identidad |
|---|---|---|
| `commander_request_logs` | petición cruda | `instance_id` nullable e indexado, sin FK deliberadamente |
| `commander_usage_rollups` | buckets hora/día por ruta/método/clase | FK instance; unique de bucket |
| `commander_activity_events` | cambios de estado, cuota y plan | FK opcional instance/subscription/invoice |
| `commander_application_repos` | origen Git y cursor | `application_id` único |
| `commander_application_commits` | commits crudos | único `(application_id, sha)` |
| `commander_application_activity_daily` | rollup Git diario | único `(application_id, day)` |

Los request logs evitan FK porque se escriben al terminar la respuesta y tienen retención/borrado
independiente. Rollups y mediciones se conservan tras deprovisioning para histórico.

Git pertenece a aplicación, no a instancia, para no duplicar el mismo commit por cada cliente que
usa código compartido.

### Operador y preferencias

- Las tablas Core `users`, roles, permisos y tokens de la base `commander` pertenecen al operador.
- Commander añade `full_name`, `company` y `phone` al usuario operador.
- `commander_settings` es un singleton JSON validado por su controller.

Estas identidades no se copian a las bases tenant.

## Esquema tenant

Cada base de instancia recibe:

1. migraciones Core: usuarios, roles, permisos, tokens y tabla base;
2. migraciones del package compartido;
3. migraciones del overlay, si existe.

El orden de una tabla de negocio nueva es: `id`, FKs, columnas de negocio y timestamps. Las FKs
van después de la PK. Una migración aplicada se evoluciona con otra forward migration.

## Kilkana

El índice del esquema editorial está en
[products/kilkana/database/README.md](products/kilkana/database/README.md):

1. [cuentas y permisos](products/kilkana/database/01-cuentas-y-permisos.md);
2. [firmas](products/kilkana/database/02-firmas.md);
3. [secciones](products/kilkana/database/03-secciones.md);
4. [artículos](products/kilkana/database/04-articulos.md);
5. [medios](products/kilkana/database/05-medios.md);
6. [comentarios y suscriptores](products/kilkana/database/06-comentarios-y-suscriptores.md);
7. [composición y ajustes](products/kilkana/database/07-composicion-y-ajustes.md).

La autenticación sigue siendo local a cada base. `editorial_seats` lee los roles locales y
`reads_month` agrega `kilkana_post_views.engaged` localmente; no existe un agregado de usuarios o
audiencia dentro de `commander_accounts`.

## Borrado y retención

- Una cuenta con clientes no se borra por FK restrict.
- Cliente y aplicación con instancias no se borran por FK restrict.
- Un plan asignado no se borra; se marca inactivo.
- Un contrato con ítems o factura no se borra; se cancela.
- Deprovisioning conserva instancia, ítem terminado, contrato, facturas, mediciones y rollups.
- Los logs crudos y el storage conocido se eliminan en la baja.

## SQLite frente a MariaDB

La suite usa SQLite y valida relaciones, API y lógica. No valida:

- permisos DDL y `GRANT`;
- `CREATE/DROP DATABASE` y usuarios;
- collation `utf8mb4_unicode_ci`;
- `information_schema` para tamaño de BD;
- comportamiento operativo bajo concurrencia MariaDB.
- constraints `CHECK` añadidos mediante `ALTER TABLE` solo en MariaDB.

Los cambios en esas fronteras requieren además una prueba contra MariaDB.
