# Modelo de datos de Kilkana

Documentación de la base de datos de un periódico digital: qué guarda cada tabla, qué
significa cada columna y qué reglas se cumplen sobre los datos.

Vuelve al [mapa general de base de datos](../../database.md) o al
[índice de documentación](../../README.md).

Se explica sola. No presupone conocer el código que la usa ni ninguna aplicación cliente:
lo que hay aquí son tablas, columnas, relaciones e invariantes. Cuando una regla no la
impone la base de datos sino el código que escribe en ella, se dice explícitamente —esa
distinción es justo lo que necesita saber quien vaya a tocar algo.

## Índice

| | Documento | Tablas |
|---|---|---|
| 1 | [Cuentas y permisos](01-cuentas-y-permisos.md) | `users` · `roles` · `permissions` · los tres pivotes |
| 2 | [Firmas](02-firmas.md) | `kilkana_authors` · `kilkana_author_slugs` |
| 3 | [Secciones](03-secciones.md) | `kilkana_categories` · `kilkana_category_slugs` |
| 4 | [Artículos](04-articulos.md) | `kilkana_posts` · `kilkana_post_slugs` · `kilkana_post_revisions` · `kilkana_post_views` · `kilkana_tags` · `kilkana_post_tag` |
| 5 | [Biblioteca de medios](05-medios.md) | `kilkana_media` · `kilkana_media_tags` · `kilkana_media_collections` · sus dos pivotes |
| 6 | [Comentarios y suscriptores](06-comentarios-y-suscriptores.md) | `kilkana_comments` · `kilkana_subscribers` · `kilkana_reader_reads` |
| 7 | [Composición y ajustes](07-composicion-y-ajustes.md) | `kilkana_layouts` · `kilkana_layout_revisions` · `kilkana_themes` · `kilkana_settings` |
| 8 | [Actividad](08-actividad.md) | `kilkana_activity` · la papelera vive en el 4 y el 5 |
| 9 | [Puerta de salida](09-puerta-de-salida.md) | `kilkana_gate_results` |

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

kilkana_themes ◄──SET NULL── kilkana_settings (que guarda su copia del spec)

Sin relaciones: kilkana_subscribers
```

## Convenciones y excepciones reales

**Claves.** La convención para tablas nuevas es `$table->id()`: `bigint unsigned`
autoincremental, con las claves de negocio en columnas únicas. `kilkana_settings` es la
excepción histórica: usa un `unsigned tinyint` fijo, no autoincremental, y un `CHECK (id = 1)`.
No debe tomarse como patrón. Las claves foráneas se llaman `<entidad>_id` y apuntan a una PK
de la misma base tenant.

**Una tabla, una migración, y cada hija detrás de su padre.** El nombre del archivo dice
exactamente qué tabla crea —en singular, sin `_tables`— y el número la coloca justo después
de aquella de la que depende:

```
000001 categories          000008 authors             000014 tags
000002 category_slugs      000009 author_slugs        000015 post_tag
000003 media               000010 posts               000016 layouts
000004 media_tags          000011 post_slugs          000017 layout_revisions
000005 media_collections   000012 post_revisions      000018 settings
000006 media_media_tag     000013 post_views          000019 comments
000007 media_collection_media                         000020 subscribers
```

Así el directorio **se lee como el mapa de relaciones**: quien abra la carpeta ve de un
vistazo que una sección arrastra su historial de URLs y que un medio arrastra dos taxonomías
con sus dos pivotes. Agrupar varias tablas en un archivo escondía justo eso —hubo uno que
creaba cuatro— y obligaba a abrirlo para saber qué había dentro.

El orden es además el de las dependencias: una tabla no puede crearse antes que aquella a la
que apunta su clave foránea, así que agrupar por parentesco y respetar las claves son la
misma cosa.

**Orden de las columnas.** Para migraciones nuevas: PK, todas las FKs, negocio por relevancia y
fechas al final; índices y únicos después de declarar columnas. Es documentación para
`DESCRIBE`, volcados y diffs, aunque no cambie el rendimiento. El esquema actual conserva dos
desviaciones (`kilkana_media.uploaded_by` y el `user_id` de las revisiones aparecen después de
datos de negocio); no deben copiarse.

**Marcas de tiempo.** Las entidades editables llevan `created_at` y `updated_at`. Las revisiones
son inmutables y guardan solo `created_at`. `kilkana_post_views` y los pivotes
`kilkana_media_media_tag`, `kilkana_media_collection_media` y `kilkana_post_tag` no tienen
timestamps. Son excepciones expresas, no una regla general para tablas nuevas.

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

> Ya no queda ningún estado en `varchar`: los seis (`posts.status`, `posts.content_tier`,
> `media.rights_status`, `categories.status`, `comments.status`, `subscribers.status`) son
> `ENUM` con su enumeración.

**Reglas de borrado.** Las claves foráneas usan tres políticas y cada una dice algo:

- `RESTRICT` — no se puede borrar el destino mientras alguien lo referencie. Está donde
  borrar dejaría huérfano algo que ya se publicó.
- `CASCADE` — borrar el destino borra las filas que cuelgan de él. Está donde la fila hija
  no significa nada por separado.
- `SET NULL` — la referencia se anula y la fila sobrevive. Está en la autoría: el artículo
  no desaparece porque se dé de baja a quien lo escribió.

**URLs públicas: quien tiene slug, tiene historial.** Una entidad que se publica en una URL
propia —artículo, firma, sección— necesita **su tabla de rutas retiradas**, y esto es una
regla, no una opción:

| Entidad | Su historial | Ruta que guarda |
|---|---|---|
| `kilkana_posts` | `kilkana_post_slugs` | `2026/06/28/titular-viejo` |
| `kilkana_authors` | `kilkana_author_slugs` | `jorge-bowen` |
| `kilkana_categories` | `kilkana_category_slugs` | `tecnologia` |

Todas tienen la misma forma: `id`, la clave foránea a su dueño en **CASCADE**, `path`
**único**, y las marcas de tiempo. Guardan la ruta **de origen** y no la de destino: el
destino sale de la clave foránea, y por eso una URL vieja resuelve **en un salto** aunque
haya habido tres renombrados, sin encadenar redirecciones.

**Por qué una tabla por entidad y no una compartida.** Se valoró una sola tabla polimórfica
—una columna con el nombre del modelo, al estilo de los paquetes de Spatie—. Se descartó por
dos motivos. Una columna polimórfica **no admite clave foránea**, así que borrar una firma
dejaría filas apuntando a un id muerto y la regla de borrado pasaría de la base al código.
Y el conjunto de entidades con URL es **cerrado y pequeño**: el polimorfismo resuelve el caso
contrario, el de tipos abiertos y desconocidos. Además cada ruta tiene forma distinta —la del
artículo lleva la fecha delante— y compartir columna las obligaría a compartir espacio de
nombres sin necesitarlo.

**Añadir una entidad publicable = añadir su historial en el mismo cambio**, mediante una
migración propia inmediatamente posterior. No se debe aplazar la escritura de ese historial:
el momento del renombrado es el único en que existe la ruta vieja, y si nadie la guarda ahí,
se pierde para siempre. La regla que las tres comparten es que **una URL retirada no vuelve a
estar libre** — sigue redirigiendo, así que ninguna otra entidad puede adoptarla, y quien lo
intente recibe un 422.

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

**Asignación masiva.** Los modelos declaran `$guarded = []`, o sea que cualquier columna es
escribible desde una petición. **Eso solo es seguro mientras su controlador declare reglas de
validación**: sin reglas, el controlador base mete la petición entera en el modelo, y entonces
se puede escribir cualquier columna de la tabla. Pasó en el alta de medios, donde dejaba
inventar `disk` y `path` — justo lo que se usa para borrar ficheros del disco.

Es una dependencia implícita entre un modelo y su controlador que no se ve leyendo ninguno de
los dos. Controlador nuevo sobre un modelo con `$guarded = []` = reglas declaradas, o la ruta
no debería existir. Un modelo con `$fillable` no tiene esa dependencia y es la alternativa
cuando la lista de columnas escribibles es corta y estable.

**Correo.** Sale **síncrono dentro de la petición** y su fallo se registra y se traga: lo que
importaba —la fila— ya está guardado, así que un SMTP caído no puede convertir un alta correcta
en un `500`. En el caso público eso además protege la respuesta uniforme que impide que el
formulario delate quién está suscrito.

Techo conocido, y es el disparador para cambiarlo: con un proveedor real esto quiere cola. Hoy
el único worker consume `--queue=provisioning`, así que un job en la cola por defecto no lo
recogería nadie.

Todo enlace que salga en un correo se firma **contra el host del periódico**, no contra
`APP_URL`: el paquete se resuelve por Host, y la firma cubre el host. Un `signedRoute` normal
produce un enlace que ni resuelve el paquete ni valida la firma. En local, **Mailpit** (`:8025`)
captura lo que se envía si Laravel se configura con `MAIL_HOST=mailpit` y `MAIL_PORT=1025`.

**Historiales acotados.** `kilkana_post_revisions` y `kilkana_layout_revisions` conservan las
**50 últimas de cada dueño** y las viejas caen al insertar, dentro de la misma transacción. No
hay barrido periódico: la tabla está acotada por construcción.

Son las dos que más crecen del esquema, y por algo que no se ve en las columnas: cada fila
copia el objeto **entero** —el cuerpo del artículo, la composición de la portada— y se escribe
una por guardado. En la portada eso es una por autoguardado, que salta cada 800 ms de pausa,
así que una sesión componiendo deja decenas de copias de 4 KB.

> El tope acota el espacio, no el ruido. Una ráfaga de autoguardados gasta el historial entero
> en una sola sesión, así que «deshacer a lo de ayer» puede no existir aunque 50 suene
> generoso. Agrupar una ráfaga en una sola revisión es otra decisión y está sin tomar.

**Datos estructurados.** Varias columnas guardan JSON dentro de un `longtext`. La base no
valida su forma; cada documento describe qué estructura espera.

**Cotejamiento.** Las comparaciones de texto ignoran mayúsculas y acentos. `'Política'`,
`'politica'` y `'POLÍTICA'` son el mismo valor para un `WHERE` o un índice único. Esto
evita duplicados por variantes de escritura, pero también significa que **no se pueden
guardar dos filas que solo se distingan por una tilde** en una columna única.
