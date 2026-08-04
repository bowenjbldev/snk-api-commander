# Commander — diseño de la consola con Ant Design 5

Especificación de diseño de **Commander**, la consola de operación de una plataforma SaaS
multi-tenant, construida sobre **Ant Design 5**. Las decisiones están tomadas; este documento las
fija con sus valores y su porqué.

---

## 1. El producto

Un solo operador administra desde aquí toda la plataforma: da de alta clientes, provisiona
instancias de producto, vigila consumo, ajusta cuotas, corta el suministro a quien no paga y lo
restablece.

Una única aplicación Laravel sirve a todos los clientes. La identidad de una petición sale de la
URL:

```
{cliente}.{dominio}/api/v1/{producto}
```

El **cliente** es el subdominio, el **producto** es el segmento de path, y cada par
`(cliente, producto)` provisionado tiene **su propia base de datos**. No hay columna de tenant; un
middleware conmuta la conexión por petición.

Lo que la consola **no** hace, y por tanto no aparece: no genera ni despliega código, no gestiona
DNS, certificados ni copias de seguridad, no cobra —el libro de facturas es manual, sin pasarela—
y no tiene autoservicio.

**Un operador, uso diario, conocimiento experto.** Sin equipos, sin invitaciones, sin onboarding.

---

## 2. El dominio

### Cliente

`slug` único —que además es el subdominio DNS real—, `name`, `company`, `email`, `phone`, `notes`,
`status` (`active` | `inactive`), y bloque de facturación: email de facturación, identificador
fiscal, dirección en dos líneas, ciudad, código postal, país (ISO-2) y plazo de pago en días.

### Producto — la entidad central

Una sola tabla mezcla **catálogo** y **ciclo de vida**. La misma fila adopta cuatro situaciones, y
distinguirlas **en una lista, sin abrir nada** es el trabajo principal de la consola:

| Situación | ¿Cliente? | `provisioned_at` | ¿Sirve tráfico? | Qué es |
|---|---|---|---|---|
| Catálogo | no | null | no | Existe en disco y se puede vender. Nadie lo usa. |
| Alta pendiente | sí | null | **no** | Comprado; el trabajo de fondo aún no ha creado su base de datos. |
| Instancia viva | sí | fecha | **sí** | En producción. |
| Dada de baja | no | null | no | Estuvo viva, se destruyó. Conserva su histórico de facturación. |

**El mismo nombre de producto se repite entre clientes.** El nombre no identifica; el identificador
numérico sí.

Campos: identidad (`name`, `slug`), catálogo (`label`, `description`, `listing_status`), estado
operativo (`status`, `reason`, `message`, `grace_until`, `changed_at`), indicadores
(`requests_24h`, `quota_health`, `last_seen`) y la frontera de vida (`provisioned_at`). Las
credenciales de base de datos existen y **nunca se muestran**.

### Plan, suscripción, factura

- **Plan**: nombre, slug, precio mensual, límites de cuota por defecto, activo o no.
- **Suscripción**: historial de términos; el vigente no tiene fecha de fin. Guarda **instantánea**
  de nombre y precio, para que cambiar el catálogo no reescriba el pasado.
- **Factura**: número único, cliente, instancia, importe, emisión, vencimiento, estado
  `paid` | `pending` | `overdue`.

### Cuota

Una fila por métrica e instancia: clave, `limit_value` (**null = ilimitado**), `current_value`,
`measured_at`, `exceeded_at` y `on_exceed` ∈ `none` | `warn` | `suspend`.
Cuatro métricas: `requests_day`, `disk_db_mb`, `disk_storage_mb`, `users_total`.

### Registro, agregados, actividad, preferencias, código

Registro de peticiones (instante, método, ruta, código, duración, usuario o token, IP, bytes,
memoria — incluidas las rechazadas por corte y por límite de tasa); agregados por hora y día;
bitácora de actividad; fila única de preferencias con la duración por defecto del preaviso
(`default_grace_hours`); y tres tablas de actividad de código.

---

## 3. Estados y reglas

### El suministro de una instancia

```
active ──────► grace ──────► suspended
  │              │                │
  └──────────────┴────────────────┘
              (resume → active)
```

Transiciones válidas y ninguna más: `active → grace`, `active → suspended`, `grace → active`,
`grace → suspended`, `suspended → active`.

- **`active`**: sirve con normalidad.
- **`grace`**: sigue sirviendo, con cabeceras de aviso, y **tiene fecha límite**.
- **`suspended`**: corte. Las peticiones del cliente reciben `402` con motivo interno y mensaje
  redactado para su público.

**El cuarto estado, el que no está en los datos.** Cuando vence la fecha límite del preaviso, el
backend trata la instancia como cortada aunque la columna siga diciendo `grace`. Ningún proceso
reescribe ese campo. Existe un **preaviso caducado** que la consola deduce del reloj, y que
significa que ese cliente ya no está servido.

### Alta de una instancia

Plan existente + cliente (nuevo o existente) + credenciales del administrador de la instancia.
Validación en caliente —plan activo, cliente activo, slug válido para DNS, contraseña de doce
caracteres o más con letras y dígitos— y respuesta **`202`**: aceptada, no completada. El trabajo
de fondo crea la base de datos, migra, siembra y crea el administrador.

**El hueco real**: no existen estados `provisioning`, `failed` ni `deprovisioning`. Si el encolado
falla, la fila se queda pendiente **para siempre**. La única señal es **la antigüedad del
pendiente**: dos minutos es normal, dos días es una avería. La consola lo dice; no inventa estados
que la API no tiene.

### Baja

Destruye base de datos, usuario, ficheros, registros y cuotas. Irreversible. Corta el tráfico en el
acto. Conserva la fila, los metadatos de catálogo y **todo el histórico de facturación**.

### Cambio de plan

Instancia viva y plan activo del mismo producto. Cierra el término vigente y abre otro con
instantánea de nombre y precio, y reajusta cuotas. **Sin prorrateo**: el nuevo término nace
`pending`.

### Lectura de contadores y disparo

Cada seis horas, y actúa solo:

| Situación | Política | Consecuencia |
|---|---|---|
| Por debajo del límite | cualquiera | Limpia la marca. Restablece **solo** si esa métrica causó el corte y ninguna otra excede. |
| Excedida | `none` | Lo registra. |
| Excedida | `warn` | Deja un aviso. |
| Excedida, estando activa | `suspend` | Abre preaviso, motivo `quota_exceeded:<métrica>`. |
| Excedida, preaviso vencido | `suspend` | Corta. |

**Un corte manual, o por impago, no se cura bajando una cuota.**

### Facturas

Marcar vencida abre una decisión inmediata: **dejar correr**, **abrir preaviso** o **cortar ya**.
Marcar pagada puede ofrecer **restablecer** si ese impago causó el corte. Cobro y suministro se
leen juntos pero son campos distintos.

---

## 4. API y permisos

Todo bajo `/api/v1`, con token **Bearer**; cualquier `401` cierra la sesión.

```
POST   auth/login (con límite de intentos) · GET auth/me · POST auth/logout

GET|POST|PATCH|DELETE  users · clients · plans · subscriptions · invoices
GET                    quotas · quotas/{id}          PATCH quotas/{id}
GET                    activity · activity/{id}

POST   provisioning

GET    packages        (sincroniza el catálogo desde disco al listar)
GET    packages/{id}   ·  PATCH packages/{id}  ·  DELETE packages/{id}
POST   packages/{id}/suspend · grace · resume · change-plan
GET    packages/{id}/usage     (?period=hour|day&from&to)
GET    packages/{id}/logs      (?limit&since&status_class)
GET    packages/{id}/insights  (?days)

GET    usage/platform  (?period&hours)
GET    logs            (?package&limit&since&status_class&method)
GET    commits         (?limit)
GET    code-frequency  (?days)

GET    settings  ·  PUT settings
```

**Las llamadas a una instancia usan siempre su identificador numérico, jamás su nombre.**

Carga inicial: nueve peticiones en paralelo (clientes, productos, suscripciones, facturas, cuotas,
planes, actividad, commits, preferencias).

**Permisos** por recurso y acción, más **controlar productos** y **provisionar productos**. La
consola los usa para ocultar lo que no procede; **no es una frontera de seguridad**. «Sin permiso»
es un estado de componente más, junto a cargando, vacío y error.

---

## 5. Frescura del dato

| Cadencia | Qué se actualiza |
|---|---|
| cada 10 minutos | peticiones de las últimas 24 h, salud de contador, última actividad |
| cada hora | agregados de uso, poda de registros, sincronización de commits |
| cada 6 horas | lectura de contadores y aplicación de políticas |

La consola **no promete tiempo real por defecto**. Una salud de contador puede llevar seis horas de
retraso; decir «ahora mismo» sería mentir. Lo único vivo es el paso del tiempo.

### El canal en vivo

Se contempla una capa de eventos por WebSocket, autenticada con el mismo token y con un único
destinatario. **La consola funciona entera sin ella y la absorbe sin tocar el diseño.**

| Evento | Por qué importa |
|---|---|
| Alta terminada o fallida | Cierra el hueco del `202`. |
| Cambio de suministro | La lectura de contadores actúa sola cada seis horas: puede cortar a un cliente sin que el operador toque nada. |
| Baja terminada | La destrucción es asíncrona. |
| Factura vencida detectada | Enlaza cobro con suministro. |
| Peticiones nuevas | Caudal continuo, se trata aparte. |

Cómo encaja, en términos de este sistema:

- **Ninguna pantalla «en vivo» separada.** Las mismas tablas absorben el empuje.
- Al caer la conexión, la consola **degrada sola** a la cadencia de arriba y lo dice en el pie del
  armazón. No se rompe, no vacía, no bloquea.
- Un empuje que llega durante una mutación optimista: **gana el servidor**; la fila se resalta una
  vez para acusar la corrección.
- **Nunca se mueve el suelo bajo el cursor**: lo que llega suma en un contador de la barra de
  herramientas de la tabla, y solo se integra al pulsarlo.
- El caudal de registros se pausa al desplazarse.
- Un corte automático o un alta fallida **no** son un aviso efímero: van a la bandeja de atención,
  que persiste. Los avisos efímeros se reservan para confirmar lo que el operador acaba de hacer.

---

## 6. Volumen

Esta consola vive de tablas. El explorador de registros se prueba con **cien mil filas** y
trescientos sesenta y cinco días. Una flota tiene decenas de instancias. El libro de facturas crece
indefinidamente. Filtrar, ordenar, buscar y paginar sobre eso es el caso normal.

---

## 7. La dirección: los mandos se ven, las medidas se leen

El dominio no necesita metáfora: **ya es** el de una compañía de suministro. Instancia provisionada
= acometida dada de alta. `active` = conectado. Cuota y su lectura cada seis horas = contador y su
lectura periódica. `on_exceed: suspend` = umbral de disparo del limitador. `grace` con fecha límite
= preaviso antes del corte. `suspended` = corte. `resume` = reenganche. Plan = tarifa.

Sobre ese dominio, este sistema impone su propia lógica cromática, y la dirección la aprovecha en
lugar de pelearse con ella:

> **El color primario significa «esto se toca». El color semántico significa «esto es así».
> Nunca al revés, y nunca mezclados en el mismo elemento.**

Este sistema pone el color primario en botones, enlaces, pestaña activa, fila seleccionada y
anillo de foco: es el vocabulario de la interacción, y funciona. Lo que hay que hacer es **no
dejar que ese vocabulario invada la medición**. En concreto:

- Un botón, un enlace o una pestaña activa llevan primario. Siempre.
- Un estado de suministro, una salud de contador, una clase HTTP o una serie de gráfica llevan
  color semántico. **Nunca primario.**
- Una etiqueta de estado **no es** un botón: no se colorea con el primario aunque sea pulsable.
- El tono primario se elige **lejos en matiz de la triada de estado**, para que ningún control
  pueda leerse como una medida.

Esa separación es la que evita el fallo habitual de una consola densa: que todo esté coloreado y
nada signifique nada.

---

## 8. Sistema visual

### 8.1 Tokens semilla

Aceptar los valores de fábrica es exactamente lo que produce una consola indistinguible de otras
mil. Cada semilla va fijada con su porqué.

| Semilla | Valor | Por qué |
|---|---|---|
| `colorPrimary` | `#5B4FD6` | Iris. Lejos en matiz del verde, el ámbar y el rojo de estado: un control nunca puede leerse como una medida. Y no es el azul de fábrica, que es la firma reconocible del sistema. |
| `colorSuccess` | `#1F9D62` | Verde de conexión, rebajado en croma respecto al de fábrica para que no compita con el primario. |
| `colorWarning` | `#C8860D` | Ámbar de preaviso. |
| `colorError` | `#C4443A` | Rojo de corte. |
| `colorInfo` | `#3D77C2` | Solo para provisionado en curso. |
| `borderRadius` | `4` | Cuatro y no seis: aprieta la consola sin caer en la esquina recta, que ya es un cliché de panel denso. |
| `fontFamily` | `'Public Sans Variable', system-ui, sans-serif` | Registro institucional, formas ligeramente estrechas: densidad sin sacrificar lectura. No es la pila del sistema, que es lo que delata la instalación por defecto. |
| `fontSize` | `13` | Base de trabajo para una consola de datos. |
| `controlHeight` | `28` | Controles compactos, coherentes con la altura de fila. |
| `sizeStep` · `sizeUnit` | `4` · `4` | Escala de espaciado de paso 4. |
| `wireframe` | `false` | — |
| `motionUnit` | `0.08` | Transiciones más cortas que las de fábrica: una herramienta de uso diario no debe hacer esperar. |

**Fuentes**: `Public Sans Variable` para la interfaz, **`DM Mono`** para toda lectura —
identificadores, slugs, rutas, URLs, importes, cifras, marcas de tiempo, cuentas atrás, SHA, IP,
códigos de estado. Cifras tabulares siempre. Ambas OFL, autoalojadas.

La regla que da coherencia: **si es un dato que el sistema generó, va en mono; si es lenguaje que
escribimos nosotros, va en la familia de interfaz.**

### 8.2 Algoritmos: densidad y tema

Los dos temas y la densidad salen del **mismo juego de semillas**, componiendo algoritmos:

```
claro    → [defaultAlgorithm, compactAlgorithm]
oscuro   → [darkAlgorithm,    compactAlgorithm]
```

`compactAlgorithm` en toda la consola, no por pantalla: la densidad es una propiedad del producto,
no una preferencia. El tema se persiste y se aplica en el proveedor de configuración raíz.

**No se derivan colores a mano.** Los tonos de fondo, borde, texto y estado salen de las semillas
por el algoritmo. Escribir un hex suelto en un componente es la vía por la que un tema se rompe al
cambiar al otro.

### 8.3 Tokens por componente

Ajustes puntuales, todos por la vía de configuración del sistema, ninguno por CSS:

| Componente | Ajuste | Por qué |
|---|---|---|
| `Table` | alto de celda 32 · 28 en registros; cabecera con fondo elevado; sin franjas alternas | La franja alterna compite con el color semántico de la fila. |
| `Table` | radio 0 en la propia tabla | Las filas son un continuo; el radio lo pone el panel que la contiene. |
| `Card` | relleno de cuerpo `0` cuando contiene una tabla | El ancho es el recurso escaso: una tabla va a sangre dentro de su tarjeta. |
| `Tag` | radio 2 · versalitas · ancho mínimo fijo | Para que las columnas de etiqueta cuadren en vertical. |
| `Statistic` | familia mono, cifras tabulares | Es una lectura. |
| `Menu` | sangría reducida, sin icono de expansión | Un raíl, no un árbol. |
| `Tooltip` | retardo de entrada 400 ms | Con datos densos, un tooltip inmediato es ruido. |

### 8.4 Superficie y espacio

Jerarquía de planos por **relleno**, no por sombra: fondo de aplicación → tarjeta → cabecera de
tabla. Las divisiones de 1 px separan dentro de un plano, no simulan uno. Las sombras se reservan
para lo que flota: modales, cajones, menús desplegables.

| Elemento | Alto |
|---|---|
| Fila de tabla | 32 |
| Fila compacta (registros, caudal) | 28 |
| Control | 28 |
| Cabecera de tarjeta | 40 |
| Cabecera de aplicación | 48 |
| Pie del armazón | 26 |

---

## 9. Lo que este sistema no trae y hay que construir

Tres piezas nacen del negocio, no de la estética, y ningún sistema las da hechas. Son las que
diferencian esta consola de un panel genérico.

### 9.1 La línea de suministro

Cada fila que representa una instancia lleva a su izquierda una **línea vertical de 3 px** que
codifica el suministro por **color y por forma**:

```
┃━━━━━━  Live              línea llena, verde
┃▔▔▔▔    Notice            segmento que MENGUA hasta el corte
┆        Cut off           línea hueca, roja
╎        Provisioning      punteada, azul
╎        Stalled           punteada, roja
         Not provisioned   sin línea: no hay suministro que representar
```

Se implementa con la clase por fila que expone la tabla del sistema y un pseudoelemento; no
requiere una columna.

Por qué esto y no una columna de etiqueta:

1. **No consume ancho** en una tabla que ya va justa.
2. La salud de toda la flota se lee **con la visión periférica**.
3. El preaviso deja de ser un número que hay que leer: **se ve encogerse**.
4. Cuando el segmento llega a cero, el preaviso ha caducado y esa instancia ya no sirve — el ojo lo
   capta antes que cualquier etiqueta.
5. Distingue las cuatro situaciones **por forma antes que por color**: funciona con visión de color
   reducida y en escala de grises.

La etiqueta de texto sigue existiendo en la cabecera de detalle, en la bandeja de atención y para
el lector de pantalla. La línea es la capa que se lee primero, no la única.

### 9.2 El contador con umbral de disparo

La barra de progreso del sistema muestra un porcentaje. **Una cuota no es un porcentaje**: es una
lectura con un límite **y** un punto en el que salta el limitador. Ver que algo se acerca al techo
importa más que ver que ya lo pasó.

```
requests_day
▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓░░░░░░│░░░   82,400 / 100,000        suspend
                       └ 80%  umbral de aviso
```

- Relleno neutro por debajo del 80 % —estar bien no es una señal—; ámbar entre 80 y 100; rojo por
  encima, con el exceso saliendo del carril.
- **Marca del umbral** siempre visible.
- La política (`none` / `warn` / `suspend`) va en el propio instrumento: un contador con `suspend`
  no significa lo mismo que uno con `none`.
- Límite vacío = ilimitado: carril tenue, sin relleno, sin umbral.

### 9.3 La bandeja de atención

Lista persistente de lo que exige decisión: preavisos corriendo, pendientes atascados, facturas
vencidas y contadores por encima del umbral, cada uno con su acción directa. No es un centro de
notificaciones genérico: es la cola de trabajo del operador, y sobrevive a la recarga.

### Lo que sí se toma tal cual del sistema

Tabla con desplazamiento virtual, cabecera fija, columnas fijas, orden, filtros, selección y filas
expandibles. Formularios con su validación. Descripciones para las fichas. Etiquetas, insignias,
segmentado, esqueletos, vacíos, resultados, alertas, modales, cajones, desplegables, menús y
avisos. **No se reimplementa nada de esto.**

---

## 10. Estructura

```
┌────┬──────────────────────────────────────────────────────────┐
│    │  Fleet                                     [Provision]   │ 48
│ ▤  ├──────────────────────────────────────────────────────────┤
│ ◆  │  Live 24   Notice 2   Cut off 1   Pending 1   Catalog 3  │
│ ▦  │ ┌──────────────────────────────────────────────────────┐ │
│ ▧  │ │ [search]  [situación] [estado] [listado]   [3 nuevas]│ │ 40
│ ▨  │ ├──────────────────────────────────────────────────────┤ │
│ ▩  │ │┃ the-beacon    Kilkana   Pro    12.4k  ▓▓▓░ 62%  2m  │ │ 32
│ ⚙  │ │┃▔el-mundo      Kilkana   Scale  88.1k  ▓▓▓▓ 103% 1m  │ │
│    │ │┆ el-confid…    Kilkana   Pro        0  ▓░░░ 18%  3d  │ │
│    │ └──────────────────────────────────────────────────────┘ │
├────┴──────────────────────────────────────────────────────────┤
│ ● Live   Data 4m old   3 need attention              ⌘K       │ 26
└───────────────────────────────────────────────────────────────┘
```

- **Armazón del sistema**: barra lateral colapsada a raíl de iconos por defecto, cabecera de 48 y
  contenido. La barra lateral es el mapa; no es el vehículo principal.
- **Paleta de comandos (⌘K)** como navegación real: salta a cualquier instancia, cliente o factura
  y ejecuta las acciones de control. Un operador diario rentabiliza el teclado.
- **Pie permanente**: estado de conexión, edad del dato más antiguo, cuántas cosas piden atención y
  la pista de atajo. Ahí aterriza el estado del canal en vivo, sin competir con el contenido.
- **Una sola acción primaria por pantalla**, a la derecha de la cabecera.
- **Detalle de instancia**: cabecera con identidad y menú de control, **pestañas del sistema** para
  las siete vistas, y descripciones para los metadatos.

---

## 11. Las pantallas

Las columnas de cada tabla y sus filtros son **lógica de negocio**: se conservan tal cual.

### Panorama

Responde «¿tengo que actuar?» en tres segundos. Selector de ventana (48 h, semana, mes, trimestre,
año) que gobierna toda la pantalla.

Lecturas: instancias vivas, en preaviso, cortadas; peticiones; proporción de error; ingreso mensual
recurrente con su variación. Gráficas: peticiones por instancia, proporción de error `4xx`/`5xx`,
frecuencia de código. Paneles: actividad de código reciente y **bandeja de atención**.

### Cartera de clientes

**Columnas**: Cliente (con estado) · MRR · Tráfico 24 h (tendencia) · Salud de contador · Última
actividad.
**Filtros**: estado · cobro · salud · país.
**Acción**: dar de alta un cliente, en cajón lateral.

### Ficha de cliente

Datos comerciales y de facturación editables en su sitio, con guardado que aparece solo cuando hay
cambios. Columna derecha con sus instancias y sus facturas. Alerta persistente si algo suyo está en
apuros.

### Flota

Donde conviven las cuatro situaciones. Distinguirlas es su trabajo.

**Columnas**: Producto (con situación) · Cliente · Plan · Tráfico 24 h · Salud de contador · Última
actividad.
**Filtros**: situación (viva / pendiente / solo catálogo) · estado operativo · estado de listado.
**Acción**: provisionar instancia — plan, cliente nuevo o existente, credenciales del
administrador.

### Instancia

Cabecera con identidad, línea de suministro, URL pública copiable y menú de control: cambiar plan,
abrir preaviso, extender preaviso, restablecer, cortar.

- **Identidad**: metadatos de catálogo editables; descripciones con cliente, suscripción, alta y
  última actividad, más los contadores en pequeño.
- **Planes** — Columnas: Plan · Precio/mes · Límites de cuota · Estado · Acciones. Con historial de
  términos.
- **Contadores** — Columnas: Métrica · Consumo actual · Límite (editable en la celda, vacío =
  ilimitado) · Política al exceder · Excedida desde. Filtros: métrica · solo las excedidas.
- **Uso**: hora o día; lecturas de peticiones, error, latencia media y percentil alto, usuarios
  únicos y transferencia; cuatro gráficas; y **rutas más pedidas** — Columnas: Ruta · Peticiones ·
  Proporción de error · Latencia.
- **Registros** — Columnas: Instante · Método · Ruta · Código · Duración.
- **Código**: mapa de calor de contribuciones, frecuencia semanal, y commits — Columnas: SHA ·
  Mensaje · Autor · Fecha · Líneas añadidas/borradas.
- **Configuración y baja**: datos técnicos, y una zona irreversible que exige escribir el
  identificador exacto.

### Libro de facturas

**Columnas**: Número · Cliente · Instancia · Importe · Emisión · Vencimiento (con días de retraso y
la consecuencia que acarrea) · Estado.
**Filtro**: estado.
**Acciones por fila**: editar, marcar pagada, marcar vencida, borrar. Marcar vencida abre la
decisión de suministro; marcar pagada ofrece restablecer si ese impago causó el corte.

### Uso agregado

La misma pregunta que el uso de una instancia, para toda la plataforma. Ventana de 48 h, dos
gráficas y las instancias que más consumen.

### Explorador de registros

Cien mil filas con desplazamiento virtual. **Columnas**: Instante · Instancia · Método · Ruta ·
Código · Duración · IP. **Filtros**: instancia · método · clase de código. Solo lectura.

### Preferencias

Perfil del operador, duración por defecto del preaviso, avisos y tema.

### Entrada

Formulario de email y contraseña sobre fondo liso. Una pantalla, un error posible, sin credenciales
de ejemplo a la vista.

---

## 12. Decisiones de ecosistema

### Gráficas: la librería del propio sistema

**La librería de gráficas hermana de este sistema, no una externa.** Motivos:

- Hereda la configuración de tema, así que el cambio claro/oscuro no exige una capa de traducción
  de tokens escrita a mano — que es donde se rompen los temas.
- Cubre todo lo que la consola necesita: líneas, áreas apiladas, barras apiladas y divergentes, y
  el mapa de calor de contribuciones.
- Una sola librería de gráficas en el paquete.

Lo que **no** se resuelve con ella y se hace en SVG propio: las tendencias por fila de las tablas
—una instancia de gráfica por fila es inaceptable con cien mil filas— y el grafo de commits.

**Paleta de series**, fijada aquí y no heredada del tema por defecto:

```
#4B79C4   azul       #3AA6A0   cian
#3F9E6B   verde      #C08A2E   ámbar
#B85A4F   rojo
```

Cuando una serie representa un estado —errores, cortes— **usa el color semántico de §8.1**, no el
de esta paleta.

### Componentes de producto: no

Existe una capa de componentes de alto nivel sobre este sistema —tablas con esquema declarativo,
formularios y armazones ya montados—. **Aquí no se usa**, por dos motivos: añade una segunda
convención sobre la que ya trae el sistema base, y esta consola tiene once superficies con formas
muy distintas, no treinta CRUD idénticos, que es donde esa capa se amortiza.

El criterio queda escrito por si cambia: si la consola llegara a tener más de quince pantallas de
listado-detalle con la misma forma, esa capa pasa a ser la opción correcta.

### Estilo: cero CSS suelto para color

Todo el color sale de las semillas y de los tokens por componente. **Ningún hex escrito en un
componente.** El CSS propio se limita a maquetación —rejillas, posiciones fijas— y a las tres
piezas de §9. Si aparece un color en una hoja de estilos, es un tema roto esperando a que alguien
cambie de claro a oscuro.

---

## 13. Movimiento

Tres, y ninguno decorativo:

1. **Conexión y corte** — 400 ms. La línea de suministro transiciona de color y de forma cuando
   cambia el estado. Es el único momento con gracia de la consola, y es semántico.
2. **Llegada acumulada** — el contador de novedades incrementa con un pulso de 120 ms. Las filas no
   se inyectan.
3. **Entrada de capa** — la del propio sistema, acortada por `motionUnit`.

La cuenta atrás actualiza a 1 Hz desde **un solo reloj compartido**; las tablas no se repintan cada
segundo.

Con movimiento reducido: la línea cambia sin transición, el contador incrementa sin pulso, y las
transiciones del sistema se desactivan.

---

## 14. Voz

Interfaz en inglés. Registro de mesa de operación: los mandos se nombran por lo que hacen, y el
nombre se mantiene igual en la confirmación y en el aviso posterior.

| En vez de | Se dice |
|---|---|
| Suspend | **Cut off supply** |
| Enter grace period | **Start notice period** |
| Extend grace | **Extend notice** |
| Resume | **Restore supply** |
| De-provision | **Destroy instance** |
| Submit | **Save changes** · **Create client** · **Provision instance** |

Un mando que dice *Cut off supply* produce un aviso que dice *Supply cut off*, no *Package
suspended*.

Los errores dicen qué pasó y qué hacer, sin disculparse:

> *This plan belongs to a different product. Pick a plan for Kilkana.*
> *Provisioning was accepted 3 days ago and never completed. Check the queue worker.*

Una pantalla vacía es una invitación a actuar:

> *No instances yet. Provision one to start serving traffic.*

Nombres del lado del operador: **supply**, **meter**, **notice period**, **tariff**. No *status
flag*, *quota row*, *grace column*.

---

## 15. Accesibilidad y rendimiento

- **Contraste AA verificado** en ambos temas. Las semillas de estado se comprueban sobre el fondo
  que el algoritmo genera para cada tema, no sobre un blanco supuesto.
- El color **nunca** es la única señal: el suministro va también por forma de línea y por texto.
- Teclado completo. La gestión de foco y la semántica de asistencia las aporta el sistema; lo que
  hay que verificar es lo propio: la línea de suministro, el contador y la paleta de comandos.
- La tabla de cien mil filas usa desplazamiento virtual, no paginación disfrazada.
- Las tendencias por fila son SVG, nunca una gráfica por fila.
- La consola **no bloquea hasta tener los nueve recursos**: el armazón entra primero y cada tarjeta
  resuelve el suyo con su propio esqueleto, del alto exacto del contenido.
- Mutaciones optimistas con reconciliación; si falla, aviso claro y vuelta atrás.
- Escritorio primero, con degradación razonable.

---

## 16. Stack y arranque

| Pieza | Elección |
|---|---|
| Base | React 19 · TypeScript · Vite |
| Sistema | Ant Design 5, con proveedor de configuración raíz y los algoritmos de §8.2 |
| Iconos | Los del propio sistema |
| Gráficas | La librería hermana del sistema (§12) |
| Tipografía | Public Sans Variable · DM Mono, autoalojadas |

### Qué construir primero

1. **El proveedor de configuración con las semillas de §8.1 y los dos temas.** Sin esto no hay nada
   que juzgar, y todo lo que se construya antes habrá que retocarlo.
2. **La línea de suministro y el contador con umbral.** Son lo que este sistema no trae y lo que
   decide si la consola sirve.
3. **El panel de tabla** con desplazamiento virtual y sus cinco estados. Seis pantallas lo usan.
4. **La flota**, donde se ven las cuatro situaciones a la vez y donde el diseño falla primero si va
   a fallar.
5. El resto, que a partir de ahí es composición.

### Cuándo está bien

- Las cuatro situaciones de un producto se distinguen en una lista, sin abrir nada.
- Un pendiente atascado se ve distinto de uno recién creado.
- Cien mil filas se recorren sin salto.
- Un preaviso y su tiempo restante se leen sin entrar al detalle; uno caducado también.
- La edad del dato está donde la cadencia del backend la hace relevante.
- Una sola acción primaria por pantalla.
- Ningún control lleva color semántico y ninguna medida lleva color primario.
- Encender el canal en vivo no obliga a rehacer ninguna pantalla; apagarlo no rompe ninguna.
- Todo lo anterior, en tema claro y en tema oscuro.
- **Y la prueba final**: una captura sin logotipo no se lee como «otro panel montado con este
  sistema». Si se lee así, revisa las semillas de §8.1 — es ahí donde se pierde.
