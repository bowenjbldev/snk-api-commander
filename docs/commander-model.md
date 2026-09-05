# Modelo de negocio y datos de Commander

## Propósito

Commander es el control plane privado que registra qué se vende, a quién se vende y qué
instancias existen. No contiene datos editoriales ni usuarios tenant. Su modelo debe servir sin
ramificaciones especiales para dos extremos:

- una aplicación sencilla vendida a un único cliente con un único plan;
- un SaaS como Kilkana, donde una cuenta de grupo contrata varias cabeceras independientes.

La decisión principal es separar cuatro conceptos que antes podían confundirse:

| Concepto | Pregunta que responde | Ejemplo |
|---|---|---|
| cuenta (`account`) | ¿quién contrata y recibe la factura? | Grupo Prensa Norte |
| cliente (`client`) | ¿qué tenant/cabecera usa el servicio? | Diario Norte |
| aplicación (`application`) | ¿qué producto vendemos? | Kilkana |
| instancia (`instance`) | ¿qué cliente usa qué aplicación? | Diario Norte + Kilkana |

Un package es la implementación Laravel de una aplicación; no es una venta ni una instancia.

## Mapa de relaciones

```text
commander_account 1 ─────── N commander_client
        │                            │
        │                            └── N commander_instance N ─── 1 commander_application
        │                                      │                              │
        │                                      │                              ├── N commander_application_metric
        │                                      │                              └── N commander_plan
        │                                      │                                          │
        └── N commander_subscription ── N commander_subscription_item ────────────────────┘
                    │                                  │
                    │                                  └── 1 instance + 1 plan operativo
                    └── N commander_invoice_line ── N commander_invoice ── 1 account

commander_instance 1 ── N commander_quota N ── 1 commander_application_metric
commander_subscription 1 ── N commander_quota
commander_quota    1 ── N commander_metric_measurement
commander_instance 1 ── N logs, rollups y eventos
commander_application 1 ── 1 repo Git ── N commits/rollups diarios
```

Las relaciones comerciales terminan en Commander. La base tenant no tiene FKs hacia la base de
control.

## Entidades y responsabilidades

### Cuenta

`commander_accounts` representa al pagador y padre contractual. Contiene razón de contacto,
facturación, NIF, dirección y condiciones de pago.

- Todo cliente tiene una cuenta obligatoria.
- Un cliente normal usa una cuenta 1:1 creada durante el alta.
- Un grupo reutiliza una cuenta 1:N para todas sus cabeceras.
- La cuenta no es tenant, subdominio ni frontera de datos.
- Suscripciones y facturas pertenecen a la cuenta, no al cliente.

El correo es un dato de contacto, no una identidad única: dos cuentas pueden usar la misma
gestoría.

### Cliente

`commander_clients` representa el tenant operativo. En Kilkana equivale a una cabecera o diario.

- `slug` identifica el subdominio y es único en el despliegue.
- Pertenece a una sola cuenta y esa pertenencia es inmutable desde la API.
- Puede tener varias aplicaciones, pero solo una instancia de cada aplicación.
- No contiene datos de billing, cuotas ni usuarios tenant.

Ejemplo: `diario-norte` produce el host de API `diario-norte.commander.test`. Kilkana se
selecciona con `/api/v1/kilkana`; otra aplicación usa el mismo host y su propio segmento.

### Aplicación

`commander_applications` es el catálogo vendible. Se sincroniza desde los packages desplegados que
tienen `routes/api.php`:

```text
application.package_name = Kilkana
application.slug         = kilkana
código                    = src/packages/Kilkana
```

El catálogo es **code-first**. `commander:applications:sync` crea o actualiza aplicaciones y
métricas; una lectura `GET` nunca modifica el catálogo. El entrypoint Docker ejecuta la
sincronización tras migrar Commander. Si desaparece el código, `code_status` pasa a `missing` y
no se permiten nuevas altas ni routing tenant.

Core y Commander son infraestructura y no se registran como aplicaciones de negocio. Un overlay
`src/packages/<Cliente>/<Paquete>` modifica el código de una instancia, pero no crea otra
aplicación comercial.

### Instancia

`commander_instances` representa el par único `(client_id, application_id)`. Es la identidad
operativa estable para routing, estado, credenciales, cuotas, logs y jobs.

Cada instancia tiene:

- base propia `{cliente}_{package}`;
- usuario runtime propio, con solo CRUD sobre esa base;
- estado de provisioning y estado de servicio separados;
- un ítem de suscripción con su plan operativo;
- una cuota por cada métrica activa de la aplicación.

La API y los jobs direccionan acciones mediante el ID numérico de instancia, nunca mediante el
nombre global de la aplicación.

#### Estados

`provisioning_status` describe recursos:

| Estado | Significado |
|---|---|
| `pending` | alta registrada, job aún no iniciado |
| `provisioning` | el worker está creando la base |
| `ready` | base, usuario, migraciones y admin listos |
| `failed` | falló el alta o la baja; `last_error` conserva el motivo |
| `deprovisioning` | baja encolada |
| `deprovisioned` | recursos eliminados, identidad histórica conservada |

`service_status` es un kill-switch independiente:

| Estado | Comportamiento |
|---|---|
| `active` | sirve tráfico |
| `grace` | sirve tráfico con cabeceras de aviso hasta `grace_until` |
| `suspended` | responde `402` |

Solo una instancia `ready`, con cliente y aplicación activos y código disponible, puede enrutar.

### Métricas, planes y límites

`commander_application_metrics` declara qué puede medir una aplicación. Cada definición tiene
clave, unidad, ventana y clase medidora. Todas las aplicaciones reciben métricas técnicas base:

- `requests_day`;
- `disk_db_mb`;
- `disk_storage_mb`;
- `disk_total_mb`;
- `users_total`.

Kilkana añade en `packages/Kilkana/config/commander.php`:

- `editorial_seats`: usuarios distintos con algún rol distinto de `reader` o `service`; una
  cuenta sin rol tampoco cuenta;
- `reads_month`: suma mensual de `kilkana_post_views.engaged`.

`commander_plans` pertenece a una aplicación. `commander_plan_limits` relaciona un plan con sus
métricas. Un límite omitido significa “medir sin limitar”; la cuota de instancia sigue existiendo
para no perder observabilidad.

Los límites de plan son una plantilla. Se copian a `commander_quotas` al provisionar o cambiar de
plan. Editar el catálogo de un plan no reescribe silenciosamente instancias existentes.

Una cuota también puede pertenecer directamente a una suscripción. En ese ámbito suma la misma
métrica de todas las instancias `ready` con ítem activo en el contrato y mantiene mediciones
propias. Esto permite vender capacidad compartida —por ejemplo, diez millones de lecturas para
cinco cabeceras— sin crear una macro-instancia ni perder las cuotas individuales.

Cada cuota admite `none`, `warn`, `suspend` o `recommend`:

- el umbral de aviso predeterminado es 80 %;
- `suspend` usa grace y después el kill-switch;
- `recommend` solo registra una propuesta de upgrade;
- nunca se cambia el plan ni se factura automáticamente.

Kilkana usa `recommend`: las lecturas se evalúan con la media de los tres meses completos
anteriores; los asientos, con la última medición del mes completo anterior. Solo se propone un
upgrade tras `consecutive_periods` evaluaciones excedidas. Un mes sano reinicia la secuencia.

`commander_metric_measurements` conserva una muestra diaria por cuota para estas decisiones y
para auditoría.

### Suscripción e ítem

`commander_subscriptions` es el contrato entre una cuenta y una aplicación. Define:

- modo `catalog` o `negotiated`;
- importe mensual y moneda pactados;
- estado comercial;
- capacidad opcional `max_instances`;
- fechas de trial, inicio y fin.

`commander_subscription_items` asigna a cada instancia su plan operativo. Esta separación permite
que un contrato de grupo tenga un precio total negociado y que cada cabecera use un plan distinto.

No se borra una suscripción con ítems o líneas de factura: se cancela y se conserva la historia.

### Facturas

`commander_invoices` pertenece a una cuenta. Sus líneas apuntan al contrato y, opcionalmente, a
una cabecera concreta mediante `subscription_item_id`. El total se calcula en el servidor como
`cantidad × precio unitario`.

Así una factura puede contener una línea consolidada de grupo, líneas por cabecera o ambas. Solo
se pueden borrar borradores. Commander es la fuente del ledger, pero no hay todavía pasarela,
SEPA, conciliación ni cobro automático.

### Observabilidad y código

- Los request logs, rollups y eventos usan `instance_id`.
- Los IDs de usuario/token de un log son informativos: pertenecen a la base tenant de esa
  instancia y no tienen FK cross-database.
- Los commits y rollups Git usan `application_id`, porque el código compartido se cuenta una sola
  vez aunque lo usen cien clientes.

## Alta de una aplicación sencilla

Ejemplo: Acme contrata SimpleApp Pro por 30 €.

```text
account Acme
└── client acme
    └── instance acme + simple-app → DB acme_simple_app

subscription Acme · SimpleApp
├── pricing_mode = catalog
├── monthly_amount = 30
├── max_instances = 1
└── item → instance acme/simple-app → plan Pro
```

El endpoint de provisioning puede crear cuenta y cliente en la misma operación. Genera el
contrato de catálogo, la instancia, el ítem y las cuotas; el worker crea después la base y el admin
tenant con las credenciales explícitas recibidas.

## Alta de Kilkana Grupo

Ejemplo: una cuenta administra tres diarios:

```text
account Grupo Kilkana
├── client diario-norte  ─ instance Kilkana ─ diario_norte_kilkana  ─ plan Basic
├── client diario-este   ─ instance Kilkana ─ diario_este_kilkana   ─ plan Pro
└── client diario-oeste  ─ instance Kilkana ─ diario_oeste_kilkana  ─ plan Business

subscription Kilkana Grupo 2026
├── pricing_mode = negotiated
├── monthly_amount = total pactado
├── max_instances = 3
└── tres items, uno por instancia y plan operativo
```

La cuenta comparte contrato y factura, no datos tenant. Cada diario conserva:

- subdominio, base, usuarios, roles y tokens propios;
- admin explícito y delegación local de permisos;
- cuotas, métricas, logs y estado independientes.

La misma persona en dos diarios son dos usuarios locales. Si ocupa un rol editorial en ambos,
consume un asiento en cada diario. No existe SSO ni deduplicación cross-tenant en esta fase.

Los precios orientativos de Kilkana —29, 59 y 99 €— son una decisión comercial documentada, no
datos automáticos: el operador crea los planes y sus límites cuando se aprueban.

## Provisioning, fallo y baja

El alta síncrona valida código, cuenta, cliente, contrato, capacidad y plan; crea los registros en
una transacción y publica el job después del commit. El worker privilegiado es el único con DDL.

Si el worker falla:

- intenta limpiar base y usuario;
- conserva cuenta, cliente, contrato e instancia;
- marca la instancia `failed` y guarda `last_error`.

`POST /instances/{id}/retry` exige nuevas credenciales de admin y reutiliza el mismo ID. No crea
otra identidad para ocultar el fallo.

La baja también es histórica: elimina base, usuario, storage conocido y logs crudos; reinicia las
cuotas, termina el ítem y marca la instancia `deprovisioned`. Conserva instancia, mediciones,
rollups y billing. Un contrato de catálogo 1:1 se cancela; un contrato negociado de grupo continúa
para sus otras cabeceras.

## API de control

Los recursos principales bajo `/api/v1` son:

```text
accounts
clients
applications                  lectura/metadata; alta por sync de código
plans                          incluye limits[]
subscriptions                  contratos de cuenta
provisioning                   alta atómica
instances                     identidad operativa
instances/{id}/retry
instances/{id}/change-plan
instances/{id}/grace|suspend|resume
instances/{id}/usage|logs
invoices                       incluye lines[]
quotas
activity
logs
usage/platform
```

## Decisiones y límites deliberados

- No hay frontend en este repositorio; Docker conserva únicamente un perfil opt-in vacío para un
  cliente futuro creado desde cero.
- No hay proveedor de pagos, SSO tenant, autoservicio público ni prorrateo.
- Las cuotas operativas siguen siendo por instancia; una suscripción puede añadir una cuota
  compartida que suma la misma métrica de sus cabeceras activas.
- No se generan ni clonan packages durante el alta.
- La conmutación global de conexión soporta PHP-FPM y workers secuenciales, no concurrencia
  Octane/Swoole.
- El storage genérico de una aplicación aún no es declarativo; la baja solo conoce
  `media/{database}`.
- El enqueue no usa outbox. `failed`, `last_error` y el retry estable permiten operar el fallo,
  pero un reconciliador automático sería una mejora futura.
