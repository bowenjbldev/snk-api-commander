# Commander — diseño de la consola

Especificación de diseño de **Commander**, la consola de operación de una plataforma SaaS
multi-tenant. Las decisiones visuales están tomadas; este documento las fija con sus valores y su
porqué, y describe qué construir.

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

El **cliente** es el subdominio. El **producto** es el segmento de path. Cada par
`(cliente, producto)` provisionado tiene **su propia base de datos**; no hay columna de tenant en
ninguna parte. Un middleware conmuta la conexión por petición.

Lo que la consola **no** hace, y por tanto no aparece en ella:

- no genera ni clona código de producto: el código llega por git, fuera de aquí;
- no despliega contenedores ni gestiona infraestructura;
- no toca DNS, certificados ni copias de seguridad;
- no cobra: sin pasarela, sin domiciliación, sin conciliación. El libro de facturas es manual;
- no tiene autoservicio: nadie se da de alta solo.

**Un operador, uso diario, conocimiento experto.** No hay equipos, invitaciones ni onboarding. Es
una herramienta profesional, no un producto que deba explicarse.

---

## 2. El dominio

### Cliente

Cuenta comercial. `slug` único —y es el subdominio DNS real, así que es identidad técnica además
de comercial—, `name`, `company`, `email`, `phone`, `notes`, `status` (`active` | `inactive`), y
bloque de facturación: email de facturación, identificador fiscal, dirección en dos líneas,
ciudad, código postal, país (ISO-2) y plazo de pago en días.

### Producto — la entidad central

Una sola tabla mezcla **catálogo** y **ciclo de vida**. La misma fila adopta cuatro situaciones, y
distinguirlas **en una lista, sin abrir nada** es el trabajo principal de la consola:

| Situación | ¿Cliente? | `provisioned_at` | ¿Sirve tráfico? | Qué es |
|---|---|---|---|---|
| Catálogo | no | null | no | Existe en disco y se puede vender. Nadie lo usa. |
| Alta pendiente | sí | null | **no** | Comprado; el trabajo de fondo aún no ha creado su base de datos. |
| Instancia viva | sí | fecha | **sí** | En producción. |
| Dada de baja | no | null | no | Estuvo viva, se destruyó. Conserva su histórico de facturación. |

**El mismo nombre de producto se repite entre clientes.** Diez clientes pueden tener diez
instancias de «Kilkana». El nombre no identifica; el identificador numérico sí.

Campos: identidad (`name`, `slug`), catálogo (`label`, `description`, `listing_status`), estado
operativo (`status`, `reason`, `message`, `grace_until`, `changed_at`), indicadores
(`requests_24h`, `quota_health`, `last_seen`) y la frontera de vida (`provisioned_at`). Las
credenciales de base de datos existen y **nunca se muestran**.

La URL pública se compone: host desde el slug del cliente, path desde el slug del producto.

### Plan, suscripción, factura

- **Plan**: nombre, slug, precio mensual, límites de cuota por defecto, activo o no. Ligado a un
  tipo de producto.
- **Suscripción**: historial de términos. El vigente es el que no tiene fecha de fin. Guarda
  **instantánea** de nombre y precio, para que cambiar el catálogo no reescriba el pasado.
- **Factura**: número único, cliente, instancia, importe, emisión, vencimiento, estado
  `paid` | `pending` | `overdue`.

### Cuota

Una fila por métrica e instancia: clave, `limit_value` (**null = ilimitado**), `current_value`,
`measured_at`, `exceeded_at` y `on_exceed` ∈ `none` | `warn` | `suspend`.

Cuatro métricas: `requests_day`, `disk_db_mb`, `disk_storage_mb`, `users_total`.

### Registro, agregados, actividad, preferencias, código

- **Registro de peticiones**: instante, método, ruta, código, duración, usuario o token, IP, bytes
  de entrada y salida, memoria. Incluye las rechazadas por corte y por límite de tasa.
- **Agregados de uso**: cubos por hora y por día, por ruta, método y clase de código.
- **Eventos de actividad**: bitácora de cambios manuales y automáticos.
- **Preferencias**: fila única del operador; perfil, duración por defecto del preaviso
  (`default_grace_hours`), avisos y tema.
- **Código**: origen del repositorio con su cursor de sincronización, commits acotados a la ruta
  del producto, y agregado diario.

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
- **`grace`**: sigue sirviendo, con cabeceras de aviso, y **tiene fecha límite** —explícita o
  calculada con la duración por defecto de las preferencias.
- **`suspended`**: corte. Las peticiones del cliente reciben `402` con motivo interno y mensaje
  redactado para su público.

**El cuarto estado, el que no está en los datos.** Cuando la fecha límite del preaviso vence, el
backend trata la instancia como cortada aunque la columna siga diciendo `grace`. Ningún proceso
reescribe ese campo. Existe por tanto un **preaviso caducado** que la consola deduce comparando la
fecha límite con el reloj — y que significa que ese cliente ya no está servido. Por eso la cuenta
atrás no es decoración: es la información que decide si alguien tiene servicio.

### Alta de una instancia

El operador elige un plan existente, un cliente (nuevo o existente) y las credenciales del
administrador que vivirá dentro de la instancia. La petición se valida en caliente —plan activo,
cliente activo, slug válido para DNS, contraseña de doce caracteres o más con letras y dígitos— y
responde **`202`**: aceptada, no completada.

Después, en segundo plano, se crea la base de datos y su usuario, se migra, se siembra y se crea
el administrador. Al terminar, la fila pasa a viva.

**El hueco real**: no existen estados `provisioning`, `failed` ni `deprovisioning`. Si el encolado
falla, la fila se queda pendiente **para siempre** y nada la distingue de una recién creada. La
única señal disponible es **la antigüedad del pendiente**: dos minutos es normal, dos días es una
avería. La consola lo dice; no inventa estados que la API no tiene.

### Baja

Destruye base de datos, usuario, ficheros subidos, registros y cuotas. Irreversible. Corta el
tráfico en el acto y limpia en segundo plano. Conserva la fila, los metadatos de catálogo y **todo
el histórico de facturación**.

### Cambio de plan

Exige instancia viva y plan activo del mismo tipo de producto. Cierra el término vigente y abre
otro con instantánea de nombre y precio, y reajusta las cuotas. **Sin prorrateo**: el término
nuevo nace con la facturación en `pending`.

### Lectura de contadores y disparo

Un proceso mide cada seis horas y actúa solo:

| Situación | Política | Consecuencia |
|---|---|---|
| Por debajo del límite | cualquiera | Limpia la marca de exceso. Restablece sola **solo** si esa métrica causó el corte y ninguna otra sigue excedida. |
| Excedida | `none` | Lo registra. |
| Excedida | `warn` | Deja un aviso. |
| Excedida, estando activa | `suspend` | Abre preaviso, motivo `quota_exceeded:<métrica>`. |
| Excedida, preaviso vencido | `suspend` | Corta. |

**Un corte manual, o por impago, no se cura bajando una cuota.** El estado y su causa son cosas
distintas.

### Facturas

- Marcar vencida abre una decisión inmediata: **dejar correr**, **abrir preaviso** o **cortar ya**.
- Marcar pagada puede ofrecer **restablecer**, si ese impago causó el corte.
- Cobro y suministro se leen juntos pero son campos distintos, y no deben confundirse
  visualmente.

---

## 4. API y permisos

Todo cuelga de `/api/v1`. Todo salvo el acceso exige token **Bearer**; cualquier `401` cierra la
sesión.

```
POST   auth/login                        (con límite de intentos)
GET    auth/me
POST   auth/logout

GET|POST|PATCH|DELETE  users · clients · plans · subscriptions · invoices
GET                    quotas · quotas/{id}
PATCH                  quotas/{id}
GET                    activity · activity/{id}

POST   provisioning

GET    packages                          (sincroniza el catálogo desde disco al listar)
GET    packages/{id}
PATCH  packages/{id}
DELETE packages/{id}
POST   packages/{id}/suspend · grace · resume · change-plan
GET    packages/{id}/usage               (?period=hour|day&from&to)
GET    packages/{id}/logs                (?limit&since&status_class)
GET    packages/{id}/insights            (?days)

GET    usage/platform                    (?period&hours)
GET    logs                              (?package&limit&since&status_class&method)
GET    commits                           (?limit)
GET    code-frequency                    (?days)

GET    settings
PUT    settings
```

**Las llamadas a una instancia usan siempre su identificador numérico, jamás su nombre.**

Carga inicial: nueve peticiones en paralelo traen clientes, productos, suscripciones, facturas,
cuotas, planes, actividad, commits y preferencias.

**Permisos** por recurso y acción, más **controlar productos** (preaviso, corte, restablecer) y
**provisionar productos**. La consola los usa para ocultar lo que no procede; **no es una frontera
de seguridad**, el backend revalida siempre. «Sin permiso» es un estado de componente más, junto a
cargando, vacío y error.

---

## 5. Frescura del dato

### Lo que llega por tareas programadas

| Cadencia | Qué se actualiza |
|---|---|
| cada 10 minutos | peticiones de las últimas 24 h, salud de contador, última actividad |
| cada hora | agregados de uso, poda de registros, sincronización de commits |
| cada 6 horas | lectura de contadores y aplicación de políticas |

La consola **no promete tiempo real por defecto**. Una salud de contador puede llevar seis horas
de retraso, y decir «ahora mismo» sería mentir. Lo único vivo es el paso del tiempo: cuentas atrás
y antigüedades.

### El canal en vivo

Se contempla una capa de eventos por WebSocket, autenticada con el mismo token del operador y con
un único destinatario. **La consola funciona entera sin ella y la absorbe sin tocar el diseño.**

Qué empujaría:

| Evento | Por qué importa |
|---|---|
| Alta terminada o fallida | Cierra el hueco del `202`: la instancia pasa a viva, o falla, sin recargar. |
| Cambio de suministro | La lectura de contadores actúa sola cada seis horas: puede cortar a un cliente sin que el operador toque nada. Enterarse seis horas tarde es el peor caso de la consola. |
| Baja terminada | La destrucción es asíncrona; hoy no se sabe cuándo acabó. |
| Factura vencida detectada | Enlaza cobro con suministro. |
| Peticiones nuevas | Caudal continuo, no evento suelto. Se trata aparte. |

Reglas que el diseño ya cumple, por eso encaja sin tocarlo:

- **No hay pantalla «en vivo» separada.** Los mismos componentes absorben el empuje.
- Al caer la conexión, la consola **degrada sola** a la cadencia de arriba y lo dice en la barra
  de estado. No se rompe, no vacía, no bloquea.
- **La edad del dato cambia de significado**: con canal activo, «hace 4 h» es un dato viejo de
  verdad. El mismo indicador sirve en los dos regímenes.
- Un empuje puede llegar mientras hay una mutación optimista en vuelo sobre la misma entidad:
  **gana el servidor**, y la fila parpadea una vez para acusar la corrección.
- **Nunca se mueve el suelo bajo el cursor**: lo que llega se acumula en el contador de la cabecera
  del panel, no se inyecta.
- El caudal de registros se pausa al desplazarse y se reanuda al volver arriba.
- Un corte automático o un alta fallida **no** son un aviso efímero: aterrizan en la bandeja de
  atención, que persiste.

---

## 6. Volumen

Esta consola vive de tablas. No son el adorno del panel: son el panel.

- El explorador de registros se prueba con **cien mil filas** y trescientos sesenta y cinco días.
- Una flota tiene decenas de instancias con su estado, su tráfico y su salud.
- El libro de facturas crece indefinidamente.

Filtrar, ordenar, buscar y paginar sobre eso es el caso normal.

---

## 7. La dirección: una mesa de operación de suministro

El dominio no necesita metáfora: **ya es** el de una compañía de suministro.

| Commander | Suministro |
|---|---|
| Instancia provisionada | Acometida dada de alta |
| `active` | Conectado |
| Cuota y su lectura cada 6 h | Contador y su lectura periódica |
| `on_exceed: suspend` | Umbral de disparo del limitador |
| `grace` con fecha límite | Preaviso antes del corte |
| `suspended` → `402` | Corte |
| `resume` | Reenganche |
| Plan | Tarifa |
| Alta asíncrona (`202`) | Alta pendiente de brigada |

De ahí salen los instrumentos, el vocabulario y el elemento firma. La consola **no se disfraza** de
cuadro eléctrico: hereda su lógica, no su textura.

---

## 8. Sistema visual

### 8.1 Color: la cromaticidad es una medida

**La decisión que gobierna todo lo demás.** El cromo de la interfaz —raíl, paneles, tablas,
formularios, botones, tipografía— es **acromático**. Los únicos píxeles con color pertenecen a la
medición y al suministro: la línea de estado, el relleno de los contadores, las series de gráfica,
las marcas de clase HTTP y la cuenta atrás.

Consecuencia deliberada: **la acción primaria no lleva color**. Es inversión pura de contraste,
tinta sobre papel o papel sobre tinta. Un botón no es una medida, así que no gasta color. Sobre
cien mil filas, el ojo va solo a lo que significa algo.

**Neutros — tema oscuro**

| Token | Valor | Uso |
|---|---|---|
| `--bg` | `oklch(0.21 0.018 255)` | Fondo de la aplicación |
| `--panel` | `oklch(0.25 0.016 255)` | Superficie de panel |
| `--panel-raised` | `oklch(0.29 0.015 255)` | Cabeceras, filas fijas, capas |
| `--line` | `oklch(0.34 0.014 255)` | Divisiones |
| `--line-strong` | `oklch(0.42 0.013 255)` | Bordes de control |
| `--text` | `oklch(0.96 0.004 255)` | Texto principal |
| `--text-dim` | `oklch(0.72 0.010 255)` | Secundario, etiquetas |
| `--text-faint` | `oklch(0.56 0.012 255)` | Terciario, marcas de tiempo |
| `--ink` | `oklch(0.96 0.004 255)` | Relleno de acción primaria |
| `--ink-on` | `oklch(0.21 0.018 255)` | Texto sobre acción primaria |

Pizarra azulada, no casi-negro. La consola debe leer a equipamiento, no a terminal.

**Neutros — tema claro**

| Token | Valor | Uso |
|---|---|---|
| `--bg` | `oklch(0.97 0.006 255)` | Fondo de la aplicación |
| `--panel` | `oklch(1 0 0)` | Superficie de panel |
| `--panel-raised` | `oklch(0.985 0.004 255)` | Cabeceras, filas fijas, capas |
| `--line` | `oklch(0.91 0.008 255)` | Divisiones |
| `--line-strong` | `oklch(0.83 0.010 255)` | Bordes de control |
| `--text` | `oklch(0.24 0.016 255)` | Texto principal |
| `--text-dim` | `oklch(0.47 0.014 255)` | Secundario |
| `--text-faint` | `oklch(0.62 0.012 255)` | Terciario |
| `--ink` | `oklch(0.24 0.016 255)` | Relleno de acción primaria |
| `--ink-on` | `oklch(1 0 0)` | Texto sobre acción primaria |

Gris frío de laboratorio, no papel crema.

**Semántica del suministro**

| Estado | Oscuro | Claro | Etiqueta |
|---|---|---|---|
| Conectado | `oklch(0.74 0.17 152)` | `oklch(0.55 0.16 152)` | Live |
| Preaviso | `oklch(0.80 0.16 78)` | `oklch(0.62 0.15 68)` | Notice · con cuenta atrás |
| Preaviso vencido | `oklch(0.72 0.19 42)` | `oklch(0.56 0.18 40)` | Notice expired |
| Cortado | `oklch(0.68 0.21 25)` | `oklch(0.54 0.21 27)` | Cut off |
| Pendiente | `oklch(0.66 0.11 255)` | `oklch(0.55 0.12 255)` | Provisioning |
| Pendiente atascado | `oklch(0.72 0.19 42)` | `oklch(0.56 0.18 40)` | Stalled · con antigüedad |
| Catálogo | `--text-faint` | `--text-faint` | Not provisioned |

**Cobro**: pagada usa el verde de conectado; pendiente el ámbar de preaviso; vencida el rojo de
corte. Mismo triángulo cromático, contexto distinto — y por eso nunca comparten columna con el
suministro sin etiqueta que los separe.

**Contador**: por debajo del 80 % usa `--text-dim` (no verde: estar bien no es una señal); del 80
al 100 % el ámbar de preaviso; por encima el rojo de corte. El umbral de disparo se marca en el
propio instrumento.

**Clases HTTP**: `2xx` neutro tenue, `3xx` azul `oklch(0.70 0.12 255)`, `4xx` ámbar de preaviso,
`5xx` rojo de corte. Los métodos usan la misma escala neutra salvo los destructivos.

**Series de gráfica** — cinco tonos equiespaciados en croma constante, legibles en ambos temas y
distinguibles en escala de grises por luminancia:

```
oklch(0.70 0.14 255)   azul
oklch(0.74 0.14 190)   cian
oklch(0.76 0.14 145)   verde
oklch(0.78 0.14 85)    ámbar
oklch(0.72 0.16 20)    rojo
```

Cuando una serie representa un estado (errores, cortes), **usa el color semántico**, no el de la
paleta de series.

**Foco**: `oklch(0.78 0.17 220)`, anillo de 2 px con 2 px de separación. Es el único color no
semántico de la consola, y se elige precisamente porque no aparece en ninguna escala de arriba:
nunca puede confundirse con una lectura.

### 8.2 Tipografía

**Archivo Variable** para la interfaz. Se elige por su **eje de anchura**: en una consola, poder
comprimir un encabezado de columna para ganar cuatro caracteres, o expandir la cifra que manda en
la pantalla, es una herramienta de maquetación, no un capricho.

| Rol | Familia | Tamaño / interlineado | Peso | Anchura |
|---|---|---|---|---|
| Cifra protagonista | Archivo | 32 / 34 | 500 | 112 |
| Título de pantalla | Archivo | 20 / 26 | 500 | 100 |
| Título de panel | Archivo | 13 / 18 | 600 | 100 |
| Encabezado de columna | Archivo | 11 / 14 · +0.06em · versalitas | 600 | 87 |
| Cuerpo | Archivo | 13 / 20 | 400 | 100 |
| Etiqueta de formulario | Archivo | 12 / 16 | 500 | 100 |
| Secundario | Archivo | 12 / 16 | 400 | 100 |

**Commit Mono** para **toda lectura**: identificadores, slugs, rutas, URLs, importes, cifras,
marcas de tiempo, cuentas atrás, SHA, IP, códigos de estado. Cifras tabulares siempre; sin
ligaduras.

| Rol | Tamaño / interlineado | Peso |
|---|---|---|
| Dato en celda | 12 / 16 | 400 |
| Dato destacado | 13 / 18 | 500 |
| Cuenta atrás | 13 / 18 | 500 · cifras tabulares |
| Dato menor | 11 / 14 | 400 |

Ambas familias son OFL y se autoalojan. La regla que hace coherente la consola: **si es un dato
que el sistema generó, va en mono; si es lenguaje que escribimos nosotros, va en Archivo.**

### 8.3 Forma y superficie

- Radio **6 px** en paneles, campos, botones y capas.
- Radio **0** en filas de tabla: las filas son un continuo, no fichas apiladas.
- Radio **completo** solo en el punto de estado y en el contador de novedades.
- Sin radios intermedios sueltos.

La jerarquía de planos se resuelve con **relleno**, subiendo un escalón de luminancia por capa
(`--bg` → `--panel` → `--panel-raised`). Las divisiones de 1 px separan **dentro** de un plano; no
se usan para simular uno. Las sombras se reservan para lo que flota de verdad: diálogos, menús,
paleta de comandos.

### 8.4 Espacio y densidad

Escala de paso 4: `4 8 12 16 24 32 48 64`. Sin valores intermedios.

| Elemento | Alto |
|---|---|
| Fila de tabla | 32 |
| Fila compacta (registros, caudal) | 28 |
| Encabezado de columna | 28 |
| Control (campo, botón, selector) | 30 |
| Cabecera de panel | 40 |
| Cabecera de aplicación | 48 |
| Barra de estado | 26 |

Relleno interior de celda: 12 horizontal, 0 vertical (lo fija el alto de fila).

**Una tabla no se envuelve en una tarjeta con relleno.** Va a sangre dentro de su panel: el ancho
es el recurso escaso de esta consola y treinta y dos píxeles por lado son una columna entera.

---

## 9. El elemento firma: la línea de suministro

Cada fila, panel y ficha que representa una instancia lleva a su izquierda una **línea vertical de
3 px** que codifica el suministro por **color y por forma**:

```
┃━━━━━━  Live              línea llena, verde
┃▔▔▔▔    Notice            segmento que MENGUA hasta el corte
┆        Cut off           línea hueca, roja
╎        Provisioning      punteada, azul
╎        Stalled           punteada, roja
         Not provisioned   sin línea: no hay suministro que representar
```

En una tabla de flota:

```
┃  the-beacon      Kilkana    Pro      12.4k   ▓▓▓▓▓▓▓░░  62%   2m ago
┃▔ el-mundo        Kilkana    Scale    88.1k   ▓▓▓▓▓▓▓▓▓  103%  1m ago   2h 14m
┆  el-confidencial Kilkana    Pro          0   ▓▓░░░░░░░  18%   3d ago
╎  la-marea        Kilkana    Starter      —        —            —       18m
   Kilkana         —          —            —        —            —
```

Por qué esto y no una etiqueta de estado en su columna:

1. **No consume ancho** en una tabla que ya va justa.
2. La salud de toda la flota se lee **con la visión periférica**, sin recorrer texto.
3. El preaviso deja de ser un número que hay que leer: **se ve encogerse**. Es el único elemento
   con vida propia de la consola, y es justo el que decide si un cliente sigue servido.
4. Resuelve el estado que no existe en los datos: cuando el segmento llega a cero, el preaviso ha
   caducado y esa instancia ya no sirve — el ojo lo capta antes que cualquier etiqueta.
5. Distingue las cuatro situaciones de un producto **por forma antes que por color**, así que
   funciona con visión de color reducida y en escala de grises.

La etiqueta de texto sigue existiendo donde hace falta —cabecera de detalle, bandeja de atención,
lector de pantalla—. La línea es la capa que se lee primero, no la única.

**Accesibilidad**: la línea nunca es la única fuente. Cada fila lleva su estado en texto accesible;
la forma (llena, menguante, hueca, punteada) es redundante con el color a propósito.

---

## 10. Instrumentos

### El contador con umbral de disparo

Una cuota no es una barra de progreso. Es un contador con un límite **y** un punto en el que salta
el limitador. Ver que algo se acerca al techo importa más que ver que ya lo pasó.

```
requests_day
▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓░░░░░░│░░░   82,400 / 100,000        suspend
                       └ 80%  umbral de aviso
```

- Relleno neutro por debajo del 80 %; ámbar entre 80 y 100; rojo por encima, con el exceso saliendo
  del carril.
- **Marca del umbral** siempre visible.
- La política (`none` / `warn` / `suspend`) va en el propio instrumento: un contador con `suspend`
  no significa lo mismo que uno con `none`, y sin ese dato la lectura es incompleta.
- Límite vacío = ilimitado: carril tenue, sin relleno, sin umbral.

### Catálogo

| Instrumento | Qué hace |
|---|---|
| Línea de suministro | Estado por color y forma; el preaviso mengua. |
| Contador | Lectura, límite, umbral de disparo y política. |
| Lectura | Cifra en mono con unidad y etiqueta; variante protagonista para las cabeceras de pantalla. |
| Delta | Variación frente a la ventana anterior. **No se muestra si no hay ventana anterior**; no se inventa. |
| Serie | Tendencia por fila, en SVG, sin instanciar una gráfica por fila. |
| Cuenta atrás | Tiempo restante en mono, con el color del preaviso; a cero, salta a «expired». |
| Edad del dato | «hace X» con el instante absoluto al pasar el puntero. |
| Marca de método · Marca de clase | Chips monoespaciados de ancho fijo, para que las columnas cuadren. |
| Identificador copiable | Mono con copia al portapapeles; los secretos no se muestran. |
| Panel | Superficie con cabecera de 40, contenido a sangre y pie informativo opcional. |
| Tabla | Cabecera fija, virtualización, orden, filtros, selección, acciones por lote, esqueleto y vacío. |
| Estado vacío | Una frase de qué falta y la acción que lo resuelve. Nunca una ilustración. |
| Estado sin permiso | El control se muestra inerte con el motivo, no desaparece: su ausencia sería más confusa. |

Todos los instrumentos declaran cuatro estados además del normal: **cargando, vacío, error y sin
permiso**. Los esqueletos ocupan exactamente el alto del contenido que sustituyen.

---

## 11. Estructura

```
┌────┬──────────────────────────────────────────────────────────┐
│    │  Fleet                                     [Provision]   │ 48
│ ▤  ├──────────────────────────────────────────────────────────┤
│ ◆  │ ┌──────────────────────────────────────────────────────┐ │
│ ▦  │ │ Live 24   Notice 2   Cut off 1   Pending 1   Catalog 3│ │
│ ▧  │ └──────────────────────────────────────────────────────┘ │
│ ▨  │ ┌──────────────────────────────────────────────────────┐ │
│ ▩  │ │ Instances                          [search] [filters]│ │ 40
│ ⚙  │ ├──────────────────────────────────────────────────────┤ │
│    │ │┃ the-beacon    Kilkana   Pro    12.4k  ▓▓▓░ 62%  2m  │ │ 32
│    │ │┃▔el-mundo      Kilkana   Scale  88.1k  ▓▓▓▓ 103% 1m  │ │
│    │ │┆ el-confid…    Kilkana   Pro        0  ▓░░░ 18%  3d  │ │
│    │ └──────────────────────────────────────────────────────┘ │
├────┴──────────────────────────────────────────────────────────┤
│ ● Live   Data 4m old   3 need attention              ⌘K       │ 26
└───────────────────────────────────────────────────────────────┘
```

- **Raíl de 56 px** a la izquierda: iconos con etiqueta al pasar el puntero. Es el mapa de la
  consola, no el vehículo principal.
- **Paleta de comandos (⌘K)** como navegación real: salta a cualquier instancia, cliente o factura,
  y ejecuta las acciones de control. Un operador que usa esto a diario rentabiliza el teclado; el
  raíl existe para orientarse, no para llegar.
- **Barra de estado inferior permanente**: estado de conexión, edad del dato más antiguo, cuántas
  cosas piden atención, pista de atajo. Ahí viven los indicadores que no deben competir con el
  contenido, y ahí aterriza el estado del canal en vivo.
- **Cabecera de 48 px**: título, ruta de migas en las pantallas de detalle y **una sola acción
  primaria** a la derecha.

**Plantilla de listado**: cabecera → tira de lecturas → panel de tabla con búsqueda y filtros en su
propia cabecera.

**Plantilla de detalle**: cabecera con identidad, línea de suministro y menú de control → dos
columnas, la principal desplazable y un **raíl de instrumentos pegajoso** a la derecha con las
lecturas y los mandos siempre visibles.

---

## 12. Las pantallas

Las columnas de cada tabla y sus filtros son **lógica de negocio**: se conservan tal cual.

### Panorama

Responde «¿tengo que actuar?» en tres segundos. Selector de ventana (48 h, semana, mes, trimestre,
año) que gobierna toda la pantalla.

Lecturas: instancias vivas, en preaviso, cortadas; peticiones; proporción de error; ingreso mensual
recurrente con su delta.

Gráficas: peticiones por instancia (barras apiladas), proporción de error `4xx`/`5xx` (área
apilada), frecuencia de código (barras divergentes).

Paneles: actividad de código reciente y **bandeja de atención** — preavisos que corren, pendientes
atascados, facturas vencidas y contadores por encima del umbral, cada uno con su acción directa.

### Cartera de clientes

Lecturas: clientes, activos, instancias, ingreso recurrente, en riesgo.

**Columnas**: Cliente (con estado) · MRR · Tráfico 24 h (serie) · Salud de contador · Última
actividad.
**Filtros**: estado · cobro · salud · país.
**Acción**: dar de alta un cliente.

### Ficha de cliente

Datos comerciales y de facturación editables en su sitio, con guardado que aparece solo cuando hay
cambios. Raíl derecho con sus instancias y sus facturas. Aviso persistente si algo suyo está en
apuros.

### Flota

La pantalla donde conviven las cuatro situaciones. Distinguirlas es su trabajo.

**Columnas**: Producto (con situación) · Cliente · Plan · Tráfico 24 h · Salud de contador · Última
actividad.
**Filtros**: situación (viva / pendiente / solo catálogo) · estado operativo · estado de listado.
**Acción**: provisionar instancia — plan, cliente nuevo o existente, credenciales del
administrador.

### Instancia

Cabecera con identidad, línea de suministro, URL pública copiable y menú de control: cambiar plan,
abrir preaviso, extender preaviso, restablecer, cortar.

- **Identidad**: metadatos de catálogo editables; raíl con cliente, suscripción, alta y última
  actividad, más los contadores en pequeño.
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

Cien mil filas virtualizadas. **Columnas**: Instante · Instancia · Método · Ruta · Código ·
Duración · IP. **Filtros**: instancia · método · clase de código. Solo lectura.

### Preferencias

Perfil del operador, duración por defecto del preaviso, avisos y tema.

### Entrada

Formulario de email y contraseña sobre fondo liso. Una pantalla, un error posible, sin credenciales
de ejemplo a la vista.

---

## 13. Movimiento

Tres, y ninguno decorativo:

1. **Conexión y corte** — 400 ms, curva de salida. La línea de suministro transiciona de color y de
   forma cuando cambia el estado. Es el único momento con gracia de la consola, y es semántico: te
   enteras de que algo cambió sin leer.
2. **Llegada acumulada** — el contador de novedades de la cabecera del panel incrementa con un
   pulso de 120 ms. Las filas no se inyectan.
3. **Entrada de capa** — 150 ms, opacidad y 4 px de desplazamiento. Diálogos, menús, paleta.

La cuenta atrás actualiza a 1 Hz desde **un solo reloj compartido**; las tablas no se repintan cada
segundo.

Con movimiento reducido: la línea cambia sin transición, el contador incrementa sin pulso, las
capas aparecen sin desplazamiento. Nada se pierde, solo la transición.

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

Los errores dicen qué pasó y qué hacer, en voz de la interfaz, sin disculparse:

> *This plan belongs to a different product. Pick a plan for Kilkana.*
> *Provisioning was accepted 3 days ago and never completed. Check the queue worker.*

Una pantalla vacía es una invitación a actuar:

> *No instances yet. Provision one to start serving traffic.*

Nombres del lado del operador, no del sistema: **supply**, **meter**, **notice period**, **tariff**,
no *status flag*, *quota row*, *grace column*.

---

## 15. Accesibilidad y rendimiento

- **Contraste AA verificado** en ambos temas, incluidos los tonos semánticos sobre su fondo.
- El color **nunca** es la única señal: el suministro va también por forma de línea y por texto.
- Teclado completo: orden lógico, foco visible con el anillo cian, foco gestionado al abrir y
  cerrar capas, escape que cierra, atajos anunciados.
- Elemento actual en la navegación, dirección de ordenación en las cabeceras, región viva para los
  avisos y para el contador de novedades.
- Las tablas grandes virtualizan; las tendencias por fila son SVG, no gráficas.
- La consola **no bloquea hasta tener los nueve recursos**: el armazón entra primero y cada panel
  resuelve el suyo con su propio esqueleto.
- Sin saltos de maquetación: el esqueleto ocupa el alto exacto del contenido.
- Las mutaciones se aplican al instante y se reconcilian con la respuesta; si falla, aviso claro y
  vuelta atrás.
- Escritorio primero, con degradación razonable. Nadie corta el suministro de un cliente desde el
  autobús.

---

## 16. Stack

| Pieza | Elección | Por qué |
|---|---|---|
| Base | React 19 · TypeScript · Vite | — |
| Comportamiento y accesibilidad | Radix | Aporta foco, teclado, ARIA y capas. **No aporta ni un color ni un radio**: el aspecto es entero de este documento. |
| Estilo | Tailwind v4, tokens de §8 en variables CSS | Los temas conmutan cambiando el juego de variables. |
| Tablas | TanStack Table + virtualización | Las cien mil filas son un requisito, no un extremo. |
| Gráficas | ECharts | No hereda tokens: sus opciones se construyen con la paleta de series de §8.1 y se reconstruyen al cambiar de tema. |

### Qué construir primero

1. **Los tokens y las dos familias tipográficas.** Sin esto no hay nada que juzgar.
2. **La línea de suministro y el contador con umbral.** Son los dos instrumentos de los que depende
   la consola entera; si no funcionan, el resto da igual.
3. **El panel de tabla virtualizado**, con sus cinco estados. Seis pantallas lo usan.
4. **La flota**, que es donde se ven las cuatro situaciones a la vez y el primer sitio donde el
   diseño puede fallar.
5. El resto de pantallas, que a partir de ahí son composición.

### Cuándo está bien

- Las cuatro situaciones de un producto se distinguen en una lista, sin abrir nada.
- Un pendiente atascado se ve distinto de uno recién creado.
- Cien mil filas se recorren sin salto.
- Un preaviso y su tiempo restante se leen sin entrar al detalle; uno caducado también.
- La edad del dato está donde la cadencia del backend la hace relevante.
- Una sola acción primaria por pantalla.
- Encender el canal en vivo no obliga a rehacer ninguna pantalla; apagarlo no rompe ninguna.
- Todo lo anterior, en tema claro y en tema oscuro.
