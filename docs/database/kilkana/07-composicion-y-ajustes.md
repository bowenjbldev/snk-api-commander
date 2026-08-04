# Composición y ajustes

Cómo se arma la portada, qué aspecto tiene el periódico y qué identidad publica. Cinco tablas
pequeñas y muy distintas del resto: no guardan contenido, guardan **configuración**.

---

## `kilkana_layouts` — la composición de las páginas

| Columna | Tipo | Nulo | Qué es |
|---|---|---|---|
| `id` | bigint unsigned | no | Clave primaria. |
| `layout` | enum | no · **único** | Qué página compone: `front` · `section`. |
| `schema` | longtext (JSON) | no | La composición entera. |
| `version` | int unsigned | no · def. 1 | Contador para escrituras concurrentes. |
| `created_at` · `updated_at` | timestamp | sí | Alta y última modificación. |

Hay **una fila por tipo de página**, no una por página. Dos en total:

| `layout` | Compone |
|---|---|
| `front` | La portada. |
| `section` | **Todas** las páginas de sección. |

Que `section` sea una sola fila es deliberado: todas las secciones comparten composición y
lo que cambia entre ellas es el conjunto de artículos que se les da. Deportes y Cultura se
ven igual porque son la misma plantilla con otro contenido.

### `schema` es el documento

Un JSON con la estructura de la página: bandas, columnas y los módulos dentro de cada
columna con su configuración. La base no valida su forma, así que **cualquier lectura debe
tratarlo como entrada no confiable**: normalizarlo y descartar lo que no reconozca en vez
de confiar en que esté bien.

Esa normalización es lo que hace que retirar un tipo de módulo no invalide una composición
guardada: la que traiga un módulo desconocido pierde ese módulo y conserva el resto.

### `version` es control de concurrencia optimista

Quien guarda manda la versión que leyó. Si no coincide con la almacenada, alguien ha
guardado en medio y la escritura se rechaza en vez de pisar su trabajo.

**El conflicto no debe resolverse solo.** Adoptar la versión remota en silencio pierde uno
de los dos cambios sin que nadie lo sepa; el rechazo tiene que llegar hasta la persona.

**Y la comprobación tiene que ir bajo el mismo bloqueo que la escritura**, o el contador no
sirve para nada. Leer la versión, compararla y después guardar deja un hueco por el que cabe
otro guardado entero: los dos leen la 3, los dos pasan la comprobación y los dos escriben la
4. Medido sobre seis guardados simultáneos declarando la misma versión, **dos respondían 200**
y uno de los dos perdía su trabajo sin que nada fallara. Con la fila bloqueada desde la
lectura hasta confirmar, el segundo espera, relee la versión ya subida y recibe su conflicto.

Le aplica igual a **restaurar** una revisión, que también sube la versión y por tanto compite
con un guardado normal.

---

## `kilkana_layout_revisions` — el historial de composiciones

| Columna | Tipo | Nulo | Qué es |
|---|---|---|---|
| `id` | bigint unsigned | no | Clave primaria. |
| `layout_id` | bigint unsigned | no | Composición. **CASCADE.** |
| `user_id` | bigint unsigned | sí | Quién guardó. SET NULL. |
| `schema` | longtext (JSON) | no | El documento **entero**, tal como estaba. |
| `created_at` | timestamp | **no** | Cuándo se guardó. |

Mismo patrón que el historial de artículos: copia completa, no diferencia, y sin
`updated_at` porque una revisión no se modifica.

Aquí importa especialmente, porque hay operaciones que **reemplazan la composición entera**
—aplicar una plantilla, por ejemplo—. Sin historial, eso sería irreversible.

---

## Los temas: un catálogo vendible dentro de cada periódico

Qué aspecto tiene el periódico, y qué se le puede vender. Cuatro tablas: el tema, sus
variantes, el vocabulario con el que se navega y el pivote que los une.

**Un tema entero es un dato.** Los tokens, el estilo —incluidos el kicker y la tarjeta—, la
cabecera (un enum de dos valores) y las plantillas de arranque son todo JSON, así que viven en
`kilkana_theme_variants.spec` y publicar un tema nuevo no exige desplegar el frontend. Eso es
lo que permite producirlos deprisa y venderlos: el código solo pone los componentes que saben
pintar un spec.

El catálogo **se replica en la base de cada periódico** y su maestro son ficheros versionados
en `database/seeders/data/themes/`. `kilkana:sync-themes` los publica: compara por `version`,
así que un catálogo de trescientos temas sin cambios no escribe una fila, y **nunca toca una
fila `tenant`**.

**`kilkana_themes`** — el tema vendible:

| Columna | Qué es |
|---|---|
| `slug` **único** | Identidad estable; la clave del fichero maestro |
| `name` · `tagline` · `description` | La tarjeta del catálogo. FULLTEXT sobre las tres |
| `status` | `draft` · `published` · `deprecated`. Solo se ofrece lo publicado |
| `featured` · `position` · `popularity` | Los tres órdenes curados |
| `version` | Contra esta compara el sincronizador |
| `thumbnail_path` · `screenshots` · `demo_url` | Assets del producto, fuera de `kilkana_media` |
| `published_at` | Se sella al publicarlo por primera vez; ordena «novedades» |

**`kilkana_theme_categories`** + **`kilkana_theme_category_theme`** — el vocabulario de
filtrado (`news`, `anime`, `technology`…) y su pivote N:M. Es cerrado y lo publica el fichero
maestro: una categoría que se pudiera crear al vuelo dejaría de ser un filtro y se convertiría
en la lista de sinónimos de quien la escribió, que es justo lo que pasó con `kilkana_tags`.

### `popularity` es un número curado, no medido

Con el catálogo replicado por inquilino, cada base solo se ve a sí misma: **no hay dónde contar
cuántos periódicos visten un tema**. El número lo fija quien publica. Medirlo de verdad exigiría
un contador global en el control plane, que es la pieza que se descartó al elegir el catálogo
por inquilino.

**`kilkana_theme_variants`** — la piel aplicable:

| Columna | Tipo | Nulo | Qué es |
|---|---|---|---|
| `id` | bigint unsigned | no | Clave primaria. Es lo que referencia `kilkana_settings`. |
| `theme_id` | bigint unsigned | no | Su tema. **CASCADE.** |
| `source` | enum | no · def. `catalog` | `catalog` (lo trajo el fichero) · `tenant` (lo adoptó o retocó este periódico). |
| `origin_variant_id` | bigint unsigned | sí | De qué variante del catálogo se copió. **SET NULL.** |
| `origin_version` | varchar(16) | sí | La versión del tema congelada al adoptar. |
| `slug` | varchar(64) | no · **único** | Identidad estable, derivada del nombre al crear. |
| `name` | varchar(255) | sí | Nombre visible de la variante. |
| `spec` | longtext (JSON) | sí | **El tema entero**: `masthead`, `tokens`, `style`, `layouts`. |
| `tokens` · `style` | longtext (JSON) | sí | Solo el retoque que puso esta redacción encima. |
| `position` · `is_default` · `thumbnail_path` | | | Orden dentro del tema y cuál se ofrece primero. |
| `created_at` · `updated_at` | timestamp | sí | Alta y última modificación. |

### Vestir es copiar, y por eso publicar no rompe nada

**Vestir un tema crea siempre una fila `tenant`** que copia el `spec` y anota `origin_version`.
Si los ajustes apuntaran a la fila del catálogo, la siguiente sincronización la reescribiría y
el periódico amanecería con otra portada, sin pedirlo y sin rastro.

Con la copia, publicar la versión 2 de un tema deja intacto a quien lleva la 1, y comparar
`origin_version` con la versión publicada es lo que enciende el aviso de «hay una actualización»
— que acepta la redacción, no el catálogo.

`kilkana_settings.theme_variant_id` es **RESTRICT** y apunta siempre a una fila `tenant`. Que
sea `tenant` y no `catalog` lo impone el modelo, no la base: MariaDB no admite subconsultas en
un `CHECK`, igual que en los dos niveles de secciones.

### Qué puede tocar una redacción, y qué no

El `spec` lo trae el catálogo entero. Lo que la redacción cambia encima es un subconjunto
acotado: los 15 colores, el radio de página, el peso, la escala y el tracking del titular y la
densidad de bloque. El lenguaje kicker/tarjeta, la cabecera y las plantillas pertenecen al tema.

Los rangos (hex de seis dígitos, radio 0–12px, peso 600–800 en centenas, escala 0.8–1.1,
tracking −0.04–0.02em, padding 10–18px) están declarados una sola vez en el modelo y los miran
dos validaciones: la del retoque parcial que manda el inspector y la del spec completo que trae
el catálogo. El servidor responde 422; el cliente además normaliza al leer, porque una fila
tocada a mano en la base no debe poder tumbar la portada.

### Un tema no entra si rompe la accesibilidad

`kilkana:sync-themes` valida cada spec **antes de escribir nada** y falla ruidosamente con el
par de colores en la mano. Se comprueba el contraste AA (≥4.5:1) de `text`, `textMuted`,
`textSoft` y `accent` sobre `surface`, los rangos, que `masthead` sea uno de los que sabe pintar
el frontend y que todos los bloques de las plantillas existan.

Es la contrapartida de producir temas deprisa: un gris elegante sobre blanco se ve bien en una
captura y deja por debajo de AA al periódico que lo instale, y este producto tiene presupuestos
Lighthouse de 100. Un bloque desconocido tampoco se descarta aquí —el normalizador del editor sí
lo hace, porque allí protege de composiciones viejas—: en el catálogo significa que esa banda no
se va a pintar y nadie lo sabría.

---

## `kilkana_settings` — la identidad del periódico

| Columna | Tipo | Nulo | Qué es |
|---|---|---|---|
| `id` | tinyint unsigned | no | Clave primaria no autoincremental. **Siempre 1.** |
| `theme_variant_id` | bigint unsigned | sí | La piel que viste. **RESTRICT.** Nula solo en una instalación recién sembrada. |
| `tenant_name` | varchar(255) | sí | Cómo se llama el periódico. |
| `tenant_tagline` | varchar(255) | sí | Su lema. |
| `tenant_city` | varchar(255) | sí | Su ciudad. |
| `tenant_url` | varchar(255) | sí | Su dirección pública. Validada `url:http,https`. |
| `onboarded` | boolean | no · def. `false` | Si ya pasó la puesta en marcha inicial. |
| `created_at` · `updated_at` | timestamp | sí | Alta y última modificación. |

### Aquí hubo `theme`, `theme_overrides` y `version`

Las tres se retiraron a la vez y por dos motivos distintos.

`theme` era un `varchar(64)` sin lista cerrada y `theme_overrides` un mapa JSON de variante →
retoque. Juntas eran **una tabla de variantes escrita dentro de dos columnas**, y en cuanto la
tabla existe de verdad sobran: la variante es la fila, y la clave foránea impide vestir una que
no existe.

`version` era control de concurrencia optimista, y se fue porque **no llegaba a ejecutarse**:
la regla del controlador era `nullable` y ningún cliente mandaba el número, así que la
comprobación se saltaba siempre y la respuesta devolvía una versión que no garantizaba nada —
peor que no tenerla, porque parecía una garantía. Los ajustes los toca poca gente y rara vez,
así que la política es last-write-wins y está dicha en voz alta. `kilkana_layouts` **sí**
conserva la suya, y allí es obligatoria: dos personas componiendo la portada a la vez es el
caso normal.

### La regla de crecimiento

Esta tabla va a crecer, y no crece de cualquier manera:

- **Columna** si el ajuste tiene tipo o clave foránea (`logo_media_id`, `default_og_image_id`).
  Es lo que la base puede defender, así que que lo defienda.
- **Columna JSON de grupo** si es un grupo abierto (`comments`, `paywall`, `integrations`),
  normalizada al leer. Añadir una clave dentro del grupo no toca la base, que es justo lo que
  ahorra la barrida de migraciones sobre todas las bases de inquilino.
- **Lo que no vuelve** es la bolsa única con todo dentro, ni una tabla clave-valor: aquí la
  clave siempre la conoce el código. El día que la elija un inquilino o una extensión, eso es
  un registro aparte (definiciones + valores), no convertir esta tabla.

**Una sola fila**, con `id = 1`. La migración añade `CHECK (id = 1)`, por lo que MariaDB rechaza
una segunda identidad con otro id. Es una excepción histórica a la convención `$table->id()` del
proyecto y no debe copiarse a tablas nuevas.

Los campos de `tenant_*` son la **identidad publicable**, y salen de aquí a la cabecera del
periódico. Con `tenant_name` nulo, quien la pinte tendrá que inventarse un valor por defecto —
y entonces el nombre del periódico vive en el cliente, que es justo lo contrario de lo que un
producto multi-inquilino necesita.

`tenant_url` no está por completar la ficha: **todo correo que sale necesita enlazar a algún
sitio**, y el enlace de un boletín no puede ser relativo. Sin ella, confirmar la suscripción y
darse de baja no tienen a dónde devolver al lector, y responden texto plano en vez de redirigir.
Va validada `url:http,https` y no `url` a secas: se pinta como enlace y sale en cada correo,
así que `javascript:` aquí sería un agujero, igual que lo era en la web de una firma.

La API los sirve **anidados** (`tenant: {name, tagline, city, url}`) aunque debajo sean columnas
planas: es la forma que ya consumía la salida pública, y el mapeo entre las dos vive en un
solo sitio.

### Por qué columnas y no una bolsa JSON

Esto **fue** una columna `settings` con todo dentro. La bolsa cobraba el precio de la
flexibilidad sin darla: quien escribe ya enumera cada clave que acepta, así que nunca hubo
claves arbitrarias. A cambio se perdían los tipos —`onboarded` guardado como lo que cayera— y
había que fusionar a mano para que un guardado parcial no borrara lo que no venía, con un caso
especial para `tenant` que alguien tenía que acordarse de replicar en el siguiente ajuste
anidado. El día que se olvidara, guardar la ciudad habría borrado el nombre en silencio.

Con columnas eso desaparece: se escribe lo que llega y lo demás se queda donde estaba.

**`theme_overrides` sigue siendo JSON, y esa es la diferencia entre usar JSON y esconderse en
él**: es un mapa de variante → token → valor, abierto por definición y opaco para la base. Su
forma la normaliza quien escribe, por tipo y por rango — un valor fuera de banda puede dejar
texto ilegible sobre su fondo.

> La tabla hermana `commander_settings` sigue siendo una bolsa JSON. La divergencia es
> consciente: aquellos son ajustes de infraestructura del operador, y estos los toca una
> redacción desde una pantalla y salen al público.

---

## Aviso al recrear la base

Estas tablas guardan **decisiones, no contenido editorial**. El seeder repone snapshots base de
layouts, temas y settings, así que una instalación limpia arranca con estructura; no puede
reconstruir las decisiones hechas después por una redacción. Un `--fresh` sin backup seguido de
seed recupera los defaults, no la portada, ni las variantes propias, ni la identidad.

Y arrastra algo que no está en estas tablas: **`--fresh` vacía también `users`**, y el admin del
periódico lo crea el provisioning, no el seeder. Una base recreada a mano se queda sin cuenta
administradora aunque el contenido demo vuelva entero.
