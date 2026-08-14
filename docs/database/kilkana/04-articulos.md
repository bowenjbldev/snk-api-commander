# Artículos

El núcleo. Seis tablas: la pieza, su historial de URLs, su historial de versiones, su
audiencia y sus etiquetas.

---

## `kilkana_posts` — la pieza

| Columna | Tipo | Nulo | Qué es |
|---|---|---|---|
| `id` | bigint unsigned | no | Clave primaria. **No aparece nunca en una URL pública.** |
| `author_id` | bigint unsigned | no | Firma. Quién aparece firmando. |
| `category_id` | bigint unsigned | no | Sección. Una y solo una. |
| `cover_media_id` | bigint unsigned | sí | Foto de portada. |
| `og_image_id` | bigint unsigned | sí | Imagen para redes, cuando la portada no vale. |
| `title` | varchar(500) | no | Titular. |
| `excerpt` | text | no | Entradilla. |
| `slug` | varchar(200) | no | Parte legible de la URL. Se deriva del titular si no llega, recortando a 200. |
| `seo_title` | varchar(500) | sí | Titular para buscadores. Nulo = se usa `title`. |
| `seo_description` | text | sí | Descripción para buscadores. Nula = se usa `excerpt`. |
| `og_title` | varchar(500) | sí | Titular para redes. Nulo = cae a `seo_title` y luego a `title`. |
| `og_description` | text | sí | Descripción para redes. Nula = cae a `seo_description` y luego a `excerpt`. |
| `status` | enum | no · def. `draft` | `draft` → `review` → `scheduled` → `published`. La base rechaza cualquier otro valor. |
| `content_tier` | enum | no · def. `free` | `free` · `metered` · `locked`. Cuánto lee quien no paga. |
| `body_blocks` | longtext (JSON) | sí | **El cuerpo.** Lista ordenada de bloques. |
| `canonical_url` | varchar(2048) | sí | Original **de otro sitio** para una pieza sindicada. Absoluta siempre. |
| `featured` | boolean | no · def. falso | Marca de destacado. |
| `noindex` | boolean | no · def. falso | Fuera de buscadores. **Sigue visible en el periódico.** |
| `created_by` | bigint unsigned | sí | Cuenta que **tecleó** la pieza. |
| `updated_by` | bigint unsigned | sí | Cuenta que la tocó por última vez. |
| `url_date` | date | sí | Fecha de la URL. **Nula = no publicado.** Se sella al publicar. |
| `publish_at` | timestamp | sí | El instante de publicación: previsto si está programada, efectivo si ya salió. |
| `created_at` · `updated_at` | timestamp | sí | Alta y última modificación. |

### Relaciones

| | Apunta a | Al borrar el destino |
|---|---|---|
| `category_id` | `kilkana_categories.id` | **RESTRICT** |
| `author_id` | `kilkana_authors.id` | **RESTRICT** |
| `cover_media_id` · `og_image_id` | `kilkana_media.id` | SET NULL |
| `created_by` · `updated_by` | `users.id` | SET NULL |

`RESTRICT` donde borrar rompería la hemeroteca: una pieza publicada no puede quedarse sin
sección ni sin firma. `SET NULL` en la autoría: dar de baja a alguien no borra lo que
escribió, solo deja de saberse quién lo tecleó.

### Índices

| Índice | Para qué |
|---|---|
| **único `(url_date, slug)`** | La URL pública. Ver abajo. |
| `(status, publish_at)` | Las dos consultas calientes con un solo índice: listar lo publicado por fecha y encontrar lo programado que ya vence. |
| texto completo `(title, excerpt)` | Buscador del sitio, sin motor externo. |

---

## La URL: fecha + slug, y el par es la clave

```
/article/2026/06/28/cumbre-del-clima-cierra-un-acuerdo-historico
          └──────┘  └──────────────────────────────────────────┘
          url_date                     slug
```

**El índice único es la PAREJA**, no el slug suelto. Eso significa que el mismo titular
puede repetirse en años distintos sin ningún sufijo:

```
2026/05/11/el-barca-gana-en-el-bernabeu     ← dos piezas
2027/05/09/el-barca-gana-en-el-bernabeu     ← dos URLs, mismo slug
```

Un titular que vuelve cada temporada no es una colisión: es otra pieza en otro día. Solo
**dos del mismo día con el mismo titular** necesitan desempate, y entonces el segundo lleva
`-2` — pero eso ya es un duplicado real, no un titular recurrente.

### `url_date` se sella al publicar y no se recalcula

Cuando una pieza pasa a `published`, `url_date` toma la fecha de `publish_at` (o la del
momento, si no había) y **se queda ahí para siempre**. Corregir después `publish_at` —para
arreglar la hora de un cierre— no mueve una URL que ya está enlazada fuera.

Mientras la pieza no está publicada, `url_date` es **nula**: un borrador no tiene URL
pública. Como en un índice único los nulos no se comparan entre sí, eso permite además
tener varios borradores con el mismo slug —dos piezas sin titular, por ejemplo—.

### `slug` es obligatorio

Se deriva del titular al guardar si no llega. Fue nulo en su día, y un publicado sin slug
era una pieza que aparecía en la portada, en el sitemap y en el feed enlazada por su
identificador numérico — una URL que **responde 404**, porque la resolución es por fecha y
slug y nunca por `id`. Cualquier código que construya la URL de un artículo debe hacerlo
desde `url_date` + `slug`, y no tener nada que enlazar cuando falta `url_date`.

### Los segmentos de fecha mandan

Una petición con la fecha equivocada no debe servir el artículo: debe redirigir a la
correcta. Si cualquier fecha valiera, el mismo artículo viviría en infinitas URLs.

---

## Los metadatos de publicación

Ocho columnas que no cuentan la noticia: cuentan **cómo se presenta y quién puede leerla**.
Están en `kilkana_posts` y no en una tabla aparte porque se leen siempre juntas, para una
sola pieza, al montar su cabecera. Nunca se pregunta «dame los artículos cuyo `og_title`
sea X».

### Tres pares que se ven en sitios distintos

```
title    ·  excerpt            lo que se lee EN el periódico
seo_*                          lo que se lee en el resultado de un buscador
og_*                           lo que se lee en una tarjeta compartida
```

Se separan porque el mismo hecho se cuenta distinto según dónde aparezca. El titular
editorial puede permitirse ser elíptico —quien lo lee ya está en la página—; el de buscador
tiene que contener lo que alguien teclea; el de redes tiene que dar ganas de pulsar. Cada
uno cae al anterior cuando está vacío, así que rellenar los tres es la excepción.

`og_image_id` existe por una razón de forma: la portada se recorta apaisada para la tarjeta
y las redes piden algo cercano a 1200×630. Cuando hay una imagen social propia, manda sobre
la portada.

### Las palabras clave no son una columna: son los tags

Aquí hubo un `news_keywords`, la vieja meta de Google News. Se retiró por dos motivos, y el
segundo es el que importa:

1. Google dejó de usarla y la quitó de su documentación para editores.
2. **Duplicaba `kilkana_tags`.** Los temas de una pieza ya están ahí, estructurados, únicos
   por pareja y normalizados; la columna era la misma información en texto libre, sin
   unicidad y mantenida a mano en paralelo.

La deriva no era teórica: la única pieza sembrada con las dos cosas tenía
`cumbre del clima, emisiones, renovables` en la columna y `climate, energy, summit` en sus
tags. Dos listas de temas para un mismo artículo, escritas el mismo día, ya distintas.

Cuando haga falta declarar las palabras clave a un buscador, salen de los tags.

### `noindex` no es despublicar

Una pieza con `noindex` **sigue publicada, enlazada y legible**. Lo único que cambia es que
no se anuncia en el sitemap y se le pide al buscador que no la indexe. Es lo que se usa
cuando hay que retirar algo de las búsquedas sin borrarlo del periódico.

Por eso son dos preguntas distintas y hay dos formas de consultarlas: «lo que ve un lector»
(publicado) y «lo que se le puede anunciar a un buscador» (publicado **y** sin `noindex`).
Confundirlas retiraría la pieza del sitio, que no es lo que se pidió.

### `canonical_url` apunta hacia fuera

Solo se rellena cuando la pieza es **de otro**: un teletipo de agencia, contenido de un
socio. Declara dónde está el original para que dos medios no compitan por la misma pieza y
el buscador no tenga que elegir por su cuenta cuál manda.

Se exige **absoluta**: una canónica relativa no dice a qué dominio apunta, y esta columna
existe justamente para señalar a otro dominio. Una pieza con canónica ajena tampoco debería
anunciarse en el sitemap propio.

### `content_tier` es SEO además de negocio

Un buscador puede indexar el texto completo de un artículo cerrado sin que eso sea
encubrimiento **solo si se declara**. Servirle el texto entero a un rastreador y un muro al
lector, sin decirlo, es exactamente lo que se penaliza.

| | Qué lee quien no paga |
|---|---|
| `free` | todo |
| `metered` | todo, hasta agotar su cuota |
| `locked` | hasta donde corta el bloque `paywall` del cuerpo |

Esta columna dice **si** la pieza está cerrada; el bloque `paywall` dice **por dónde** corta.
No son lo mismo: una medida no necesita bloque —se mide entera— y una cerrada sin bloque no
sabría por dónde partirse.

La API pública falla cerrada mientras no haya una sesión lectora vinculada a la suscripción:
`metered` y `locked` solo entregan los bloques hasta el primer `paywall`; si falta, entregan un
muro automático y ningún texto. Los listados públicos no sirven `body_blocks` nunca. La API
autenticada sí recibe el cuerpo completo. Falta el contrato de entitlement para desbloquear el
resto a un suscriptor confirmado; no debe resolverse ocultando HTML en el navegador porque el
payload seguiría filtrándolo.

### Por qué columnas y no una tabla de pares clave-valor

Aquí hubo esa tabla y se retiró. Con un conjunto que se lee siempre completo, guardar cada
etiqueta en su fila significa reconstruir un objeto a base de `JOIN`s, y a cambio no se gana
nada: la lista de claves válidas sigue teniendo que vivir en el código, y se pierde lo que sí
da una columna —que `seo_title` no pueda pasar de 500 caracteres, que `noindex` sea booleano,
que `og_image_id` sea una clave foránea con su regla de borrado—.

La regla que lo decide no es cuántos campos hay, sino **cómo se leen**: un conjunto que
siempre se pide entero va en columnas; uno por el que se filtra necesita además que la
columna exista de verdad. Los dos casos apuntan al mismo sitio.

---

## `kilkana_post_slugs` — las URLs viejas

| Columna | Tipo | Nulo | Qué es |
|---|---|---|---|
| `id` | bigint unsigned | no | Clave primaria. |
| `post_id` | bigint unsigned | no | Artículo al que apunta. **CASCADE.** |
| `path` | varchar(255) | no · **único** | La ruta antigua **entera**: `2026/05/11/titular-viejo`. |
| `created_at` · `updated_at` | timestamp | sí | Alta y última modificación. |

Renombrar no puede romper lo que ya está enlazado. Un titular se retoca a los diez minutos
de publicar y para entonces ya hay enlaces fuera apuntando a la URL vieja.

**Guarda la ruta completa, no el slug suelto**: desde que la fecha forma parte de la URL, un
slug por sí solo no identifica un artículo. Con la ruta entera, un solo mecanismo cubre
renombrar el titular y mover la fecha.

**Resuelve en un salto**: como guarda `post_id` y no la ruta destino, una URL vieja siempre
apunta a la actual, aunque haya habido tres renombrados. No se forman cadenas.

### Cómo funciona

**Cuándo nace una fila.** Solo al cambiar la URL de un artículo **ya publicado** —el titular
o la fecha—. Un borrador no tiene URL pública que conservar, y publicar por primera vez no
deja nada atrás. Por eso la tabla nace vacía y sigue vacía hasta que alguien edita de verdad:
en un juego de datos recién sembrado hay cero filas, y eso es correcto.

Al renombrar pasan dos cosas, en este orden:

```
la ruta que el artículo DEJA   →  entra en el historial apuntando a él
la ruta que el artículo TOMA   →  sale del historial, si estaba (solo la suya)
```

La segunda evita que una ruta sea a la vez la URL actual y una redirección hacia sí misma,
que es lo que pasaría al renombrar `a → b` y arrepentirse.

**Cómo se resuelve una petición.** Cuando llega `/2026/06/28/titular`, se intenta en tres
pasos y se para en el primero que responde:

1. **¿Hay un artículo publicado con esa fecha y ese slug?** Se sirve, y se cuenta la lectura.
   Es el camino de todos los días; el historial ni se toca.
2. **¿Está esa ruta en el historial?** Se busca su artículo y, **si sigue publicado**, se
   responde una redirección permanente a su URL de ahora. Que se compruebe el estado importa:
   una pieza despublicada no puede recibir tráfico por la puerta de atrás.
3. **¿Hay exactamente un artículo publicado con ese slug, en cualquier fecha?** Se redirige a
   él. Esto **no necesita el historial**: repara una URL con la fecha mal escrita o teclada a
   mano. Si hay dos, no se elige por nadie.

Si ninguno responde, es un 404 de verdad.

El paso 3 es también la razón de que la fecha de la URL no sea decorativa: si los segmentos no
casan con la sellada, se redirige a la buena en vez de servir el artículo. Sin eso, el mismo
artículo viviría en infinitas URLs y cada una competiría con las demás.

**El ancho de `path` depende del de `slug`**, y por eso el slug tiene tope. La ruta son los
11 caracteres de la fecha más el slug: con el tope en 200, el máximo es 211 y estos 255
sobran. Sin tope no salía la cuenta —el slug llegaba a 255 y la ruta a 266—, así que
renombrar un artículo de titular largo fallaba **aquí**, al ir a grabar la redirección, con
el artículo ya guardado. Si algún día se sube el tope del slug, esta columna sube con él.

Dos reglas al renombrar:

1. La ruta anterior entra en el historial.
2. Si la ruta nueva **estaba** en el historial, se saca. Sin esto, renombrar `a → b` y
   arrepentirse dejaría `a` siendo a la vez la URL actual y una redirección hacia sí misma.

El `único` sobre `path` significa que una URL vieja apunta a **un** artículo: si dos piezas
pudieran reclamar la misma ruta retirada, la redirección no sería decidible.

### Una URL retirada no vuelve a estar libre

Esta es la regla que hace que lo anterior se sostenga: **una ruta que está en el historial
cuenta como ocupada**, igual que si la tuviera un artículo vivo. Sigue redirigiendo, así que
no está libre.

Cuenta en los dos momentos en que se decide una URL:

- **Al publicar**, el desempate mira artículos **y** historial, así que una pieza nueva no
  nace encima de una redirección viva — le toca `-2`.
- **Al renombrar** una pieza ya publicada, se comprueba antes de guardar y, si la ruta es de
  otro, se responde **422**. El propio artículo queda excluido: revertir un renombrado tiene
  que seguir siendo posible, y su URL vieja no puede bloquearle.

Sin esta regla pasaban dos cosas, las dos silenciosas. Renombrar hacia un slug ya ocupado el
mismo día reventaba contra el índice único con un error de base de datos en la respuesta. Y
renombrar hacia una ruta que otro artículo tenía retirada **borraba su redirección**: quien
siguiera un enlace antiguo del primero aterrizaba en el segundo, con un 200 y sin ninguna
señal de que había cambiado de artículo.

Por eso el borrado del historial va acotado **por artículo**: al adoptar una ruta solo se
retira la propia. Es cinturón sobre tirantes —el modelo ya no deja adoptar la de otro—, pero
protege a los datos que se crearan antes de la regla.

---

## `kilkana_post_revisions` — el historial

| Columna | Tipo | Nulo | Qué es |
|---|---|---|---|
| `id` | bigint unsigned | no | Clave primaria. |
| `post_id` | bigint unsigned | no | Artículo. **CASCADE.** |
| `user_id` | bigint unsigned | sí | Quién guardó esa versión. SET NULL. |
| `payload` | longtext (JSON) | no | El artículo **entero** tal como estaba antes del cambio. |
| `created_at` | timestamp | **no** | Cuándo se guardó. |

Se escribe una fila **antes** de cada modificación, así que `payload` es siempre el estado
anterior. Copia completa, no diferencia: recuperar una versión no exige reconstruir nada.

Es la única tabla con `created_at` obligatorio y sin `updated_at`: una revisión no se
modifica, solo se crea.

---

## `kilkana_post_views` — la audiencia real

| Columna | Tipo | Nulo | Qué es |
|---|---|---|---|
| `id` | bigint unsigned | no | Clave primaria. |
| `post_id` | bigint unsigned | no | Artículo. **CASCADE.** |
| `date` | date | no | Día contado. |
| `opens` | int unsigned | no | Veces que se abrió. |
| `engaged` | int unsigned | no | Veces que se **leyó**: alguien pasó el umbral de lectura. |
| `readers` | int unsigned | no | Personas distintas que leyeron ese día. |
| `ref_search` · `ref_social` · `ref_direct` · `ref_internal` · `ref_other` | int unsigned | no | De dónde llegaron las aperturas. |

`date` está en medio de la tabla y no al final con las marcas de tiempo, saltándose la
convención a propósito: aquí **no marca cuándo se escribió la fila, es parte de su
identidad** —la mitad del único `(post_id, date)`—. Es una dimensión, no una marca.

Y esta tabla no lleva `created_at` ni `updated_at`, que es la tercera excepción de la base a
«siempre marcas de tiempo». El motivo es de coste: el incremento de un contador es la
escritura más frecuente del sistema, y con marcas cada lectura del periódico escribiría
además `updated_at`.

Dos índices, y cada uno responde una pregunta distinta:

- Único `(post_id, date)` — una fila por artículo y día, que se incrementa. Es lo que
  permite sumar en vez de insertar una fila por visita.
- `(date)` — «lo más leído de hoy» recorre **todos** los artículos de una fecha, así que la
  consulta empieza por el día y no por la pieza.

Agregada por día, no fila por visita: «lo más leído de hoy» y «de la semana» son sumas
sobre un rango de fechas, y la tabla no crece con el tráfico sino con el catálogo.

### Abrir y leer no son lo mismo

Es la distinción que hace honesto el número. Con un solo contador, quien entra y se va a los
dos segundos vale igual que quien lee entero — y entonces «lo más leído» ordena por el titular
que mejor engaña, no por lo que la gente lee.

```
engaged / opens     cuántos de los que entran se quedan
engaged / readers   si la pieza se relee o se lee una vez y se olvida
```

Los agregados que consume el periódico suman **`engaged`**, no `opens`.

### Quién cuenta, y por qué no el servidor

Lo cuenta el **navegador del lector**, con un aviso al abrir y otro al pasar el umbral. No lo
cuenta el render, y eso no es un detalle de implementación: la salida pública está cacheada,
así que contar en el render cuenta renders. Una pieza que arrasa se renderiza una vez por
minuto pase lo que pase, de modo que **el error crecía con el éxito**. Contando desde el
cliente, además, los robots dejan de aparecer solos: no ejecutan JavaScript.

`readers` sale de deduplicar por visitante, y el visitante es un hash con sal que lleva la
**fecha dentro**: el de hoy y el de mañana no se pueden emparejar ni queriendo. No se
almacena. Sirve para no contar dos veces y para nada más, así que esta medición no rastrea a
nadie y no necesita consentimiento.

Eso también fija lo que esta tabla **no** puede contestar: retención —si el lector de ayer
vuelve hoy—, porque exigiría reconocer a la misma persona entre días. Esa pregunta se
responde donde hay una cuenta identificada, no con visitantes anónimos.

### La procedencia la decide el servidor

Los cinco cubos salen de la cabecera `Referer`, clasificada en el servidor. Si la mandara el
cliente, sería un número que cualquiera puede escribir.

`ref_direct` es **sin procedencia**, y `ref_other` es otro sitio que enlaza. Juntarlos
borraría justo el dato que dice quién te cita.

### Tres ventanas, y ninguna se deriva de las otras

De esta tabla salen tres agregados, que son tres preguntas distintas:

| | Suma |
|---|---|
| lecturas de hoy | las filas de la fecha de hoy |
| lecturas de la semana | las de los últimos siete días |
| lecturas totales | todas las filas de la pieza |

«Lo más leído hoy» lo dominan las piezas recientes; el total, las que más se leyeron en su
momento aunque hoy no las abra nadie. Un periódico necesita las dos cosas y ordenan distinto.

### Aquí hubo una columna `posts.reads`

Un entero en la propia pieza con un número grande dentro, que **nada incrementaba**. Convivía
con esta tabla, y al ser lo más fácil de leer, era lo que acababa pintándose como audiencia.
El número no era falso por casualidad: era falso por construcción, porque nadie lo escribía.

Contar lecturas en una columna de la pieza tiene además un problema de fondo: obliga a un
`UPDATE` sobre la fila del artículo en **cada visita**, justo la fila más leída y más
bloqueada del sistema. Agregar por día en una tabla aparte cuesta una fila por artículo y
día, y la tabla crece con el catálogo y no con el tráfico.

---

## `kilkana_tags` y `kilkana_post_tag` — las etiquetas

**`kilkana_tags`**

| Columna | Tipo | Nulo | Qué es |
|---|---|---|---|
| `id` | bigint unsigned | no | Clave primaria. |
| `name` | varchar(255) | no · **único** | Nombre visible, tal y como se escribió la primera vez. |
| `slug` | varchar(255) | no · **único** | Forma para URL, y **lo que identifica a la etiqueta**. |
| `created_at` · `updated_at` | timestamp | sí | Alta y última modificación. |

**`kilkana_post_tag`** — `id`, `post_id`, `tag_id`, con único `(post_id, tag_id)`. Ambas
claves foráneas en CASCADE: borrar un artículo o una etiqueta limpia el pivote.

Las etiquetas son de **tema** («elecciones», «sequía»), transversales a las secciones. Se
crean sobre la marcha al escribir una que no existe.

### El slug no es una URL: nadie sirve `/tag/…`, y es una decisión

Conviene decirlo antes de que alguien lea «slug» y dé por hecho que hay página detrás. **No
la hay, y el estado por defecto debería ser que no la haya.** Medido sobre el juego de datos:

```
151 etiquetas para 59 artículos publicados

  1 artículo  →  129 etiquetas   (85 %)
  2 artículos →   16
  3 artículos →    5
  4 artículos →    1
```

Una página por etiqueta serían **129 páginas con un solo artículo dentro**. Cada una compite
con el artículo que contiene y las 129 juntas se comen presupuesto de rastreo sin aportar
nada — contenido delgado de manual, que es por lo que los archivos de etiqueta de WordPress
salen con `noindex` de fábrica.

Y hay algo peor que la delgadez: **estas etiquetas nacen tecleando**. No son un vocabulario
decidido, son lo que escribió quien redactó la pieza, así que publicar una URL por cada una
publica también los sinónimos y las erratas de la redacción. Las secciones —19, con jerarquía
y descripción— ya cubren el eje de «páginas de tema», y lo cubren bien.

Lo que las secciones **no** cubren es el hilo de un asunto que las atraviesa y tiene recorrido:
«cumbre del clima», «elecciones 2026». Ahí una página que lo recoge todo sí vale. Pero eso son
cinco o seis al año y **las elige alguien**, así que la forma correcta no es «las etiquetas
tienen URL» sino «una etiqueta se puede promover a tema» — la misma figura que `status` en una
sección: se recibe URL pública cuando alguien lo decide, no por existir.

El coste de esa promoción, para cuando se pida:

```
kilkana_tags        + status (draft · live), seo_title, seo_description, noindex
kilkana_tag_slugs   porque quien tiene URL pública tiene historial
```

Lo último no es opcional: es la regla de esta base, y es el coste que no se ve al decir
«pongamos páginas de etiqueta».

### Lo que identifica a una etiqueta es su slug

Las dos columnas son únicas, pero **no protegen lo mismo**, y la que manda es `slug`.

`name` es único por cotejamiento: las comparaciones ignoran mayúsculas y acentos, así que
`'Sequía'` y `'sequia'` ya eran la misma etiqueta. Lo que no cubría era la **puntuación**:

```
«Covid-19»  y  «Covid 19»     →  dos nombres distintos, un solo slug: covid-19
```

Eso son dos filas para la base y una sola cosa para un lector, y como `slug` es único la
segunda no cabía. Por eso quien escribe una etiqueta al vuelo —el caso normal: alguien
teclea el tema de su artículo— **busca por slug**: si ya hay una con ese slug, se usa esa.
Las variantes de escritura se colapsan solas.

Consecuencia asumida: dos nombres realmente distintos que produzcan el mismo slug —`C++` y
`C#`, que al quitar los símbolos quedan los dos en `c`— se colapsan también. Quien necesite
separarlos crea la segunda a mano dándole un slug propio; ahí sí se avisa de que el slug
está ocupado en vez de decidir por nadie.

Un nombre sin ninguna letra latina (`###`, solo emoji) no deja segmento de URL: en ese caso
el slug se deriva del propio nombre (`tag-5c5b8251`). No es bonito, pero es estable, no
choca con nada y no impide guardar el artículo.

---

## El cuerpo: `body_blocks`, y solo `body_blocks`

Una lista ordenada de bloques (párrafos, imágenes, citas, vídeo, divisoria, muro de pago).
Tratar el contenido como estructura y no como una sopa de HTML es lo que permite
renderizarlo distinto según el contexto y validar cada pieza por separado.

Cada bloque tiene tres campos:

```json
{ "id": "identificador único dentro del artículo",
  "type": "text | quote | image | gallery | video | paywall | divider",
  "config": { … lo que necesite ese tipo … } }
```

El HTML de dentro de un bloque **se sanea al escribir**, no al leer. Es la frontera de
confianza: lo que está guardado ya es seguro de emitir, y quien lo sirva no tiene que
volver a limpiarlo ni acordarse de hacerlo.

### Hubo una segunda columna `body`, y sobraba

Guardaba el cuerpo como HTML plano. Se retiró porque:

- Estaba a **NULL en las 60 filas** y nada la escribía.
- **La salida pública renderiza bloques.** Un texto que aterrizara solo en `body` no se
  vería en ninguna parte. No era una rampa para importar contenido antiguo: era un sitio
  donde perderlo en silencio.

Quien importe de otro sistema **convierte a bloques en la frontera de entrada**. Guardar dos
representaciones del mismo cuerpo obliga para siempre a decidir cuál manda.

---

## Los cuatro estados

| Estado | Significa |
|---|---|
| `draft` | Se está escribiendo. Sin URL pública. |
| `review` | Terminado y entregado, esperando decisión. |
| `scheduled` | Aprobado, saldrá en su `publish_at`, que aquí es una fecha futura. |
| `published` | Publicado. Es el único con `url_date`. |

Es una columna `ENUM`, así que la lista la impone la base y no solo el código: un valor
inventado no entra ni por la API ni por un `UPDATE` a mano. El orden de arriba es el de
declaración, y por tanto el que usa `ORDER BY status` — un listado ordenado por estado
recorre la vida de la pieza en vez de ordenar alfabéticamente.

Los dos últimos exigen el permiso de publicación. Programar cuenta como publicar: solo
cambia el reloj, y quien firma sigue sin decidir cuándo sale.

`review` existe porque sin él «borrador» significaba dos cosas incompatibles: «lo estoy
escribiendo» y «tomad, ya está». Sin esa distinción, quien no puede publicar no tiene forma
de decir que ha terminado.

### Una sola fecha de publicación

`publish_at` es el instante en que la pieza sale, y `status` dice si eso es una promesa o un
registro:

```
status = scheduled   →   publish_at en el futuro   = plan
status = published   →   publish_at ya pasó        = hecho
```

Aquí había **dos** columnas, una para lo previsto y otra para lo efectivo, y al llegar la
hora había que copiar la primera en la segunda y vaciar la primera. Ese traspaso llegó a
publicar piezas con la fecha en que se redactaron, descolocándolas en la portada, el sitemap
y el feed. Con una sola columna no hay nada que copiar: publicar un programado es **cambiar
de estado y nada más**, y la fecha ya es la correcta.

Tampoco se perdió información al fundirlas: las dos nunca convivían con sentido, porque el
traspaso borraba la prevista en el momento de usarla.

**Publicar a mano algo programado a futuro sella la hora actual.** Dejarle la fecha prevista
lo ordenaría mañana en una portada donde ya está hoy.
