# Diseño funcional del SPA de Commander

## Estado y propósito

Este documento es la especificación canónica del futuro cliente web privado de Commander. Define
qué debe poder hacer el operador, cómo se organiza la información y qué datos consume cada vista.
Es deliberadamente agnóstico de framework, librería de componentes y sistema de diseño.

El cliente web todavía no existe. Docker conserva únicamente el perfil opt-in `commander-web`.
Esta guía no convierte una capacidad futura en una API disponible: cuando una pantalla necesita
backend nuevo se marca de forma explícita.

Antes de implementar el cliente deben leerse también:

- [Modelo de Commander](../commander-model.md), para la semántica de las entidades;
- [Flujos](../flows.md), para provisioning, estado, cuotas y baja;
- [Base de datos](../database.md), para relaciones y retención;
- [Dominios propios](../domains.md), para la capacidad multi-host futura.

## Alcance

El SPA pertenece al control plane y lo usan operadores autenticados en la base `commander`.
Gestiona cuentas, clientes, aplicaciones, planes, contratos, instancias, cuotas, facturas,
observabilidad, usuarios operadores y preferencias.

No administra artículos, usuarios, roles ni datos de negocio de Kilkana. Esos datos permanecen en
la base aislada de cada instancia. Tampoco incluye pagos, conciliación, SSO tenant, autoservicio
público ni un editor de código de packages.

## Principios

- La interfaz refleja el modelo real: cuenta es pagador, cliente es tenant, aplicación es producto
  e instancia es el par cliente-aplicación.
- Las vistas globales ayudan a localizar y comparar; las fichas concentran el contexto de una
  entidad mediante pestañas.
- Un formulario largo o con impacto contractual usa una página dedicada. Los diálogos se reservan
  para confirmaciones y acciones breves.
- Ninguna acción aparece como disponible si la API no la soporta.
- Provisioning, cambio de estado, retry y baja muestran sus estados asíncronos; nunca simulan éxito
  antes de recibirlo del servidor.
- Todo estado combina texto e indicador visual. El color nunca es la única señal.
- La URL conserva búsqueda, filtros, orden, página y pestaña para permitir recarga, historial y
  enlaces compartibles.
- La interfaz se redacta en español, muestra fechas en `Europe/Madrid` y cantidades monetarias con
  la moneda del registro.

## Arquitectura de información

La navegación lateral admite dos niveles como máximo. El tercer nivel vive como pestañas dentro
de las fichas.

| Grupo | Entradas |
|---|---|
| Inicio | Resumen |
| Comercial | Cuentas, Contratos, Facturas |
| Catálogo | Aplicaciones, Planes |
| Operación | Clientes, Instancias, Nueva alta, Dominios futuros |
| Observabilidad | Uso, Cuotas, Logs, Actividad |
| Administración | Operadores, Preferencias |

### Rutas del SPA

| Ruta | Vista |
|---|---|
| `/login` | autenticación |
| `/` | dashboard |
| `/accounts`, `/accounts/new`, `/accounts/:id/*` | cuentas |
| `/clients`, `/clients/new`, `/clients/:id/*` | clientes |
| `/applications`, `/applications/:id/*` | catálogo e insights |
| `/plans`, `/plans/new`, `/plans/:id/edit` | planes |
| `/provisioning/new` | alta de instancia |
| `/instances`, `/instances/:id/*` | operación de instancias |
| `/subscriptions`, `/subscriptions/new`, `/subscriptions/:id/*` | contratos |
| `/quotas`, `/quotas/:id` | cuotas |
| `/invoices`, `/invoices/new`, `/invoices/:id` | facturación |
| `/usage` | uso global |
| `/logs` | logs de requests |
| `/activity`, `/activity/:id` | auditoría operativa |
| `/operators`, `/operators/new`, `/operators/:id/edit` | usuarios Commander |
| `/settings` | preferencias |
| `/domains`, `/domains/:id` | dominios propios futuros |

Las pestañas son rutas hijas. Por ejemplo, `/instances/42/usage` conserva el contexto mejor que
un estado local que se pierde al recargar.

## Contrato común de página

Todas las listas comparten:

- título, descripción breve y acción primaria si el operador tiene permiso;
- búsqueda local y filtros relevantes;
- contador de resultados;
- tabla ordenable;
- paginación local mientras la API siga devolviendo arrays completos;
- estado vacío sin datos y otro distinto cuando los filtros no encuentran resultados;
- esqueleto durante la primera carga, indicador discreto al refrescar y error con reintento;
- menú de fila para acciones secundarias;
- enlace en la columna principal hacia la ficha.

Las fichas comparten:

- breadcrumb, título, identificador y estados principales;
- acciones contextuales en la cabecera;
- resumen legible antes de las pestañas;
- timestamps absolutos, con tiempo relativo solo como ayuda;
- campos no editables diferenciados de los editables;
- aviso cuando los datos históricos impiden borrar una entidad.

Los formularios:

- validan al salir del campo y de nuevo al enviar;
- asignan los errores `422` al campo exacto y reservan el aviso global para errores sin campo;
- deshabilitan el envío mientras la misma petición está en curso;
- conservan los valores tras un error;
- advierten antes de abandonar cambios sin guardar;
- nunca muestran contraseñas, tokens ni credenciales runtime ya almacenadas.

## Sesión y permisos

### Login

`POST /api/v1/auth/login` recibe correo y contraseña. Tras autenticar, el SPA obtiene
`GET /api/v1/auth/me` y usa `all_permissions` como fuente de autorización visual.

El token Bearer se conserva en `sessionStorage`, se elimina en logout o `401` y no se comparte
con dominios tenant. No existe “recordarme” mientras la API no ofrezca una sesión más duradera.

### Reglas de autorización

- Una entrada de navegación de listado requiere el permiso `index <recurso>` correspondiente.
- Ver una ficha requiere `show <recurso>`.
- Crear, editar y borrar requieren `create`, `update` y `delete`.
- Las acciones de estado de instancia requieren `control instances`.
- El alta y el retry requieren `provision instances`.
- Uso y logs globales usan `index instances`; insights de código usan permisos de aplicaciones.
- Operadores usa permisos de `users` y Preferencias usa `show settings`/`update settings`.
- La UI oculta acciones no autorizadas, pero siempre maneja `403` porque el servidor sigue siendo
  la autoridad.

El dashboard solo solicita y muestra los bloques para los que exista permiso. La falta de permiso
para facturas, por ejemplo, no convierte todo el dashboard en un error.

## Inicio

### Dashboard

El dashboard responde “¿qué necesita atención ahora?” y no duplica todas las páginas analíticas.

#### Indicadores

| Indicador | Cálculo | Fuente actual |
|---|---|---|
| cuentas activas | cuentas con `status=active` | `GET /accounts` |
| clientes activos | clientes con `status=active` | `GET /clients` |
| instancias listas | `provisioning_status=ready` | `GET /instances` |
| instancias con incidencia | failed, suspended o grace | `GET /instances` |
| MRR contractual | suma de `monthly_amount` en contratos active/trialing | `GET /subscriptions` |
| requests 24 h | suma de `requests_24h` | `GET /instances` |
| cuotas excedidas | cuotas con límite y valor superior al límite | `GET /quotas` |
| facturas vencidas | cantidad y total con `status=overdue` | `GET /invoices` |

#### Visualizaciones

- Serie de requests y errores de las últimas 48 horas desde `GET /usage/platform`.
- Distribución de instancias por estado de provisioning y servicio.
- Consumo más alto por cuota, mostrando valor, límite, unidad y porcentaje.
- Actividad reciente con fecha, tipo, ámbito, razón y enlace a la entidad.

Cada gráfica ofrece el mismo dato como resumen textual y tabla accesible. Un valor derivado en el
navegador se etiqueta como snapshot de los datos cargados, no como ledger nuevo.

## Comercial

### Cuentas

#### Tabla

Columnas: nombre, estado, correo, correo de facturación, NIF, condiciones de pago, número de
clientes, número de contratos y actualización. Carga relaciones mediante
`with=clients,subscriptions,invoices`.

Filtros: estado, país y presencia de contratos. Búsqueda: nombre, correo, correo de facturación y
NIF.

#### Alta y edición

Página dedicada con secciones:

1. identidad y estado;
2. contacto;
3. facturación y condiciones de pago;
4. dirección;
5. notas internas.

#### Ficha

| Pestaña | Contenido |
|---|---|
| Resumen | contacto, dirección, condiciones, MRR contractual, facturación pendiente y salud de sus clientes |
| Clientes | tabla de tenants de la cuenta y acceso a nueva alta |
| Contratos | contratos, capacidad usada, importe y estado |
| Facturas | ledger de la cuenta, importes y vencimientos |
| Actividad | eventos de sus instancias, contratos y facturas cuando estén disponibles |

La cuenta no muestra usuarios tenant ni agrega sus datos de negocio.

Borrar solo se ofrece cuando no existen clientes, contratos ni facturas; en cualquier otro caso
se propone desactivar la cuenta y conservar la historia.

### Contratos

#### Tabla

Columnas: nombre, cuenta, aplicación, modalidad, estado, importe mensual, moneda, cabeceras
activas/capacidad, trial, inicio y fin.

Filtros: cuenta, aplicación, modalidad `catalog|negotiated`, estado y capacidad agotada. La
modalidad, cuenta y aplicación son inmutables después de crear.

#### Crear contrato

Página dedicada para contratos negociados o contratos de catálogo creados manualmente. Incluye
cuenta, aplicación, nombre, modalidad, importe, moneda, estado, capacidad y fechas.

El alta normal ya crea un contrato de catálogo; la interfaz debe advertirlo para evitar duplicados.

#### Ficha

| Pestaña | Contenido |
|---|---|
| Resumen | términos, fechas, precio pactado, capacidad y estado |
| Cabeceras | ítems activos/terminados con cliente, instancia y plan operativo |
| Cuotas compartidas | límites agregados por métrica y su histórico |
| Facturas | líneas que referencian el contrato |
| Actividad | warnings, recomendaciones y cambios de sus instancias |

Acciones: editar términos permitidos, cancelar mediante cambio de estado y añadir cabecera. Borrar
solo se ofrece cuando no existen ítems, cuotas ni líneas de factura.

La capacidad nunca puede quedar por debajo del número de ítems activos. El precio pactado no se
recalcula al cambiar el plan operativo de una cabecera.

### Facturas

#### Tabla

Columnas: número, cuenta, estado, emisión, vencimiento, total y moneda. Filtros: cuenta, estado,
moneda, emisión y vencimiento. Resaltar como atención las vencidas sin alterar automáticamente el
servicio.

#### Crear factura

Página dedicada:

1. cuenta, número, moneda y fechas;
2. líneas repetibles;
3. resumen calculado.

Cada línea selecciona un contrato de la cuenta y opcionalmente una cabecera de ese contrato,
descripción, cantidad y precio unitario. El navegador muestra el subtotal, pero el total devuelto
por el servidor es la cifra autoritativa.

#### Detalle

Muestra cabecera, líneas, total y enlaces a cuenta, contrato e instancia. Tras crear solo pueden
cambiar estado, emisión y vencimiento. Solo una factura draft ofrece borrar; no existe cobrar,
reembolsar, enviar ni descargar PDF hasta que haya backend.

## Catálogo

### Aplicaciones

#### Tabla

Columnas: nombre, slug, package, estado comercial, disponibilidad de código, instancias y planes.
La aplicación se crea y descubre mediante `commander:applications:sync`; no existe botón de alta
ni de borrado en el SPA.

#### Ficha

| Pestaña | Contenido |
|---|---|
| Resumen | nombre, descripción, slug, package, estados y conteos |
| Métricas | key, nombre, unidad, ventana, tipo, medidor y estado |
| Planes | catálogo de la aplicación y acceso a crear plan |
| Instancias | clientes que ejecutan la aplicación y su salud |
| Código | actividad diaria y últimos commits |

Solo nombre, descripción y estado son editables. Slug, package, métricas y `code_status` proceden
del código sincronizado.

La pestaña Código usa `GET /applications/:id/insights`. La vista global puede usar
`GET /commits` y `GET /code-frequency`; no presenta commits compartidos como trabajo repetido
por instancia.

#### Catálogo visible de métricas

Todas las aplicaciones reciben las métricas técnicas y cada package puede declarar otras. La UI
usa nombre y unidad devueltos por la API, pero conserva estas definiciones para no mezclar
conceptos:

| Key | Significado |
|---|---|
| `requests_day` | requests de la instancia desde el inicio del día UTC |
| `disk_db_mb` | tamaño de tablas e índices de su base tenant |
| `disk_storage_mb` | storage conocido en `media/{database}` |
| `disk_total_mb` | suma de base y storage |
| `users_total` | todos los usuarios locales de la instancia |
| `editorial_seats` | Kilkana: usuarios distintos con rol diferente de reader/service |
| `reads_month` | Kilkana: suma de vistas engaged del mes actual |

`users_total` no sustituye a `editorial_seats` y requests HTTP no sustituyen a lecturas. Los
conteos se muestran como enteros; los MB pueden humanizarse a GB conservando el valor exacto en el
detalle. Un valor `null` significa “Sin medición”, nunca cero.

### Planes

#### Tabla

Columnas: aplicación, nombre, slug, precio mensual, estado y resumen de límites. Filtros:
aplicación, estado y rango de precio.

#### Crear o editar

Página dedicada con:

- nombre, slug, descripción, precio mensual y estado;
- una fila por métrica activa de la aplicación;
- límite nullable, porcentaje de aviso, acción y periodos consecutivos;
- comparativa con los demás planes de la aplicación.

La aplicación queda fija tras crear. Límite vacío significa medir sin limitar. El editor explica
que modificar la plantilla no cambia instancias existentes: solo provisioning o cambio de plan
materializa los límites.

El borrado solo se ofrece a planes sin ítems. Un plan usado se desactiva.

## Operación

### Clientes

#### Tabla

Columnas: nombre, slug/subdominio, cuenta, correo, estado, número de instancias y última
actualización. Filtros: cuenta, estado y aplicación provisionada.

#### Alta y edición

La creación solicita cuenta, slug, nombre, correo, estado y notas. Cuenta y slug quedan inmutables
después del alta. La edición solo permite nombre, correo, estado y notas.

#### Ficha

| Pestaña | Contenido |
|---|---|
| Resumen | identidad, cuenta, subdominio técnico, contacto y estado |
| Instancias | una fila por aplicación con plan, contrato, provisioning, servicio y consumo |

No hay pestañas de usuarios, roles o contenido tenant.

Borrar solo se ofrece a clientes sin instancias. Un cliente con historia se desactiva.

### Nueva alta

La página `/provisioning/new` empieza eligiendo uno de tres caminos:

1. **Cliente individual nuevo**: plan, datos de cliente/cuenta y credenciales del administrador.
   Envía un único `POST /provisioning`; Commander crea cuenta, cliente, contrato e instancia.
2. **Aplicación adicional**: cliente existente, plan y credenciales. Envía `client_id`.
3. **Cabecera de grupo**: contrato negociado existente, nuevo cliente de la misma cuenta, plan y
   credenciales. Envía `subscription_id` y `client.account_id`.

Un grupo nuevo se prepara creando primero cuenta y contrato. El asistente enlaza esas pantallas y
permite volver al alta; no finge una transacción distribuida que la API no ofrece.

Pasos:

1. modalidad;
2. aplicación y plan;
3. cuenta, contrato y cliente según modalidad;
4. administrador tenant;
5. revisión;
6. resultado.

La contraseña requiere al menos doce caracteres, letras y números, solo se envía una vez y nunca
se recupera. La revisión muestra la base derivada como información, no como campo editable.

Tras recibir `202`, el SPA abre la ficha de instancia y consulta su estado cada cinco segundos
mientras esté pending o provisioning. Deja de consultar al llegar a ready/failed o tras diez
minutos; en este último caso informa que el trabajo continúa y permite refrescar manualmente.

### Instancias

#### Tabla

Columnas: ID, cliente, cuenta, aplicación, plan, provisioning, servicio, consumo máximo de cuota,
requests 24 h, última actividad y acciones. Filtros: cuenta, aplicación, plan, estado de
provisioning, estado de servicio y salud de cuota.

Los filtros `application_id` y `account_id` se envían al endpoint; los demás son locales hasta
que exista filtrado de servidor.

#### Ficha

| Pestaña | Contenido |
|---|---|
| Resumen | identidad, cliente, cuenta, aplicación, plan, contrato, base y timestamps |
| Operación | provisioning, servicio, razón, mensaje, grace, último error y acciones |
| Uso | requests, 4xx y 5xx por hora/día |
| Cuotas | límites propios, valor, porcentaje, enforcement e histórico |
| Logs | últimos requests de la instancia |
| Actividad | estados, cambios de plan, warnings y recomendaciones |
| Dominios | diseño futuro; no se activa sin API |

La base y el usuario runtime pueden mostrarse a un operador autorizado, pero nunca
`database_password`.

#### Acciones

| Acción | Disponibilidad | Interacción |
|---|---|---|
| Grace | ready | diálogo con horas, razón y mensaje |
| Suspender | ready | confirmación con razón y mensaje |
| Reanudar | ready y no active | confirmación breve |
| Cambiar plan | instancia con ítem | diálogo con planes activos de la misma aplicación |
| Retry | failed o deprovisioned | diálogo con nuevas credenciales admin |
| Deprovisionar | no deprovisioning/deprovisioned | confirmación destructiva escribiendo el slug |

No se permite crear otra instancia para ocultar un fallo. Retry reutiliza el ID. Deprovisioning
conserva la ficha histórica y el SPA consulta cada cinco segundos hasta deprovisioned/failed.

### Dominios propios

Esta sección es futura y no aparece en la navegación funcional hasta existir API. Su contrato de
negocio es [Dominios propios](../domains.md).

La tabla global mostrará hostname, instancia, cliente, aplicación, tipo principal/alias, propiedad,
DNS, TLS, health check, último chequeo y último error. La ficha permitirá verificar, reintentar,
promover y desactivar de forma idempotente.

Hasta entonces la pestaña de instancia puede mostrar solo el subdominio técnico y un texto
“Dominios propios todavía no disponibles”; no debe renderizar controles inertes ni inventar
endpoints.

## Observabilidad

### Uso global

`GET /usage/platform` alimenta una serie temporal por instancia.

Controles: periodo hora/día, rango entre una hora y 366 días, cuenta, aplicación e instancia. Los
tres últimos filtros se aplican localmente después de relacionar cada `instance_id`.

Indicadores: requests, errores, tasa de error, 4xx y 5xx. Visualizaciones:

- requests y errores en el tiempo;
- 4xx frente a 5xx;
- instancias con más tráfico;
- instancias con mayor tasa de error, siempre mostrando volumen para evitar porcentajes engañosos.

Las divisiones por cero devuelven 0 %, no infinito ni dato ausente.

### Cuotas

#### Tabla global

Columnas: ámbito, propietario, aplicación, métrica, valor actual, límite, porcentaje, aviso,
acción, periodos consecutivos, último warning y última medición.

Filtros: ámbito instancia/suscripción, cuenta, aplicación, métrica, acción, sin límite, en aviso y
excedida. El porcentaje es `current_value / limit_value`; un límite nulo se muestra como
“Sin límite” y no produce porcentaje.

#### Detalle

Muestra definición de la métrica, configuración, estado actual y serie diaria de
`measurements`. Editar permite límite, porcentaje de aviso, acción y periodos consecutivos.
No existe borrado de cuota en la API.

Las cuotas de instancia nacen del plan y solo se editan. La acción crear se ofrece únicamente
dentro de “Cuotas compartidas” de un contrato, con métricas de su misma aplicación.

La UI distingue:

- `none`: observación;
- `warn`: aviso;
- `suspend`: grace y posible suspensión;
- `recommend`: propuesta comercial, sin cambio automático.

### Logs

Tabla de alta densidad con fecha, cliente, aplicación, instancia, método, ruta, estado HTTP,
duración, actor, IP, bytes y memoria.

Filtros enviados al servidor: aplicación, instancia, cuenta, desde, clase de estado, método y
límite hasta 500. La fila expandida muestra IDs informativos de usuario/token y el resto de datos,
sin enlazarlos a usuarios Commander.

La vista no ofrece borrado ni exportación porque no existen endpoints. Los logs pueden desaparecer
por retención o deprovisioning.

### Actividad

Tabla cronológica con fecha, tipo, instancia, contrato, factura, razón y mensaje. Cada referencia
válida enlaza su ficha. El detalle muestra el evento completo; no permite editar ni borrar.

Mientras el endpoint no acepte filtros, búsqueda y filtrado son locales. Los eventos representan
auditoría operativa, no un sistema genérico de tareas.

## Administración

### Operadores

Tabla con nombre completo, correo, nombre corto, rol, empresa, teléfono y alta. Las páginas de
crear/editar gestionan esos campos y contraseña.

`GET /users?with=roles` carga el rol. Como hoy solo se siembra `admin` y no existe endpoint de
roles, el formulario usa ese valor fijo; no inventa un selector ni permisos configurables.

El borrado exige confirmación y no permite al operador eliminarse a sí mismo desde la sesión
activa, aunque esta protección debe existir también en backend antes de depender de ella.

### Preferencias

Una sola página edita el singleton:

- nombre y correo del operador;
- horas de grace por defecto;
- notificar suspensiones;
- notificar facturas vencidas.

Guarda parcialmente mediante `PUT /settings`. El texto aclara que las preferencias de
notificación son configuración almacenada; no garantiza un canal de envío que aún no existe.

## Estados y mensajes

### Provisioning

| Valor | Etiqueta | Severidad |
|---|---|---|
| pending | Pendiente | neutral |
| provisioning | Aprovisionando | informativa |
| ready | Lista | correcta |
| failed | Fallida | crítica |
| deprovisioning | Eliminando recursos | aviso |
| deprovisioned | Dada de baja | neutral |

### Servicio

| Valor | Etiqueta | Comportamiento |
|---|---|---|
| active | Activo | sirve tráfico |
| grace | Periodo de gracia | sirve tráfico hasta `grace_until` |
| suspended | Suspendido | responde `402` |

### Contratos y facturas

- Contrato: trialing, active, past_due y canceled.
- Factura: draft, pending, paid, overdue y void.
- Código: available y missing.

Las etiquetas se traducen para mostrar, pero los valores enviados a la API se conservan exactos.

### Respuestas HTTP

| Código | Tratamiento |
|---|---|
| 401 | borrar sesión y volver a login conservando la ruta de retorno |
| 403 | aviso de permisos y vuelta segura |
| 404 | estado “No encontrado”, sin asumir que se borró |
| 409 | conflicto operativo con mensaje y refresco de la entidad |
| 422 | errores de campos o regla de negocio |
| 429 | indicar espera y respetar `Retry-After` si existe |
| 5xx | conservar contexto, ofrecer reintento y no afirmar que una escritura falló definitivamente |

## Contratos API y límites actuales

Todas las llamadas son same-origin bajo `/api/v1`.

### Mapa endpoint-vista

| API actual | Vista o acción |
|---|---|
| `POST /auth/login` | Login |
| `GET /auth/me`, `POST /auth/logout` | sesión, permisos y logout |
| CRUD `/users` | Operadores |
| CRUD `/accounts` | Cuentas |
| CRUD `/clients` | Clientes |
| `GET /applications`, `GET /applications/:id`, `PUT/PATCH /applications/:id` | Aplicaciones |
| CRUD `/plans` | Planes |
| `POST /provisioning` | Nueva alta |
| CRUD `/subscriptions` | Contratos |
| CRUD `/invoices` | Facturas |
| `GET /quotas`, `GET /quotas/:id`, `POST /quotas`, `PUT/PATCH /quotas/:id` | Cuotas |
| `GET /activity`, `GET /activity/:id` | Actividad |
| `GET /usage/platform` | Dashboard y Uso |
| `GET /logs` | Logs globales |
| `GET /commits`, `GET /code-frequency` | actividad global de código |
| `GET /applications/:id/insights` | Aplicación > Código |
| `GET /instances`, `GET /instances/:id` | Instancias |
| `POST /instances/:id/grace`, `/suspend`, `/resume` | Instancia > Operación |
| `POST /instances/:id/change-plan` | cambiar plan |
| `POST /instances/:id/retry` | reintentar alta/baja fallida |
| `GET /instances/:id/usage`, `GET /instances/:id/logs` | Instancia > Uso/Logs |
| `DELETE /instances/:id` | deprovisioning |
| `GET /settings`, `PUT /settings` | Preferencias |

| Capacidad | Estado | Decisión del SPA |
|---|---|---|
| auth y permisos efectivos | disponible | usar login/me/logout |
| CRUD de cuentas, clientes, planes, contratos, facturas y operadores | disponible | implementar según permisos y restricciones |
| aplicaciones | lectura y edición parcial | alta/sync sigue por CLI |
| provisioning y retry | disponible/asíncrono | seguimiento por `GET /instances/:id` |
| control y baja de instancias | disponible | confirmación y seguimiento |
| uso, logs, actividad e insights | disponible | visualizaciones y tablas |
| cuotas de instancia | lectura/edición | creación solo mediante provisioning/cambio de plan |
| cuotas compartidas | lectura/creación/edición | crear desde contrato |
| filtros y paginación general | incompleto | cliente en v1; backend antes de gran volumen |
| resumen de dashboard | sin endpoint propio | derivar de listados en v1 |
| roles configurables | sin endpoint de catálogo | usar únicamente el rol existente |
| dominios propios | no implementado | documentar, no activar controles |
| sync de aplicaciones, colas y scheduler | solo operación/CLI | no añadir botones ficticios |
| pagos, PDF, envío de factura y exportaciones | no implementado | no mostrar acciones |

### Umbral para ampliar backend

La primera versión reutiliza endpoints actuales. Antes de operar cientos de cuentas o miles de
logs debe añadirse paginación, búsqueda y filtros de servidor con un contrato uniforme. Un endpoint
de dashboard solo se justifica cuando las llamadas paralelas y agregaciones locales sean un coste
medido, no como requisito previo del primer SPA.

## Diseño responsive y accesibilidad

- El escritorio es el entorno principal, pero todas las operaciones siguen siendo utilizables
  desde 320 px.
- La navegación se repliega en pantallas pequeñas y conserva nombre de página y acción primaria.
- Las tablas anchas permiten desplazamiento horizontal; las columnas de identidad y estado se
  priorizan antes que datos secundarios.
- Los formularios pasan de dos columnas a una sin cambiar el orden lógico.
- Cada página tiene un único título principal y una jerarquía de encabezados sin saltos.
- Todo input tiene etiqueta visible, ayuda y error asociados.
- Toda interacción funciona con teclado y conserva foco visible.
- Tras navegar, el foco pasa al título; tras borrar, vuelve a la siguiente fila o a la tabla.
- Los diálogos atrapan y devuelven el foco.
- Estados, gráficas y validaciones no dependen solo del color.
- El contraste mínimo es WCAG 2.2 AA y el zoom al 200 % no oculta acciones.
- El movimiento no es necesario para comprender cambios y respeta reducción de movimiento.

## Escenarios de aceptación

### Aplicación sencilla

1. El operador ve DemoApp y su plan activo.
2. Inicia alta individual y crea cuenta, cliente, contrato e instancia.
3. Observa pending/provisioning hasta ready.
4. Abre plan, cuotas, uso y logs desde la ficha.
5. Cambia el plan, aplica grace, suspende, reanuda y da de baja con los permisos correctos.

### Kilkana individual

1. Una cuenta y un cliente representan un único diario.
2. Commander solo conserva el administrador inicial enviado durante provisioning.
3. La ficha no intenta listar usuarios editoriales de Kilkana.
4. Asientos y lecturas aparecen como métricas de la instancia.

### Grupo Kilkana

1. El operador crea una cuenta y un contrato negotiated con `max_instances=3`.
2. Añade tres clientes/cabeceras usando el mismo contrato.
3. Cada instancia muestra su plan, base, cuotas, logs y estado independientes.
4. El contrato muestra las tres cabeceras y una cuota compartida de lecturas.
5. Una cuarta cabecera se rechaza sin aparecer como alta correcta.

### Contrato personalizado

1. El operador registra 5.000 EUR mensuales y capacidad cinco.
2. Cada cabecera puede usar un plan operativo distinto.
3. Las cuotas sin límite se muestran como observación, no como cero.
4. El precio contractual permanece igual al cambiar un plan.

### Fallos e historia

1. Una instancia failed muestra `last_error` y ofrece retry con nuevas credenciales.
2. Deprovisioning termina en deprovisioned o failed sin desaparecer de los listados históricos.
3. Una factura no draft no ofrece borrar.
4. Un contrato con ítems, cuotas o facturas ofrece cancelar, no borrar.

### Dominios futuros

1. Mientras no exista API, solo se muestra el subdominio técnico.
2. Cuando se implemente, un hostname pendiente nunca aparece como activo.
3. La ficha separa propiedad, DNS, TLS y health check.
4. Fallar el dominio propio conserva visible el fallback técnico.

## Criterio de finalización del futuro SPA

El cliente se considera funcional cuando:

- cada ruta y acción actual de Commander tiene una representación coherente;
- permisos, estados y errores se comprueban en navegación y acciones;
- los cuatro escenarios de negocio anteriores funcionan sin acceder a datos tenant;
- las vistas responden a teclado, móvil, zoom y lector de pantalla;
- tests cubren login, permisos, tablas, formularios, provisioning, acciones destructivas y errores;
- las capacidades futuras permanecen claramente separadas de las disponibles.

El framework, la librería de componentes y la herramienta de gráficas se eligen al comenzar la
implementación. Ninguna decisión de este documento exige una tecnología concreta.
