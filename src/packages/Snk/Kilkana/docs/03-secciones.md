# 3 · Secciones

`kilkana_categories` — las mesas del periódico y sus subsecciones.

Un árbol de **exactamente dos niveles**: secciones madre (Deportes, Economía) e hijas
(Fútbol, Vivienda). Cada artículo pertenece a una y solo una.

---

## Columnas

| Columna | Tipo | Nulo | Qué es |
|---|---|---|---|
| `id` | bigint unsigned | no | Clave primaria. Es lo que referencia `kilkana_posts.category_id`. |
| `parent_id` | bigint unsigned | sí | Sección madre. **Nulo = es una sección raíz.** |
| `slug` | varchar(64) | no · **único** | Segmento de la URL pública de la sección. Único en todo el periódico, también entre niveles. |
| `name` | varchar(255) | no | Nombre visible. |
| `description` | text | sí | Descripción de qué cubre. |
| `position` | smallint unsigned | no | Orden dentro de su nivel. Las hermanas se ordenan entre ellas, no contra el resto del árbol. |
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

## Dos huecos, anotados y sin decidir

Ninguno es un fallo: son cosas que la tabla **no tiene** y que conviene mirar con calma. Se
dejan escritas para que la próxima persona no tenga que redescubrirlas.

### Una sección es una página pública sin metadatos

Todo lo que tiene para presentarse es `name` y `description`. Un artículo, en cambio, lleva
ocho columnas para eso: titular y descripción de buscador, titular y descripción de redes,
imagen social, canónica, nivel de acceso y `noindex`.

La asimetría se nota porque **la página de una sección es de las que más posicionan** en un
periódico: «Deportes» compite por el nombre del deporte, no por una noticia concreta. Hoy no
puede tener un titular de buscador distinto de su nombre, ni imagen social propia, ni salir
del índice sin borrarla.

Hay además una pequeña confusión dentro de `description`: hace de texto visible en la página
**y** de descripción para buscadores. Son dos escrituras distintas —una tiene que leerse
bien debajo del título, la otra caber en unos 155 caracteres y contener lo que alguien
teclea— y comparten columna.

### No se puede preparar una sección sin publicarla

O existe y sale en la navegación, o no existe. Abrir una mesa nueva —crearla, irle asignando
piezas, lanzarla cuando haya suficiente— no tiene forma de representarse.

Ojo con no confundirlo con el caso de `motor`, que en el fixture nace **sin artículos a
propósito**: eso es una sección viva y vacía, un estado real y distinto del que falta.

La forma evidente es una bandera. Lo que hay que decidir es cuál de las dos significa: que
se vea pero no se anuncie a los buscadores —como el `noindex` de un artículo— o que no
aparezca siquiera en la navegación.

## `slug` es único en todo el periódico

También entre niveles: no puede haber una raíz `motor` y una hija `motor`. Cada sección
ocupa un segmento de URL distinto, así que la URL de una sección no dice a qué nivel
pertenece. La jerarquía se cuenta con el rastro de migas y los datos estructurados, no con
la forma de la URL.
