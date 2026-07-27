# Base de datos de Kilkana

Documentación de la base de datos de un periódico digital: qué guarda cada tabla, qué
significa cada columna y qué reglas se cumplen sobre los datos.

Se explica sola. No presupone conocer el código que la usa ni ninguna aplicación cliente:
lo que hay aquí son tablas, columnas, relaciones e invariantes. Cuando una regla no la
impone la base de datos sino el código que escribe en ella, se dice explícitamente —esa
distinción es justo lo que necesita saber quien vaya a tocar algo.

## Índice

| | Documento | Tablas |
|---|---|---|
| 1 | [Cuentas y permisos](01-cuentas-y-permisos.md) | `users` · `roles` · `permissions` · los tres pivotes |
| 2 | [Firmas](02-firmas.md) | `kilkana_authors` |
| 3 | [Secciones](03-secciones.md) | `kilkana_categories` |
| 4 | [Artículos](04-articulos.md) | `kilkana_posts` · `kilkana_post_slugs` · `kilkana_post_revisions` · `kilkana_post_views` · `kilkana_tags` · `kilkana_post_tag` |
| 5 | [Biblioteca de medios](05-medios.md) | `kilkana_media` · `kilkana_media_tags` · `kilkana_media_collections` · sus dos pivotes |
| 6 | [Comentarios y suscriptores](06-comentarios-y-suscriptores.md) | `kilkana_comments` · `kilkana_subscribers` |
| 7 | [Composición y ajustes](07-composicion-y-ajustes.md) | `kilkana_layouts` · `kilkana_layout_revisions` · `kilkana_settings` |

## Una base de datos por periódico

Cada periódico vive en su **propia base de datos**, con su propio juego completo de tablas:
sus cuentas, sus artículos, sus medios. No hay ninguna columna que identifique al periódico
dentro de una fila, porque no hace falta: el aislamiento es la base de datos entera.

Consecuencia práctica: un `id` solo tiene sentido dentro de su base. El artículo 42 de un
periódico no guarda relación con el artículo 42 de otro.

## Mapa de relaciones

```
users ──1:1── kilkana_authors ──1:N── kilkana_posts ──N:1── kilkana_categories ──┐
  │                  │                     │                                    │
  │                  │ avatar              │ portada                       parent_id
  │                  ▼                     ▼                              (autorreferencia,
  │            kilkana_media ◄─────────────┘                               dos niveles)
  │                  │
  │                  ├──N:M── kilkana_media_tags
  │                  └──N:M── kilkana_media_collections
  │
  ├── created_by / updated_by ──► kilkana_posts
  ├── uploaded_by ──────────────► kilkana_media
  └── user_id ──────────────────► kilkana_comments

kilkana_posts ──1:N── kilkana_post_slugs       (URLs viejas → redirección)
              ──1:N── kilkana_post_revisions   (historial completo)
              ──1:N── kilkana_post_views       (audiencia por día)
              ──1:N── kilkana_comments         (hilo por parent_id)
              ──N:M── kilkana_tags

kilkana_layouts ──1:N── kilkana_layout_revisions

Sin relaciones: kilkana_settings · kilkana_subscribers
```

## Convenciones que se repiten en todas las tablas

**Claves.** `id` es siempre `bigint unsigned` autoincremental. Las claves foráneas se
llaman `<tabla_en_singular>_id` y apuntan al `id` de esa tabla.

**Orden de las columnas.** El orden **no afecta al rendimiento**: es documentación. Manda en
`SELECT *`, en `DESCRIBE`, en los volcados y en los diffs, así que una tabla se lee de arriba
abajo como se lee una ficha. Al crear una tabla nueva, este es el orden:

```
1 · id
2 · claves foráneas de contenido      a qué pertenece la fila
3 · texto principal                   lo que la fila dice, y su forma para URL
4 · estado                            en qué punto está
5 · contenido largo                   cuerpo, JSON, blobs
6 · referencias externas              lo que apunta fuera de esta fila
7 · banderas                          featured, destacados
8 · claves foráneas de autoría        created_by, updated_by
9 · TODAS las fechas                  date, timestamp y al final timestamps()
```

Dos reglas que valen más que la lista:

- **Ninguna marca temporal en el medio.** Es el criterio que más cambia cómo se lee una tabla:
  una fecha suelta entre el texto y el cuerpo obliga a saltarla cada vez. Todas juntas al
  final, y dentro del bloque de lo editorial a lo automático — primero las que decide una
  persona (`url_date`, `publish_at`), después las que escribe el sistema
  (`created_at`, `updated_at`).
- **Las foráneas van agrupadas y alfabéticas dentro de su grupo** (`author_id`, `category_id`,
  `cover_media_id`; luego `created_by`, `updated_by`). Son dos grupos distintos y a propósito:
  las de contenido dicen qué es la fila, las de autoría solo quién la tocó.

Los índices, únicos y `fullText` van **después de todas las columnas**, no intercalados.

El slug va en el grupo 3, pegado al texto del que se deriva, y no con lo que apunta fuera:
`slug` es la dirección propia y `canonical_url` señala la de otro, así que juntarlos porque
los dos «son URLs» sería agrupar por tipo en vez de por lo que significan.

> Sabida y asumida: agrupar por significado puede separar dos columnas que forman una misma
> clave. En `kilkana_posts`, `slug` está en el grupo 3 y `url_date` en el 9, y juntas son el
> índice único que resuelve la URL. La declaración del `unique(['url_date', 'slug'])` al final
> es lo que las vuelve a unir; si una tabla nueva depende de una pareja así, conviene que su
> índice sea lo primero que se lea después de las columnas.

**Fechas.** `created_at` y `updated_at` son `timestamp` nulos en toda tabla que los tenga;
se rellenan solos al insertar y actualizar. Los `timestamp` guardan fecha y hora
(`2026-06-28 20:00:00`), los `date` solo fecha (`2026-06-28`). Un `timestamp` nulo no
significa «no se sabe» sino «todavía no ha ocurrido» — cada columna documenta el matiz.

**Estados.** Un estado es una lista cerrada y se declara como tal: la columna es un `ENUM` y
sus valores **salen de una enumeración del código**, que es la única definición. La migración
lee esa lista para declarar la columna, así que la base y el código no pueden discrepar, y
añadir un estado es añadir un caso.

De ahí salen tres cosas que conviene saber:

- **La base rechaza lo que no está en la lista.** Un `UPDATE` a mano con un valor inventado
  falla con `Data truncated for column` y la fila no se mueve. ⚠️ Esa garantía **depende de
  que `sql_mode` incluya `STRICT_TRANS_TABLES`**: sin modo estricto, MariaDB no da error,
  guarda la cadena vacía y avisa. Merece la pena comprobarlo antes de confiar en la columna.
- **El orden de declaración es el orden de ordenación.** `ORDER BY status` sigue el orden en
  que se declararon los valores, no el alfabético. Declararlos en orden de **ciclo de vida**
  hace que un listado ordenado por estado se lea de principio a fin de la vida de la fila.
- **Añadir un valor al final es barato; insertarlo en medio, no.** Al final se puede hacer
  sin reconstruir la tabla; en medio se renumeran los existentes.

Y una trampa para más adelante: que la migración importe la enumeración es lo que impide que
las dos listas se separen, pero solo funciona mientras las migraciones se puedan reescribir.
El día que el esquema se congele y una migración sea historia inmutable, ejecutarla
reproducirá la lista de **hoy**, no la que había cuando se escribió. Al llegar ahí, hay que
fijar los valores a mano en la migración vieja.

Las dos derivaciones que salen de la enumeración —la lista para la migración y la regla `in:`
de la API— son idénticas en todos los estados, así que viven una sola vez en un trait
compartido (`Enums\Concerns`). Lo que **no** entra ahí es lo que cada estado significa
—«¿esto exige permiso de publicación?», «¿esto lo ve un lector anónimo?»—: eso es propio de
cada enumeración.

> Todavía en `varchar(16)`: `kilkana_comments.status` y `kilkana_subscribers.status`. Les
> aplica lo de arriba cuando se toquen — hoy sus valores válidos solo los comprueba el código
> que escribe, así que un `UPDATE` a mano puede meter un estado que nadie sepa interpretar.

**Reglas de borrado.** Las claves foráneas usan tres políticas y cada una dice algo:

- `RESTRICT` — no se puede borrar el destino mientras alguien lo referencie. Está donde
  borrar dejaría huérfano algo que ya se publicó.
- `CASCADE` — borrar el destino borra las filas que cuelgan de él. Está donde la fila hija
  no significa nada por separado.
- `SET NULL` — la referencia se anula y la fila sobrevive. Está en la autoría: el artículo
  no desaparece porque se dé de baja a quien lo escribió.

**Transacciones.** El criterio no es cuántas tablas se tocan, sino si forman **un agregado**:
un conjunto que se guarda y se lee como una unidad y tiene una raíz. Aquí hay cuatro.

| Raíz | Lo que arrastra |
|---|---|
| Artículo | `kilkana_posts` · `post_revisions` · `post_tag` · `post_slugs` |
| Medio | `kilkana_media` · sus dos pivotes de taxonomía |
| Composición | `kilkana_layouts` · `layout_revisions` |
| Sección · Firma · Comentario · Suscriptor · Ajustes | cada una sola |

De ahí salen tres reglas:

1. **Una operación de negocio, una transacción**, y la abre quien conoce la operación entera.
   Guardar un artículo escribe en cuatro tablas y es **una** transacción porque es **un**
   agregado, no porque sean cuatro tablas. Una escritura suelta ya es atómica y no necesita
   nada.
2. **Un agregado por transacción.** Cuando una operación toca dos, no se envuelven juntos: se
   guarda el principal y el otro queda como mejor-esfuerzo **con una señal visible**. Es lo que
   pasa al dar de alta una cuenta con permiso de escritura: la cuenta se guarda aunque su firma
   no se pueda crear, y la respuesta lo dice (`byline_pending`). Guardar un usuario no puede
   fallar por su URL pública.
3. **Dentro no entra nada que no sea base de datos.** Ni ficheros, ni peticiones a otros
   servicios, ni correo. No se deshacen con un `ROLLBACK`, y mientras la transacción está
   abierta los bloqueos de fila siguen puestos: meter dentro la subida de un fichero es tener
   la fila bloqueada lo que tarde el disco. Los efectos van **después** de confirmar.

Dos excepciones asumidas, las dos por el mismo motivo —el avance parcial vale más que el
todo-o-nada—: **el reemplazo de un fichero** de la biblioteca, que se ordena en vez de
envolverse (escribe el nuevo · apunta la fila · borra el viejo, para que un fallo deje basura
en disco y nunca una referencia rota); y **la publicación programada**, que va pieza a pieza
para que una rota no impida salir a las demás ese minuto — vuelve a intentarlo al siguiente.

**Datos estructurados.** Varias columnas guardan JSON dentro de un `longtext`. La base no
valida su forma; cada documento describe qué estructura espera.

**Cotejamiento.** Las comparaciones de texto ignoran mayúsculas y acentos. `'Política'`,
`'politica'` y `'POLÍTICA'` son el mismo valor para un `WHERE` o un índice único. Esto
evita duplicados por variantes de escritura, pero también significa que **no se pueden
guardar dos filas que solo se distingan por una tilde** en una columna única.
