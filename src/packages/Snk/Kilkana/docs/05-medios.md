# 5 · Biblioteca de medios

`kilkana_media` y su taxonomía. Los ficheros que publica el periódico: fotos de portada,
retratos de las firmas, vídeo.

Un medio es un **fichero del periódico con sus metadatos**, no una URL remota apuntada.
Esa distinción es la que permite guardar quién tiene los derechos, hasta cuándo y cómo se
recorta.

---

## `kilkana_media` — el fichero

### Identidad y ubicación

| Columna | Tipo | Nulo | Qué es |
|---|---|---|---|
| `id` | bigint unsigned | no | Clave primaria. |
| `uploaded_by` | bigint unsigned | sí | Quién lo subió. SET NULL. |
| `url` | varchar(2048) | no | Dónde se sirve. |
| `url_hash` | char(40) | no · **único** | Huella SHA-1 de la URL. Existe porque una `varchar(2048)` no cabe en un índice único; es lo que impide dar de alta dos veces la misma URL. La calcula el código al guardar, no la base. |
| `content_hash` | char(64) | sí · **único** | Huella SHA-256 del **contenido**. Dos subidas del mismo fichero con distinto nombre son un solo medio. Nulo en un medio que solo es una URL remota. |
| `original_name` | varchar(500) | sí | Nombre del fichero al subirlo. |
| `disk` | varchar(32) | sí | Almacenamiento donde vive. |
| `path` | varchar(1024) | sí | Ruta dentro de ese almacenamiento. |

### Propiedades del fichero

| Columna | Tipo | Nulo | Qué es |
|---|---|---|---|
| `mime` | varchar(64) | sí | Tipo de contenido (`image/jpeg`, `video/mp4`…). |
| `size_bytes` | bigint unsigned | sí | Tamaño. |
| `width` · `height` | int unsigned | sí | Dimensiones reales en píxeles. |
| `duration` | int unsigned | sí | Duración en segundos. Solo audio y vídeo. |

`width` y `height` no son decorativos: publicar una imagen sin sus dimensiones intrínsecas
hace que la página salte mientras carga.

### Lo editorial

| Columna | Tipo | Nulo | Qué es |
|---|---|---|---|
| `title` | varchar(500) | sí | Título con el que se busca en la biblioteca. |
| `alt` | varchar(500) | sí | Texto alternativo: qué se ve, para quien no puede verlo. |
| `caption` | varchar(500) | sí | Pie de foto publicable. |
| `description` | text | sí | Notas internas más largas. |
| `credit` | varchar(500) | sí | Autoría de la imagen. Se publica. |

### Derechos

| Columna | Tipo | Nulo | Qué es |
|---|---|---|---|
| `source_url` | varchar(2048) | sí | De dónde salió. Procedencia, no origen de servicio. |
| `rights_status` | varchar(32) | no · def. `unknown` | Situación legal del medio. |
| `license` | varchar(500) | sí | Licencia concreta. |
| `rights_expires_at` | date | sí | Cuándo caduca el derecho de uso. Nulo = no caduca. |

Hay índice sobre `rights_expires_at`: la pregunta «qué se me caduca» tiene que poder
responderse antes de que caduque, no después. Solo la fecha, y más abajo se explica por qué
llevar el estado delante lo dejaba inservible.

### Encuadre y borrado

| Columna | Tipo | Nulo | Qué es |
|---|---|---|---|
| `focal_x` · `focal_y` | decimal(5,4) | no · def. 0.5 | Punto de interés, de 0 a 1. `0.5, 0.5` = el centro. |
| `deleted_at` | timestamp | sí | Borrado lógico. **Nulo = vivo.** |
| `created_at` · `updated_at` | timestamp | sí | Alta y última modificación. |

El punto focal decide qué se conserva al recortar la misma foto a proporciones distintas.
Sin él, un recorte cuadrado de un retrato apaisado decapita al retratado.

`deleted_at` es borrado **lógico**: la fila sobrevive y se puede restaurar. Un medio se usa
desde muchos sitios, así que su desaparición no puede ser inmediata e irreversible. Toda
consulta de la biblioteca debe filtrar `deleted_at IS NULL` salvo que quiera ver la papelera.

---

## Dos huellas, dos preguntas distintas

| | Responde a |
|---|---|
| `url_hash` | ¿Ya tengo dada de alta esta URL? |
| `content_hash` | ¿Ya tengo este fichero, aunque llegue con otro nombre? |

La segunda es la que evita que la biblioteca se llene de copias: subir tres veces la misma
foto crea un medio, no tres.

---

## La taxonomía: etiquetas y colecciones

**`kilkana_media_tags`** — `id`, `name` varchar(120) **único**, timestamps.
**`kilkana_media_collections`** — `id`, `name` varchar(120) **único**, timestamps.

Dos pivotes, ambos con único compuesto y CASCADE por los dos lados:

- `kilkana_media_media_tag` — `media_id` + `media_tag_id`.
- `kilkana_media_collection_media` — `media_id` + `media_collection_id`.

### No son las etiquetas de los artículos

`kilkana_media_tags` y `kilkana_tags` (documento 4) son **vocabularios distintos**:

| | Responde a | Ejemplos |
|---|---|---|
| Etiqueta de artículo | ¿De qué **habla** la noticia? | `elecciones`, `sequía` |
| Etiqueta de medio | ¿Qué **es** este fichero? | `apaisado`, `primer plano`, `nocturna`, `archivo` |

La distinción no es cosmética. La misma foto de un pleno sirve para una pieza de política y
para otra de vivienda: no cambia de naturaleza al reutilizarse, así que etiquetarla con el
tema de quien la usó primero la deja mal descrita para el segundo uso. Un tag de medio tiene
que seguir siendo cierto dentro de diez años y de cualquier artículo.

Tres orígenes, y conviene saber cuál es cuál:

1. **Del fichero** — proporción, formato, resolución. Se calculan; no se escriben a mano.
2. **Del uso** — `portada` dice que esa imagen abre una pieza. Es un hecho del fichero.
3. **De la mirada** — `primer plano`, `nocturna`, `con personas`. **No hay forma de
   derivarlos**: los pone quien ve la foto. Ningún proceso automático puede rellenarlos, y
   un proceso que lo intente acabará copiando algo que no toca.

### Aquí se copiaba el vocabulario editorial

`kilkana_media_tags` llegó a tener las **mismas 151 filas** que `kilkana_tags`, y las
colecciones eran los **18 nombres de sección**. Dos tablas con contenido idéntico: parecían
una duplicación inútil del esquema cuando el problema estaba en quién las rellenaba.

Lo hacían dos sitios —la siembra inicial y una tarea de mantenimiento que además corría
sobre **todos** los periódicos aprovisionados—, y la tarea sigue existiendo: enriquece
título, texto alternativo, pie y derechos desde el artículo que usa la foto, que sí son
datos que el artículo conoce. Lo que ya no hace es inventar el vocabulario.

### Qué es una colección

Un **lote**: el encargo, la cobertura o la remesa con la que entró el material. «Elecciones
2026», «Incendios de agosto». Se archiva por cuándo y para qué se pidió, no por dónde acabó
publicándose — una misma foto puede salir en tres secciones sin dejar de ser del mismo
encargo.

---

## Un medio está en uso de TRES formas

A `kilkana_media` apuntan tres claves foráneas, y las tres cuentan:

| Quién | Columna | Para qué |
|---|---|---|
| Artículo | `cover_media_id` | portada |
| Artículo | `og_image_id` | imagen para redes |
| Firma | `avatar_media_id` | retrato |

Las tres son `SET NULL`: borrar un medio no borra lo que lo usaba, le quita la foto.

Eso es justo lo que hace importante contarlas todas. Se contaba solo la portada, así que la
imagen social de un artículo publicado y el retrato de un periodista aparecían **como «sin
usar», con el contador a cero** — y esa es la vista con la que se hace limpieza. La
biblioteca ofrecía para borrar precisamente las fotos que sí estaban publicadas, y como la
clave es `SET NULL`, borrarlas no daba ningún error: el artículo se quedaba sin imagen
social y el periodista sin cara, en silencio.

Si algún día aparece una cuarta referencia a un medio, tiene que entrar en esa cuenta. Un
medio «sin usar» que en realidad se está publicando es peor que no tener la vista.

---

## Índices: los que se usan de verdad

| Índice | Lo que sostiene |
|---|---|
| `(deleted_at, created_at)` | **toda** lectura de la biblioteca |
| `rights_expires_at` | qué derechos caducan pronto |
| `url_hash` · `content_hash` | los dos dedup |

El primero existe porque el borrado lógico añade `deleted_at IS NULL` a cada consulta sin
que se vea en el código, y el listado ordena por fecha de alta. Sin él, cada carga de la
biblioteca recorría la tabla entera y ordenaba a mano; con él, la base resuelve filtro y
orden con el propio índice.

El segundo fue `(rights_status, rights_expires_at)`, y no servía para lo único que consulta
la caducidad: un filtro por fecha que no toca el estado. En un índice compuesto solo se
puede aprovechar el prefijo por la izquierda, así que con el estado delante la fecha no se
alcanzaba. El estado sí se filtra por su cuenta, pero tiene cuatro valores contados y ahí un
índice no aporta.

> Detalle que anula un índice sin avisar: envolver la columna en una función —`whereDate`,
> `DATE(...)`, `LOWER(...)`— impide que la base lo use. Como `rights_expires_at` ya es de
> tipo fecha, se compara en crudo.

---

## Lo que se edita aquí tiene que LLEGAR al público

Un medio guarda `alt`, `caption`, `credit` y el punto focal precisamente para que se
publiquen. Si quien pinta una foto recalcula el texto alternativo con el titular del
artículo o ignora el encuadre, esos campos son trabajo que nadie ve: la biblioteca los
recoge y se quedan dentro. Campo nuevo de un medio que deba verse = hay que tocar las dos
mitades, o nace muerto.
