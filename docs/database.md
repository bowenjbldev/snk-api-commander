# Base de datos

## Topología

La plataforma no usa una única base compartida con `tenant_id`. Usa una base de control y una
base por instancia:

```text
commander
├── users/roles/tokens del operador
├── commander_accounts
├── commander_clients
├── commander_packages
├── planes, suscripciones y facturas
└── cuotas, logs, rollups, actividad e insights

elmundo_kilkana
├── users/roles/tokens de esa redacción
├── tablas kilkana_*
└── ninguna fila de otro cliente

elconfidencial_kilkana
└── otro esquema completo e independiente
```

No existen claves foráneas entre bases. Los ids de usuario y token que aparecen en los logs de
Commander son referencias informativas a la base tenant, no FKs.

## Conexiones

| Conexión | Uso | Credencial |
|---|---|---|
| `commander` | control plane y operador | usuario HTTP, limitado a la base `commander` |
| `tenant` | base activa de una instancia | usuario runtime único, configurado por request/job |
| `provisioner` | crear/eliminar bases y usuarios, migrar | credencial DDL solo en el worker privilegiado |

`CommanderPackageModel` está fijado a `commander`. La contraseña runtime usa el cast
`encrypted`, se descifra solo dentro del proceso y está oculta en respuestas JSON.

## Esquema Core

Core se instala tanto en `commander` como en cada base tenant:

| Grupo | Tablas |
|---|---|
| identidad | `users`, `password_reset_tokens`, `sessions` |
| autorización | `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` |
| tokens | `personal_access_tokens` |
| infraestructura Laravel | `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` |

Commander añade `full_name`, `company` y `phone` al usuario operador. Kilkana añade las mismas
columnas a los usuarios de cada redacción. La identidad no lleva `package_id`: la base en la que
vive ya define la instancia.

## Esquema Commander

Las catorce tablas `commander_*` viven solo en `commander`.

| Tabla | Responsabilidad | Claves relevantes |
|---|---|---|
| `commander_accounts` | identidad comercial; una o varias cabeceras | PK numérica |
| `commander_clients` | cabecera tenant y slug DNS | FK obligatoria `account_id`; `slug` único; `status` active/inactive |
| `commander_packages` | catálogo y filas de instancia | FK `client_id`; único `(client_id, slug)`; `provisioned_at` |
| `commander_plans` | oferta por tipo de producto y cuotas por defecto | `slug` único; `package` StudlyCase |
| `commander_subscriptions` | historial de términos de plan | FKs cliente/instancia; término actual con `ended_at IS NULL` |
| `commander_invoices` | ledger de facturas | número único; FK cliente/instancia |
| `commander_quotas` | límite, medición y política por métrica | único `(package_id, metric_key)` |
| `commander_request_logs` | petición tenant cruda | `package_id` indexado sin FK |
| `commander_usage_rollups` | buckets hora/día para uso y gráficos | FK instancia; bucket compuesto único |
| `commander_activity_events` | cambios manuales y automáticos | FKs opcionales a instancia/factura |
| `commander_settings` | preferencias del único operador | JSON en la fila convencional id 1 |
| `commander_package_repos` | origen Git y cursor de sync por instancia | `package_id` único |
| `commander_package_commits` | commits crudos acotados al path | único `(package_id, sha)` |
| `commander_package_activity_daily` | rollup Git diario | único `(package_id, day)` |

### Cuentas comerciales y cabeceras

`commander_accounts` no es un tenant. Agrupa clientes que comparten pagador o contrato, mientras
cada `commander_client` sigue representando una cabecera, un subdominio y una frontera de datos.
La relación es obligatoria: un periódico independiente recibe automáticamente una cuenta 1:1 y
un grupo reutiliza la misma cuenta para todas sus cabeceras. La restricción `NOT NULL` impide
clientes huérfanos incluso fuera de la API.

La cuenta se identifica solo por su `id`; no tiene slug porque no participa en hosts ni rutas.
`email` es un contacto principal obligatorio y `billing_email` un destino opcional de facturas:
ninguno es único ni se usa para autenticar o deducir pertenencia. Una persona o gestoría puede
atender varias cuentas legítimas.

#### `commander_accounts`

| Columna | Tipo | Nula | Significado |
|---|---|---:|---|
| `id` | bigint unsigned | no | PK autoincremental e identidad estable de la cuenta. |
| `name` | varchar(255) | no | Nombre comercial o razón reconocible por el operador. |
| `email` | varchar(255) | no | Contacto principal; no es único ni una credencial. |
| `billing_email` | varchar(255) | sí | Destino alternativo para facturación. |
| `phone` | varchar(50) | sí | Teléfono de contacto comercial. |
| `tax_id` | varchar(64) | sí | Identificador fiscal tal como lo facilita el cliente. |
| `payment_terms` | varchar(32) | sí | Condición validada por la API: `prepaid`, `net_15`, `net_30` o `net_60`. |
| `address_line1` | varchar(255) | sí | Primera línea de la dirección de facturación. |
| `address_line2` | varchar(255) | sí | Segunda línea de la dirección de facturación. |
| `city` | varchar(128) | sí | Localidad de facturación. |
| `postal_code` | varchar(32) | sí | Código postal sin asumir un formato nacional. |
| `country` | varchar(2) | sí | Código de país ISO 3166-1 alfa-2. |
| `notes` | text | sí | Notas internas del operador. |
| `created_at` | timestamp | sí | Fecha de alta. |
| `updated_at` | timestamp | sí | Última modificación. |

La tabla solo tiene la PK sobre `id`. La relación se materializa desde
`commander_clients.account_id`, una FK obligatoria con borrado `RESTRICT`.

No hay propagación de estado ni cuotas agregadas. Tres cabeceras de una cuenta conservan tres
filas `commander_packages`, tres bases, tres términos de suscripción y medición independiente.

Las facturas todavía pertenecen a una instancia concreta. La cuenta permite agrupar y calcular
totales, pero una factura consolidada con varias cabeceras requerirá cabecera de factura y líneas;
no se simula poniendo varias publicaciones dentro de una BD.

### `commander_packages`

La tabla mezcla metadatos del tipo de producto con datos operativos de una instancia. Su forma
actual permite cuatro situaciones:

| Situación | `client_id` | `slug`/credenciales | `provisioned_at` | Enruta tráfico |
|---|---:|---|---|---|
| catálogo sincronizado | null | null | null | no |
| alta pendiente | valor | valor | null | no |
| instancia viva | valor | valor | fecha | sí |
| de-provisionada | null | null | null | no |

Una fila de catálogo existe para crear planes antes del primer cliente. Provisionar crea otra
fila; no convierte la fila de catálogo. De-provisionar conserva `id`, nombre, label, descripción,
listing y el historial comercial, pero anula lo operativo.

Campos principales:

- identidad: `id`, `client_id`, `subscription_id`, `name`, `slug`;
- conexión: `database`, `database_username`, `database_password`;
- catálogo: `label`, `description`, `listing_status`;
- estado: `status`, `reason`, `message`, `grace_until`, `changed_at`;
- KPIs: `requests_24h`, `quota_health`, `last_seen`;
- frontera de vida: `provisioned_at`.

`subscription_id` es un puntero operativo sin FK para evitar el ciclo; la relación autoritativa
es `commander_subscriptions.package_id`. `plan` y `price_monthly` se congelan en cada término para
que editar o borrar un plan no reescriba el pasado.

### Cuotas actuales

Cada plan puede sembrar cuatro filas:

| `metric_key` | Fuente |
|---|---|
| `requests_day` | logs de la instancia desde el inicio del día UTC |
| `disk_db_mb` | `information_schema.tables` visto desde la conexión tenant |
| `disk_storage_mb` | tamaño de `storage/app/public/media/{database}` |
| `users_total` | `users` de la base tenant |

`limit_value = null` significa ilimitado. `on_exceed` acepta `none`, `warn` o `suspend`. El motor
escribe `current_value`, `measured_at` y `exceeded_at` en la misma fila que edita Commander.

Estas métricas no equivalen aún a asientos y lecturas/mes de la propuesta comercial de Kilkana;
consulta [Negocio](business.md).

## Esquema Kilkana

Cada base Kilkana contiene Core más veinticuatro tablas de negocio:

| Agregado | Tablas |
|---|---|
| secciones | `kilkana_categories`, `kilkana_category_slugs` |
| medios | `kilkana_media`, `kilkana_media_tags`, `kilkana_media_collections`, `kilkana_media_media_tag`, `kilkana_media_collection_media` |
| firmas | `kilkana_authors`, `kilkana_author_slugs` |
| artículos | `kilkana_posts`, `kilkana_post_slugs`, `kilkana_post_revisions`, `kilkana_post_views`, `kilkana_tags`, `kilkana_post_tag` |
| composición | `kilkana_layouts`, `kilkana_layout_revisions` |
| apariencia | `kilkana_themes`, `kilkana_theme_variants`, `kilkana_theme_categories`, `kilkana_theme_category_theme` |
| identidad del medio | `kilkana_settings` |
| comunidad | `kilkana_comments`, `kilkana_subscribers` |

El detalle de columnas, relaciones y reglas editoriales está dividido por agregado:

1. [Cuentas y permisos](database/kilkana/01-cuentas-y-permisos.md)
2. [Firmas](database/kilkana/02-firmas.md)
3. [Secciones](database/kilkana/03-secciones.md)
4. [Artículos](database/kilkana/04-articulos.md)
5. [Biblioteca de medios](database/kilkana/05-medios.md)
6. [Comentarios y suscriptores](database/kilkana/06-comentarios-y-suscriptores.md)
7. [Composición y ajustes](database/kilkana/07-composicion-y-ajustes.md)

Mapa abreviado:

```text
users ──1:1── authors ──1:N── posts ──N:1── categories
                   │        ├──1:N── post_slugs
media ◄────────────┘        ├──1:N── post_revisions
  ▲                         ├──1:N── post_views
  ├── cover/og de posts     ├──1:N── comments
  └── avatar de authors     └──N:M── tags

layouts ──1:N── layout_revisions
themes  ──1:N── theme_variants ◄──RESTRICT── settings.theme_variant_id
subscribers no depende de ningún otro agregado
```

## Reglas para migraciones nuevas

Estas son las convenciones del proyecto, aunque el esquema histórico tenga excepciones que se
enumeran abajo:

1. PK entera autoincremental con `$table->id()`; la identidad de negocio va en una columna única.
2. Tabla de negocio con prefijo del producto (`kilkana_posts`, `shop_products`), nunca del cliente.
3. Columnas en orden: PK, FKs, negocio por relevancia y timestamps al final.
4. Una tabla por migración y dependencias antes que hijas.
5. Política de borrado explícita: `RESTRICT`, `CASCADE` o `SET NULL` según el significado.
6. No editar una migración ya aplicada esperando que vuelva a correr. Añadir una migración forward.
7. Enums históricos deben congelar sus valores cuando el esquema deje de ser reescribible; importar
   el enum vivo hace que una migración antigua reproduzca la lista de hoy.

No hay migración automática de todos los tenants al arrancar HTTP. El control plane se migra con
`core:migrate`; las instancias nuevas se migran durante provisioning y las existentes tras un
deploy con `core:migrate --all-tenants --force`.

## Orden de migración y seed

```text
core:migrate sin cliente/paquete
  → Core + Commander sobre `commander`

core:migrate --client=elmundo --package=Kilkana
  → Core + Kilkana compartido + overlay ElMundo/Kilkana
  → sobre `elmundo_kilkana`

core:seed con los mismos argumentos
  → roles/base + seeder compartido + seeder overlay
```

El admin tenant se crea con las credenciales elegidas durante el alta. Nunca se copia el operador
de Commander. `KILKANA_SEED_DEMO=false` evita datos y lector demo por defecto.

## Excepciones y deuda conocida

- `kilkana_settings.id` es hoy `unsignedTinyInteger`, no autoincremental, y tiene un `CHECK (id =
  1)`. Es una excepción real a la convención de PK; no debe copiarse a tablas nuevas.
- Los pivotes de permisos y varios pivotes Kilkana no tienen timestamps. Las revisiones guardan
  solo `created_at`; `kilkana_post_views` tampoco lleva timestamps por ser un contador caliente.
- `commander_settings` supone id 1 pero la base no impide una segunda fila.
- La base no garantiza que exista un único término de suscripción con `ended_at IS NULL`; lo
  serializa el servicio con transacción y `lockForUpdate`.
- El único `(client_id, slug)` no evita duplicar filas de catálogo porque ambos valores son `NULL`
  y MariaDB permite múltiples nulos. `firstOrCreate` tampoco es un lock: dos sincronizaciones
  concurrentes pueden competir.
- Catálogo y ciclo de vida comparten tabla. Tras una baja, una fila puede parecer catálogo y una
  operación encolada no tiene `status = provisioning|failed|deprovisioning`. Hace falta un estado
  explícito antes de automatizar reintentos o autoservicio.
- `commander_request_logs` renuncia a FK por coste de escritura; de-provisioning debe borrarlos de
  forma explícita.
- `commander_accounts` agrupa cabeceras, pero `commander_invoices` sigue exigiendo un único
  `package_id`; no existe aún factura consolidada ni bolsa de cuotas compartida.
