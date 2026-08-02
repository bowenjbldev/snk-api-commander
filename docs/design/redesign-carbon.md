# Brief: diseñar Commander con IBM Carbon Design System

> Prompt autocontenido. Todo lo que necesitas está en este documento. No hay repositorio que
> consultar, no hay implementación previa que respetar, no preguntes por ella.

---

## 1. El encargo

Diseña desde cero **Commander**: la consola web de operación de una plataforma SaaS
multi-tenant. Un solo operador la usa para dar de alta clientes, provisionar instancias de
producto, vigilar consumo, aplicar cuotas, cortar el servicio a quien no paga y volver a
levantarlo.

Campo libre en todo lo visual, estructural y de interacción. Lo único inamovible es el backend:
sus entidades, sus endpoints, sus máquinas de estado, sus permisos y la cadencia de sus tareas
programadas — todo ello descrito abajo.

El sistema de diseño está fijado: **IBM Carbon Design System v11**. La sección 12 detalla qué
significa eso exactamente, porque el fracaso típico de este encargo es instalar Carbon y luego
reescribirlo entero en CSS propio.

---

## 2. El dominio en una página

Una única aplicación Laravel sirve a todos los clientes de la plataforma. La identidad de una
petición sale de la URL:

```
{cliente}.{dominio}/api/v1/{producto}
```

El **cliente** es el subdominio. El **producto** es el segmento de path. Cada par
`(cliente, producto)` que ha sido provisionado tiene su **propia base de datos**; no hay columna
`tenant_id` en ninguna parte. Un middleware conmuta la conexión por petición.

Commander es el **control plane** de esa plataforma, y se sirve en su propio subdominio. Lo que
**no** hace, y por tanto no debes diseñar:

- no genera ni clona código de producto (el código llega por git, fuera de la consola);
- no despliega contenedores ni gestiona infraestructura;
- no toca DNS, certificados TLS ni copias de seguridad — eso son tareas de sistemas, fuera;
- no cobra: no hay pasarela, ni domiciliación, ni conciliación bancaria. El registro de facturas
  es un libro manual que el operador lleva a mano;
- no tiene autoservicio público: nadie se da de alta solo, el operador crea todo.

**Un único operador** usa esta consola. No hay equipos, ni invitaciones, ni onboarding, ni
notificaciones a terceros. Diséñala como una herramienta profesional de uso diario para una
persona que ya sabe lo que hace.

---

## 3. Las entidades y los campos que la interfaz muestra

Trece tablas viven en la base de datos de la consola. Resumen por responsabilidad, con detalle de
campo solo donde condiciona el diseño.

### Cliente

Cuenta comercial. Campos: `slug` (único, y es el subdominio DNS real, así que es identidad
técnica además de comercial), `name`, `company`, `email`, `phone`, `notes`, `status`
(`active` | `inactive`), y un bloque de facturación: email de facturación, identificador fiscal,
dirección en dos líneas, ciudad, código postal, país (ISO-2) y plazo de pago en días.

### Producto — la entidad central, y la más traicionera

Una sola tabla mezcla **catálogo** y **ciclo de vida**. La misma fila puede estar en cuatro
situaciones muy distintas, y el operador debe distinguirlas **de un vistazo, en una lista**:

| Situación | ¿Tiene cliente? | `provisioned_at` | ¿Enruta tráfico? | Qué significa |
|---|---|---|---|---|
| Catálogo | no | null | no | El producto existe en disco y se puede vender. Nadie lo usa. |
| Alta pendiente | sí | null | **no** | Alguien lo compró; el worker aún no ha terminado de crear su base de datos. |
| Instancia viva | sí | fecha | **sí** | En producción, sirviendo peticiones. |
| De-provisionada | no | null | no | Estuvo viva, se destruyó. Conserva su histórico de facturación. |

Nota crítica: **el mismo nombre de producto se repite entre clientes**. Diez clientes pueden tener
diez instancias del producto «Kilkana». El nombre no identifica nada; el identificador numérico sí.

Campos: identidad (`name`, `slug`), catálogo (`label`, `description`, `listing_status`), estado
operativo (`status`, `reason`, `message`, `grace_until`, `changed_at`), indicadores
(`requests_24h`, `quota_health`, `last_seen`) y la frontera de vida (`provisioned_at`). Las
credenciales de base de datos existen pero nunca se muestran.

La URL pública de una instancia se compone: el host sale del slug del cliente, el path sale del
slug del producto.

### Plan

Oferta comercial asociada a un tipo de producto: nombre, slug, precio mensual, límites de cuota
por defecto y si está activo. Varios clientes pueden estar en el mismo plan.

### Suscripción

Historial de términos contractuales. El término vigente es el que no tiene fecha de fin. Guarda
una **instantánea** del nombre y el precio del plan en el momento de contratar, para que cambiar
el catálogo no reescriba el pasado.

### Factura

Número único, cliente, instancia, importe, fecha de emisión, fecha de vencimiento y estado:
`paid` | `pending` | `overdue`.

### Cuota

Una fila por métrica y por instancia. Campos: clave de métrica, `limit_value` (**null significa
ilimitado**), `current_value`, `measured_at`, `exceeded_at` y `on_exceed` — la política, que puede
ser `none`, `warn` o `suspend`.

Las cuatro métricas que existen hoy: `requests_day`, `disk_db_mb`, `disk_storage_mb`,
`users_total`.

### Registro de peticiones

Una fila por petición atendida en cualquier instancia: instante, método HTTP, ruta, código de
estado, duración en milisegundos, usuario o token, IP, bytes de entrada y salida, memoria.
Incluye las peticiones rechazadas por corte de servicio y por límite de tasa.

### Agregados de uso

Cubos por hora y por día, desglosados por ruta, método y clase de código HTTP.

### Eventos de actividad

Bitácora de cambios manuales y automáticos, con tipo de evento y referencias opcionales a
instancia y factura.

### Preferencias

Fila única del operador: su perfil, la duración por defecto del periodo de gracia
(`default_grace_hours`) y sus preferencias de aviso y de tema.

### Actividad de código

Tres tablas alimentan la vista de desarrollo: origen del repositorio con su cursor de
sincronización, commits en crudo acotados a la ruta del producto, y un agregado diario por
producto.

---

## 4. Máquinas de estado y reglas de negocio

Esta sección es la que más condiciona el diseño. Léela dos veces.

### Estado de una instancia

```
active ──────► grace ──────► suspended
  │              │                │
  └──────────────┴────────────────┘
              (resume → active)
```

Transiciones válidas y ninguna más: `active → grace`, `active → suspended`, `grace → active`,
`grace → suspended`, `suspended → active`.

- **`active`**: sirve con normalidad.
- **`grace`**: sigue sirviendo, pero con cabeceras de aviso, y **tiene fecha límite**. La fecha se
  fija explícitamente o se calcula con la duración por defecto de las preferencias.
- **`suspended`**: interruptor de corte. Las peticiones del cliente reciben `402` con un motivo
  interno y un mensaje redactado para su público.

**El detalle que se olvida siempre**: cuando la fecha límite de una gracia vence, el backend la
trata como suspendida aunque la columna siga diciendo `grace`. Ningún proceso reescribe ese campo.
Es decir: existe un cuarto estado de facto —**gracia caducada**— que no aparece en los datos y
que la interfaz tiene que deducir comparando la fecha límite con el reloj. Una instancia en ese
estado ya no está sirviendo, y el operador debe verlo.

De ahí que la cuenta atrás de una gracia no sea decoración: es información operativa.

### Alta de una instancia (provisioning)

El operador elige un plan **ya existente**, un cliente (nuevo o existente) y las credenciales del
administrador que se creará dentro de la instancia. La petición se valida en caliente —plan
activo, cliente activo, slug válido para DNS, contraseña de al menos doce caracteres con letras y
dígitos— y responde **`202 Accepted`**: aceptada, no completada.

Después, en segundo plano, un trabajo crea la base de datos, su usuario, ejecuta migraciones,
siembra datos y crea el administrador. Cuando termina, la fila pasa a viva.

**El hueco que debes diseñar**: no existen estados `provisioning`, `failed` ni `deprovisioning`.
Si el encolado del trabajo falla, la fila se queda pendiente **para siempre**, y nada en los datos
distingue «se está creando ahora mismo» de «lleva tres días muerta».

No inventes estados que la API no da. Lo que sí tienes es el instante en que se creó la fila: la
antigüedad de un pendiente es la única señal disponible. Un pendiente de dos minutos es normal;
uno de dos días es una avería. Que la interfaz lo diga.

### Baja de una instancia (de-provisioning)

Destruye la base de datos, su usuario, los ficheros subidos, los registros y las cuotas. Es
irreversible. Corta el tráfico en el acto y luego limpia en segundo plano. Conserva la fila, los
metadatos de catálogo y **todo el histórico de facturación**.

Merece una confirmación de las que cuestan trabajo escribir.

### Cambio de plan

Exige instancia viva y plan activo del mismo tipo de producto. Cierra el término vigente y abre
otro con instantánea de nombre y precio, y reajusta las cuotas. **No hay prorrateo**: el término
nuevo nace con la facturación en `pending`.

### Medición y aplicación de cuotas

Un proceso mide cada seis horas y actúa solo:

| Situación | Política | Consecuencia |
|---|---|---|
| Por debajo del límite | cualquiera | Limpia la marca de exceso. Reanuda sola **únicamente** si esa métrica causó el estado y ninguna otra sigue excedida. |
| Excedida | `none` | Solo lo registra. |
| Excedida | `warn` | Deja un aviso. |
| Excedida, estando activa | `suspend` | La mete en gracia, con el motivo `quota_exceeded:<métrica>`. |
| Excedida, con la gracia ya vencida | `suspend` | La suspende. |

Regla que sorprende y hay que reflejar: **una suspensión manual, o por impago, no se cura bajando
una cuota**. El estado y su causa son cosas distintas.

### Facturas

- Marcar una factura como vencida abre una decisión inmediata: **dejarla correr**, **meterla en
  gracia** o **suspender ya**.
- Marcar como pagada puede ofrecer **reanudar**, si fue ese impago el que causó la suspensión.
- La situación de facturación y el estado operativo se leen juntos, pero son campos distintos y no
  deben confundirse visualmente.

---

## 5. Contrato de API

Todo cuelga de `/api/v1`. Todo salvo el acceso exige un token **Bearer**; cualquier `401` cierra
la sesión y devuelve a la pantalla de entrada.

```
POST   auth/login                        (con límite de intentos)
GET    auth/me
POST   auth/logout

GET|POST|PATCH|DELETE  users
GET|POST|PATCH|DELETE  clients
GET|POST|PATCH|DELETE  plans
GET|POST|PATCH|DELETE  subscriptions
GET|POST|PATCH|DELETE  invoices
GET                    quotas · quotas/{id}
PATCH                  quotas/{id}
GET                    activity · activity/{id}

POST   provisioning

GET    packages                          (sincroniza el catálogo desde disco al listar)
GET    packages/{id}
PATCH  packages/{id}
DELETE packages/{id}
POST   packages/{id}/suspend
POST   packages/{id}/grace
POST   packages/{id}/resume
POST   packages/{id}/change-plan
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

**Regla dura**: las llamadas a una instancia usan siempre su **identificador numérico**, jamás su
nombre. El nombre se repite entre clientes; sólo el número identifica.

Carga inicial: nueve peticiones en paralelo traen clientes, productos, suscripciones, facturas,
cuotas, planes, actividad, commits y preferencias. Cómo se orqueste eso —bloqueo total, carga
progresiva, esqueletos por región— **es decisión tuya**, y una de las más visibles.

### Permisos

Por recurso (clientes, productos, suscripciones, facturas, cuotas, actividad, planes,
preferencias) y acción (listar, ver, crear, editar, borrar), más dos específicos:
**controlar productos** (suspender, gracia, reanudar) y **provisionar productos**.

La interfaz los usa para ocultar lo que no procede, pero **no es una frontera de seguridad**: el
backend revalida siempre. Diseña el estado «sin permiso» como un caso más, junto a cargando, vacío
y error.

---

## 6. Cuán fresco está cada dato

### 6.1 Hoy: todo llega por tareas programadas

Nada llega solo. El backend refresca por cron y la interfaz lee lo que haya:

| Cadencia | Qué se actualiza |
|---|---|
| cada 10 minutos | peticiones de las últimas 24 h, salud de cuota, última vez visto |
| cada hora | agregados de uso, poda de registros antiguos, sincronización de commits |
| cada 6 horas | medición de cuotas y aplicación de políticas |

Consecuencia de diseño: **la interfaz no puede prometer tiempo real por defecto**. Un indicador de
salud de cuota puede llevar seis horas de retraso, y decir «ahora mismo» sería mentir. Lo único
genuinamente vivo es el paso del tiempo: cuentas atrás y antigüedades.

Comunicar la edad del dato es parte del encargo, no un extra. Resuélvelo sin convertir cada
número en un párrafo.

### 6.2 Mañana: integración de tiempo real por WebSocket

Se contempla añadir una capa de eventos en vivo (canal WebSocket autenticado con el mismo token
del operador; en el ecosistema de este backend eso significa un servidor de difusión y un canal
privado, dado que **hay un único operador y por tanto un único destinatario**).

**El diseño debe preverla desde ahora.** No como una funcionalidad a maquetar, sino como una
restricción arquitectónica: la consola tiene que poder recibir empujes sin que su estructura
visual cambie. Si añadir tiempo real obligara a rediseñar pantallas, el diseño ha fallado.

Lo que ese canal empujaría, y por qué cada cosa importa:

| Evento | Por qué justifica el canal |
|---|---|
| **Alta terminada o fallida** | Es *el* caso que lo justifica. Hoy el alta responde `202` y la fila se queda pendiente sin que nada avise (sección 4). Un empuje cierra ese hueco: el operador ve la instancia pasar a viva, o fallar, sin recargar. |
| **Cambio de estado de instancia** | El proceso de cuotas actúa solo cada seis horas: puede meter en gracia o suspender **sin que el operador haya tocado nada**. Enterarse seis horas tarde de que un cliente está cortado es el peor caso de la consola. |
| **Baja terminada** | La destrucción es asíncrona; hoy no se sabe cuándo acabó. |
| **Factura vencida detectada** | Enlaza cobro con estado operativo (sección 4). |
| **Peticiones nuevas** | Seguimiento en vivo del explorador de registros y del panorama. Es un caudal continuo, no un evento suelto: trátalo aparte. |

Lo que el diseño debe resolver para que esa capa encaje:

- **Ninguna pantalla «en vivo» separada.** Los mismos componentes absorben el empuje. Un modo
  paralelo duplica el sistema.
- **Estado de la conexión**, visible pero sin protagonismo: conectada, reconectando, caída. Cuando
  cae, la consola **degrada sola** a la cadencia de la sección 6.1 y lo dice; no se rompe, no
  vacía la pantalla, no bloquea.
- **La edad del dato cambia de significado.** Con canal activo, «hace 4 h» pasa a ser un dato
  antiguo real y no la cadencia normal. El mismo indicador tiene que servir en los dos regímenes.
- **Un empuje puede llegar mientras hay una mutación optimista en vuelo** sobre la misma entidad
  (sección 9), o llegar desordenado. Define cuál gana y cómo se ve la reconciliación.
- **No mover el suelo bajo el cursor.** Si el operador está leyendo una tabla ordenada y llegan
  filas nuevas, reordenar en caliente es hostil. El patrón esperado es acumular y ofrecer
  («**N novedades**»), no inyectar.
- **El caudal de registros se pausa al desplazarse** y se reanuda al volver arriba.
- **Los avisos de eventos que exigen decisión** —una suspensión automática, un alta fallida— no
  pueden ser un mensaje efímero que se pierde si el operador estaba mirando otra pestaña.
  Necesitan un sitio persistente.

Diseña ambos regímenes con las mismas primitivas. En el entregable, di explícitamente cuáles
cambian de comportamiento cuando el canal está vivo.

---

## 7. Volumen y densidad

Esta consola vive de tablas. No son un adorno del panel: son el panel.

- El explorador de registros de petición se prueba con **cien mil filas** y trescientos sesenta y
  cinco días de histórico.
- Una flota puede tener decenas de instancias, cada una con su estado, su tráfico y su salud.
- El libro de facturas crece indefinidamente.

Filtrar, ordenar, buscar y paginar sobre volúmenes así es el caso normal, no el extremo. La
densidad de fila que elijas condiciona toda la consola.

---

## 8. Las superficies que hay que resolver

Hoy la aplicación tiene dieciséis. **Eso es lo que hay, no un requisito**: fusionar, partir o
reorganizar estas superficies es decisión tuya, y si el sistema pide otra estructura, propónla.
Lo que no puede pasar es que un trabajo se quede sin sitio.

Cada una descrita por lo que el operador necesita saber y decidir:

1. **Panorama de la plataforma** — ¿va todo bien? Cuántas instancias vivas, en gracia y
   suspendidas; volumen de peticiones y proporción de errores en la ventana elegida; ingreso
   mensual recurrente; actividad de código reciente; lo último que ha pasado. Es la primera
   pantalla del día y debe responder «¿tengo que actuar?» en tres segundos.

2. **Cartera de clientes** — quién es quién, cuánto factura cada uno, cuánto tráfico mueve, si
   alguno está en riesgo por cuota o por impago.

3. **Ficha de cliente** — sus datos comerciales y de facturación, editables; sus instancias; sus
   facturas; si algo suyo está en apuros.

4. **Flota de productos** — la lista donde conviven las cuatro situaciones de la sección 3.
   Distinguirlas es el trabajo principal de esta pantalla. Desde aquí se provisiona.

5. **Instancia: identidad y estado** — qué es, de quién, en qué plan, en qué URL responde, cómo
   está de cuotas, y las acciones de control: gracia, suspensión, reanudación, cambio de plan.

6. **Instancia: planes** — qué planes existen para este tipo de producto, cuáles están activos,
   qué límites traen, y el histórico de términos contratados.

7. **Instancia: cuotas** — consumo actual frente a límite en cada métrica, con edición del límite
   y de la política. Aquí hace falta ver lo que se acerca al techo antes de que lo toque.

8. **Instancia: uso** — evolución de peticiones, errores, latencia media y percentil alto, y
   transferencia, por hora o por día, con las rutas que más se piden.

9. **Instancia: registros** — las últimas peticiones atendidas, para diagnosticar en caliente.

10. **Instancia: desarrollo** — ritmo de commits, quién toca el código, actividad por día.

11. **Instancia: configuración y baja** — los datos técnicos, y la zona irreversible.

12. **Libro de facturas** — todo el ledger, con lo vencido primero, y las acciones que enlazan
    cobro con estado operativo (sección 4).

13. **Uso agregado** — la misma pregunta que la superficie 8, pero para toda la plataforma:
    quién consume qué.

14. **Explorador de registros** — cien mil filas, filtradas por instancia, método y clase de
    código. Read-only.

15. **Preferencias** — perfil del operador, duración por defecto de la gracia, avisos, tema.

16. **Entrada a la sesión** — email y contraseña. Una sola pantalla, un solo error posible.

Además, hoy existe una **paleta de comandos** con atajo de teclado que salta a cualquier
instancia, cliente o factura. Si en tu diseño la navegación no la necesita, dilo y justifícalo;
si la mantienes, intégrala en el sistema.

---

## 9. Requisitos no funcionales

- **Tema claro y oscuro, ambos de primera clase.** Ninguno es el modo degradado del otro.
- **WCAG AA de contraste** en los dos temas.
- **Teclado completo**: foco visible siempre, orden lógico, atajos donde aporten, foco gestionado
  al abrir y cerrar capas.
- Semántica de asistencia donde toca: elemento actual en la navegación, dirección de ordenación en
  las cabeceras, región viva para los avisos.
- **Sin saltos de maquetación** al cargar: los estados de carga ocupan el mismo espacio que el
  contenido que sustituyen.
- Las mutaciones se aplican al instante y se reconcilian con la respuesta del servidor; si falla,
  aviso claro y vuelta atrás.
- **Escritorio primero**, con degradación razonable. No hace falta una experiencia móvil completa:
  nadie suspende un cliente desde el autobús.

---

## 10. Restricciones técnicas fijas

- React 19, TypeScript, Vite.
- El backend no se toca: los endpoints, los estados y los permisos son los de las secciones 4 y 5.
- El canal de tiempo real de la sección 6.2 aún no existe: **la consola tiene que funcionar
  entera sin él**, y absorberlo después sin rediseño.
- **ECharts** para las gráficas. Ya se descartó la librería de gráficas de Carbon por rendimiento
  con datos densos; esa decisión se mantiene. ECharts no hereda tokens del sistema de diseño: sus
  opciones se construyen a mano con la paleta activa, y **eso es trabajo tuyo de diseño**, no un
  detalle de implementación. Define qué paleta de series usan las gráficas, en ambos temas.

Todo lo demás —enrutado, estado, formularios, tablas, estructura de carpetas— lo eliges tú.

---

## 11. Qué NO inventar

No diseñes lo que no existe:

- ni DNS, ni certificados, ni copias de seguridad, ni despliegues;
- ni pasarela de pago, ni prorrateo, ni recibos automáticos, ni autoservicio;
- ni estados de provisionado que la API no expone (sección 4);
- ni métricas que no están en la base de datos: solo existen `requests_day`, `disk_db_mb`,
  `disk_storage_mb` y `users_total`. Cosas como plazas editoriales o lecturas mensuales están en
  el plan comercial, no en los datos;
- ni multi-usuario, ni roles de equipo, ni invitaciones: hay un operador.

Y no diseñes contra un fantasma: **no existe una versión anterior de esta interfaz que debas
respetar, extender o superar**. No preguntes por ella, no supongas convenciones heredadas, no
mantengas nada «por compatibilidad». Empieza por el problema.

---

## 12. El sistema de diseño: Carbon v11, usado en serio

Carbon está fijado. Lo que se te pide no es «usar Carbon», es **usarlo de verdad** — porque el
modo habitual de fallar aquí es instalarlo, tomar prestados sus botones y sus campos, y
reconstruir a mano todo lo demás en hojas de estilo propias hasta que el sistema de diseño solo
aporta átomos y la personalidad la lleva un fichero de mil trescientas líneas que nadie mantiene.

### La regla

**Si Carbon o su librería de patrones de producto tienen el componente, se usa el componente.**
CSS propio solo para composición de maquetación. Cada excepción se justifica por escrito.

Entra en el inventario, no lo des por sabido: tabla de datos con sus barras de herramientas,
selección y acciones por lote; pestañas; tiles; listas contenidas; listas estructuradas; paneles
laterales y hojas superpuestas; cabecera de página; ventanas modales; menús de desbordamiento;
notificaciones en línea y emergentes; popovers y bocadillos informativos; indicadores de progreso;
esqueletos de carga; capas.

### Los tokens, completos

No colores sueltos ni píxeles a ojo:

- **Capas** (`layer-01`, `layer-02`, `layer-03`) para la jerarquía de superficies. Si te
  encuentras dibujando bordes de un píxel para separar planos, es que no estás usando el sistema
  de capas.
- **Escala de espaciado** del sistema, entera. Nada de valores intermedios inventados.
- **Tokens tipográficos** con sus variantes compactas para interfaces densas, y el estilo
  monoespaciado del sistema para identificadores, slugs, rutas, cifras y marcas de tiempo.
- **Tokens de movimiento** para transiciones: las duraciones y curvas están definidas.
- **Tokens de estado** para lo semántico. Si el sistema no tiene un tono para algo —el ámbar de un
  aviso intermedio es el caso clásico— dilo explícitamente y define la extensión como parte del
  sistema, no como un parche suelto.

### Temas

Carbon trae varios. Elige la pareja claro/oscuro que sirva a esta consola y **justifica la
elección**. Si decides que algún área conserve un tema distinto al resto —hay precedente en las
consolas de IBM— explica por qué mejora la lectura y no solo por qué queda bien.

### Tipografía

IBM Plex Sans y IBM Plex Mono. Son parte del sistema, no una decisión abierta.

### Criterio de éxito de esta sección

Al final del entregable debe poder responderse: **¿cuántas líneas de CSS propio necesita esta
consola, y por qué cada bloque?** Si la respuesta se acerca al millar, el rediseño ha fallado
aunque se vea bien: significa que Carbon está puesto pero no usado.

Referencia de patrón, para calibrar densidad y tono: las consolas de operación de IBM Cloud.

---

## 13. Qué entregar

En este orden.

### Primero: el sistema

- **Tokens** con valores concretos: qué usas de Carbon, qué extiendes y por qué.
- **Jerarquía de superficies**: cómo se apilan las capas en esta consola, con ejemplo.
- **Densidad**: altura de fila, escala de espaciado en uso, tamaños tipográficos elegidos para
  tablas, formularios y cabeceras.
- **Paleta semántica**: estado operativo, situación de facturación, salud de cuota, métodos HTTP,
  clases de código de estado, y **series de gráfica** en ambos temas.
- **Catálogo de primitivas**: cada una con su anatomía y **todos sus estados**, incluidos cargando,
  vacío, error y sin permiso. Marca cuáles son de Carbon tal cual, cuáles son composiciones y
  cuáles —si alguna— son nuevas, con su justificación.

### Segundo: cinco pantallas maquetadas con ese sistema

1. Panorama de la plataforma.
2. Flota de productos, con las cuatro situaciones visibles a la vez.
3. Instancia, vista principal, con una en gracia y su cuenta atrás.
4. Cartera de clientes.
5. Libro de facturas, con vencidos.

### Tercero: cómo se resuelven las once restantes

Prosa breve por superficie: qué primitivas del catálogo la componen. Si alguna no encaja, ahí se
ve.

### Cuarto: el plan de tiempo real

Media página. Qué primitivas del catálogo cambian de comportamiento cuando el canal de la
sección 6.2 está vivo, dónde vive el indicador de conexión, dónde aterrizan los eventos que
exigen decisión, y qué pasa exactamente cuando la conexión cae.

### Criterios de aceptación

- Las cuatro situaciones de un producto se distinguen **en una lista, sin abrir nada**.
- Un pendiente atascado se ve distinto de un pendiente reciente.
- Una tabla de cien mil filas es navegable.
- Una gracia y su tiempo restante se leen sin entrar al detalle; una gracia **caducada** también.
- La edad del dato es visible allí donde la cadencia del backend la hace relevante.
- Una sola acción primaria por pantalla.
- **Encender el canal de tiempo real no obliga a rediseñar ninguna pantalla**, y apagarlo tampoco
  rompe ninguna.
- Todo lo anterior funciona en tema claro y en tema oscuro.
