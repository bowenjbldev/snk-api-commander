# Secciones

`kilkana_categories` — las mesas del periódico y sus subsecciones.

Un árbol de **exactamente dos niveles**: secciones madre (Deportes, Economía) e hijas
(Fútbol, Vivienda). Cada artículo pertenece a una y solo una.

---

## Columnas

| Columna | Tipo | Nulo | Qué es |
|---|---|---|---|
| `id` | bigint unsigned | no | Clave primaria. Es lo que referencia `kilkana_posts.category_id`. |
| `parent_id` | bigint unsigned | sí | Sección madre. **Nulo = es una sección raíz.** |
| `name` | varchar(255) | no | Nombre visible. |
| `slug` | varchar(64) | no · **único** | Segmento de la URL pública de la sección. Único en todo el periódico, también entre niveles. |
| `seo_title` | varchar(500) | sí | Titular para buscadores. **Nulo = se usa `name`.** |
| `seo_description` | text | sí | Descripción para buscadores, unos 155 caracteres. **Nulo = se usa `description`.** |
| `status` | enum | no · def. `draft` | Si existe para el lector: `draft` (en preparación) · `live` (lanzada) · `hidden` (retirada del menú, alcanzable por su URL). |
| `description` | text | sí | Descripción visible en la página de la sección. |
| `position` | smallint unsigned | no | Orden dentro de su nivel. Las hermanas se ordenan entre ellas, no contra el resto del árbol. |
| `noindex` | boolean | no · def. `false` | Pide a los buscadores que no la indexen. La sección sigue existiendo y navegable. |
| `created_at` · `updated_at` | timestamp | sí | Alta y última modificación. |

## Relaciones

| | Apunta a | Al borrar el destino |
|---|---|---|
| `parent_id` | `kilkana_categories.id` | **RESTRICT** |

Una sección con subsecciones **no se puede borrar**, igual que no se puede borrar una que
tenga artículos. Aquí fue `SET NULL`, que ascendía las hijas a raíz: borrar «Deportes»
colocaba Fútbol, Baloncesto, Ciclismo y Motor en la portada sin avisar a nadie. Vaciar el
árbol es ahora una decisión explícita y en el orden que uno elija.

---

## Solo dos niveles

`parent_id` admitiría por sí solo un árbol de profundidad arbitraria, así que la regla se
impone al guardar, en dos comprobaciones:

> **Tu madre tiene que ser una raíz** — y, en el otro sentido, **si ya tienes hijas no
> puedes colgar de nadie.**

Con la primera los ciclos tampoco caben: cerrar uno exigiría que las dos secciones tuvieran
madre. La segunda tapa la puerta de atrás — mover una sección con hijas bajo otra creaba el
tercer nivel sin que la primera lo viera.

**No es una restricción de la base porque no puede serlo**: MariaDB no admite subconsultas
en un `CHECK`, así que «tu madre no puede tener madre» no se puede escribir en SQL. Vive en
el modelo, que es el punto por el que pasan también el seeder y la consola.

De esa regla depende cómo se consulta: «los artículos de Deportes» se resuelve mirando la
sección del artículo y la madre de esa sección, dos saltos y se acabó. Cuando el tercer
nivel era posible, esa consulta no fallaba — **devolvía de menos**, y los artículos de la
nieta desaparecían del listado de la abuela sin un solo error.

## Una sección incluye a sus subsecciones

Pedir «Deportes» devuelve también Fútbol, Baloncesto y Ciclismo. Quien pulsa una mesa
espera lo que hay debajo.

Consecuencia asumida: una portada con un módulo de «Deportes» y otro de «Fútbol» repetirá
artículos. Los módulos no se descuentan entre sí.

## Borrar una sección: dos cosas la protegen

`kilkana_posts.category_id` y `kilkana_categories.parent_id` son las dos `RESTRICT`. Una
sección se borra solo cuando no tiene **ni artículos propios ni subsecciones**.

El conteo que se enseña antes de borrar debe ser el de artículos **propios**, no el total
con descendientes: la clave foránea solo protege lo asignado directo, así que un total
incluiría piezas de las hijas que no impedirían el borrado y el aviso mentiría en la
dirección peligrosa.

Y conviene contar las dos cosas **antes** de intentarlo, no descubrirlas por el error: la
base informa solo del primer obstáculo con el que tropieza, así que quien vacíe los
artículos para luego chocar con las subsecciones hace el viaje dos veces.

## Una subsección necesita que algo la enlace

Una hija sin ningún enlace entrante es una página huérfana: existe, se puede anunciar en un
sitemap y nadie llega a ella. La jerarquía tiene que ser visible en alguna parte —una lista
de hijas bajo el título de la madre, un rastro de migas en el artículo— o el árbol solo
existe en esta tabla.

## La sección se presenta a los buscadores por su cuenta

**La página de una sección es de las que más posicionan** en un periódico: «Deportes»
compite por el nombre del deporte, no por una noticia concreta. Por eso tiene tres columnas
propias, con la misma forma que las de un artículo:

- `seo_title` y `seo_description` cuando el nombre y el texto visible no sirven tal cual.
- `noindex` para una mesa que existe y se navega pero no quiere salir en el buscador.

Los dos primeros resuelven de paso una confusión que arrastraba `description`: hacía de texto
visible **y** de descripción para buscadores, y son dos escrituras distintas —una tiene que
leerse bien debajo del título, la otra caber en unos 155 caracteres y contener lo que alguien
teclea—. Ahora `description` es lo visible y `seo_description` lo otro; nulo significa
«usa el visible», así que quien no toque nada sigue teniendo lo de antes.

Lo que **no** tiene es imagen social ni canónica propia. Una sección compartida en redes sale
sin foto. Se deja fuera a propósito hasta que alguien la pida: son dos columnas más y una
decisión de producto, no un descuido.

## Una mesa se prepara antes de abrirla

`status` es lo que permite representar el trabajo real de una redacción: se crea la sección,
se le van asignando piezas, y se lanza cuando hay con qué abrirla. Antes no cabía —o existía
y salía ya en la navegación, o no existía—.

Una sección **nace en `draft`** y de ahí no sale sola. Mientras lo esté:

- No aparece en el catálogo público, así que no se navega ni se enlaza.
- Admite borradores, que es justo para lo que sirve.
- **No admite piezas publicadas**: intentar publicar en ella responde 422.

Y la vuelta también está cerrada: **no se devuelve a preparación una sección con publicados**
—**suyos o de sus subsecciones**, porque el árbol tiene dos niveles y la madre de una sección viva
no puede estar en preparación—,
porque se llegaría al mismo sitio por la puerta de atrás —lanzarla, publicar, retirarla— y
esas piezas quedarían colgando de una mesa que el lector no puede abrir, con su rótulo
enlazando a una página que ya no existe. El error dice cuántas hay que mover o despublicar.

## Retirarla del menú es otra cosa, y sí se puede con el archivo lleno

`hidden` es la salida que `draft` no puede ser: la sección **desaparece de la navegación y
sigue abriéndose por su URL**. Es lo que necesita una mesa que se deja de ofrecer sin romper
lo que ya está enlazado —sus piezas siguen respondiendo, y su página también—, y por eso el
muro de arriba no la alcanza: pasar de `live` a `hidden` con cuarenta publicados es legítimo,
y publicar en una oculta también.

De ahí que el modelo tenga **dos scopes y no uno**, y confundirlos publica en el menú lo que se
retiró de él:

| Scope | Quién lo pregunta |
|---|---|
| `inMenu()` | El catálogo público: la navegación del periódico. Solo `live`. |
| `reachable()` | La página de la sección, su redirección de slug antiguo y el muro que exige sección lanzada para publicar. `live` y `hidden`. |

Los dos **le preguntan al enum** (`inMenu()` · `isPublic()`) en vez de enumerar los valores: con la
lista escrita en el modelo, un estado nuevo entra en el enum y los scopes siguen contestando lo de
ayer sin que nada falle.

**Y el estado no sale a la calle.** `GET public/categories` y `GET public/categories/{slug}`
esconden `status` y `position`: que la respuesta exista ya dice que la sección es alcanzable, y
decir con cuál de los dos estados lo es le cuenta a cualquiera qué retiró la redacción del menú a
propósito. Con dos estados eso no decía nada, porque todo lo público era `live`.

**Revertir la migración se niega si queda alguna oculta.** Las dos salidas automáticas mienten —a
`live` publica en el menú lo que alguien retiró a mano; a `draft` retira de la calle URLs que
respondían, y además choca con el muro de arriba—, así que el `down()` aborta diciendo cuántas hay.
El motivo mecánico refuerza al semántico: el DDL de MariaDB no es transaccional, así que un `UPDATE`
masivo seguido de un `ALTER` que falle deja lo peor de las dos.

Cuatro cosas parecidas que conviene no confundir:

| | Significa |
|---|---|
| `status = draft` | Todavía **no se ve**. |
| `status = hidden` | **Se ve por su URL**, y no está en el menú. |
| `noindex` | **Se ve y se enlaza**, pero no se anuncia a los buscadores. |
| Sección `live` y vacía | Abierta y sin piezas aún — `motor` en el juego de datos. Estado legítimo. |

## Una sección no se ve más que su madre

Los tres estados están **ordenados** —`draft` < `hidden` < `live`— y de esa escala cuelga la regla
del árbol: una hija no puede ser más visible que la sección de la que cuelga. No es simetría por
gusto: el menú tiene dos niveles, así que **el de una hija se pinta dentro del desplegable de su
madre**; una hija `live` bajo una madre `hidden` no está en el menú de nadie, y bajo una `draft`
además cuelga de una página que responde 404.

Se comprueba en las **dos direcciones**, porque las dos escrituras llegan al mismo estado
imposible:

| Se escribe | Se rechaza si |
|---|---|
| `parent_id` | la madre nueva se ve menos que la sección que se mueve |
| `status` | queda alguna subsección más visible que el estado nuevo |

Y **falla cerrado**: si el estado de la madre no se puede resolver —un `parent_id` que apunta a
una fila que no existe— se rechaza en vez de dejar pasar. Ese caso llega hasta aquí porque la regla
`exists:` es del controlador y ni el seeder ni la consola pasan por él, y `guardTree` no lo caza:
su consulta devuelve `null` igual que la de una raíz.

Sin la primera, el muro del estado se rodea por la puerta de la jerarquía: `guardStatus` impide
devolver a preparación una sección con archivo, pero mover una sección viva **bajo** una en
preparación pasaba limpio y dejaba exactamente lo mismo. `guardTree` comprueba la forma —dos
niveles, sin ciclos— y no mira el estado de nadie; esto es lo que faltaba.

## Lo que el listado calcula por fila

`GET /categories` no devuelve la tabla pelada: el panel de secciones enseña «Local · 40 · 26 Aug»
y necesita tres cosas que no son columnas.

| Campo | De dónde sale | Por qué no lo calcula el cliente |
|---|---|---|
| `posts_count` | `withCount('posts')` | Contarlo fuera exige traerse el archivo entero: son decenas de miles de filas para pintar un número. |
| `last_published_at` | `withMax` sobre las **publicadas** | Un borrador de hoy no es actividad de la sección, y ordenar por él en el cliente exige lo mismo que arriba. |
| `retired_slugs` | La relación, de la última a la primera | La ficha enseña el 301 vigente; sin esto, el historial se guardaba y no había dónde consultarlo. |

Y el listado va **ordenado por `position` y desempatado por `id`**, que es el orden del menú.
`position` se numera **por nivel** —las hermanas de distinta madre comparten número—, así que
la lista plana mezcla niveles a propósito: quien la pinta reconstruye el árbol y dentro de cada
grupo de hermanas el orden relativo es el correcto.

Las tres viven en `newQuery()` del controlador y no en el modelo porque **solo las quiere quien
lista**: pedir una sección para editarla no necesita contar sus piezas.

## `kilkana_category_slugs` — las URLs que dejó atrás

| Columna | Tipo | Nulo | Qué es |
|---|---|---|---|
| `id` | bigint unsigned | no | Clave primaria. |
| `category_id` | bigint unsigned | no | Sección a la que apunta. **CASCADE.** |
| `path` | varchar(64) | no · **único** | El slug antiguo: `technology`. |
| `created_at` · `updated_at` | timestamp | sí | Alta y última modificación. |

De las tres tablas de este tipo, esta es la que más protege: **la página de una sección es la
que más enlaces externos acumula** en un periódico, porque compite por el nombre del tema y no
por una noticia concreta. Renombrar «Technology» a «Tecnología» sin esto tira todos esos
enlaces a la vez.

Funciona igual que la de artículos y la de firmas: al renombrar, la ruta que la sección deja
entra en el historial y la que adopta sale de él si era suya. **Una URL retirada no vuelve a
estar libre**, así que otra sección no puede adoptarla — recibe un 422.

La resolución entra por el punto de entrada por slug: pedir una sección devuelve la sección,
o la ruta actual si la pedida es una que dejó atrás, o nada. Igual que un artículo o una
firma.

**La redirección exige que la sección siga lanzada.** Una URL retirada cuya dueña volvió a
preparación no manda tráfico a ninguna parte — mismo criterio que en un artículo, donde el
301 exige que la pieza siga publicada. Una mesa que no existe para el lector tampoco la
recibe por detrás.

## `slug` es único en todo el periódico

También entre niveles: no puede haber una raíz `motor` y una hija `motor`. Cada sección
ocupa un segmento de URL distinto, así que la URL de una sección no dice a qué nivel
pertenece. La jerarquía se cuenta con el rastro de migas y los datos estructurados, no con
la forma de la URL.
