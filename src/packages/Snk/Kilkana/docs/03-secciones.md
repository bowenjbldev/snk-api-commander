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
| `parent_id` | `kilkana_categories.id` | **SET NULL** |

Borrar una madre **no borra a sus hijas**: las promociona a raíz. Es deliberado — perder
una mesa no puede llevarse por delante los artículos de sus subsecciones.

---

## Solo dos niveles

No lo impone la base de datos: `parent_id` admitiría un árbol de profundidad arbitraria. Lo
impone la regla de uso, y de ella depende cómo se consulta:

> **Una sección con hijas no puede tener madre.**

Gracias a eso, «los artículos de Deportes» se resuelve mirando la sección del artículo y la
madre de esa sección: dos saltos y se acabó. Con tres niveles, esa consulta se rompería en
silencio y devolvería de menos.

## Una sección incluye a sus subsecciones

Pedir «Deportes» devuelve también Fútbol, Baloncesto y Ciclismo. Quien pulsa una mesa
espera lo que hay debajo.

Consecuencia asumida: una portada con un módulo de «Deportes» y otro de «Fútbol» repetirá
artículos. Los módulos no se descuentan entre sí.

## Borrar una sección se decide con su cuenta PROPIA

`kilkana_posts.category_id` es `RESTRICT`: no se puede borrar una sección que tenga
artículos asignados **directamente**. Pero el conteo que se le enseña a alguien antes de
borrar debe ser el propio, no el total con descendientes: la clave foránea solo protege lo
asignado directo, así que un total incluiría piezas de las hijas que no impedirían el
borrado y el aviso mentiría en la dirección peligrosa.

## Una subsección necesita que algo la enlace

Una hija sin ningún enlace entrante es una página huérfana: existe, se puede anunciar en un
sitemap y nadie llega a ella. La jerarquía tiene que ser visible en alguna parte —una lista
de hijas bajo el título de la madre, un rastro de migas en el artículo— o el árbol solo
existe en esta tabla.

## `slug` es único en todo el periódico

También entre niveles: no puede haber una raíz `motor` y una hija `motor`. Cada sección
ocupa un segmento de URL distinto, así que la URL de una sección no dice a qué nivel
pertenece. La jerarquía se cuenta con el rastro de migas y los datos estructurados, no con
la forma de la URL.
