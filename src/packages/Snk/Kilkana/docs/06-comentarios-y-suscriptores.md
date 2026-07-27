# 6 · Comentarios y suscriptores

Las dos tablas donde escribe el público.

---

## `kilkana_comments` — el hilo

| Columna | Tipo | Nulo | Qué es |
|---|---|---|---|
| `id` | bigint unsigned | no | Clave primaria. |
| `parent_id` | bigint unsigned | sí | Comentario al que responde. Nulo = de primer nivel. **CASCADE.** |
| `post_id` | bigint unsigned | no | Artículo comentado. **CASCADE.** |
| `user_id` | bigint unsigned | sí | Cuenta, si quien comenta tiene una. **SET NULL.** |
| `author_name` | varchar(80) | sí | Nombre de quien comenta **sin cuenta**. |
| `author_email` | varchar(190) | sí | Su correo. **No sale en ninguna respuesta.** |
| `status` | enum | no · def. `pending` | `pending` · `approved` · `spam`. |
| `body` | text | no | El texto. |
| `created_at` · `updated_at` | timestamp | sí | Alta y última modificación. |

Índice sobre `(post_id, status)`: la consulta natural es «los aprobados de este artículo».

### Dos formas de firmar un comentario, excluyentes

| | `user_id` | `author_name` / `author_email` |
|---|---|---|
| Con cuenta | el identificador | nulos |
| Sin cuenta | nulo | rellenos |

El nombre visible sale de un sitio o del otro. **La base no obliga a que haya uno de los
dos**: nada impide insertar un comentario sin cuenta y sin nombre, que quedaría sin firma.
Lo impone quien escribe.

### El hilo se arma con `parent_id`

Las filas son planas; la jerarquía la reconstruye quien las lea. Una respuesta solo debería
colgar de un comentario **aprobado del mismo artículo**: sin esa comprobación, un
`parent_id` cualquiera cuelga la respuesta de otra pieza o de algo que nadie ha aprobado.

`parent_id` es CASCADE contra sí misma: borrar un comentario se lleva sus respuestas. Es lo
correcto —una respuesta a algo que ya no existe no se sostiene— pero conviene saber que
**borrar un comentario borra texto de otras personas**.

### Un solo nivel de respuestas

`parent_id` admitiría por su cuenta una cadena sin fin, y sin freno se llegó a doce niveles
sin que nada lo impidiera. Ahora **el padre de una respuesta no puede tener padre**: se
responde al comentario, no a la respuesta.

Es la misma regla de forma que en las secciones, y por los mismos dos motivos: un hilo sin
fondo no cabe indentado en ninguna pantalla, y borrar su raíz dispara una cascada tan larga
como la conversación. Y como allí, **no es una restricción de la base porque no puede
serlo**: MariaDB no admite subconsultas en un `CHECK`, así que vive en el modelo, que es por
donde pasan también el seeder y la consola.

### Borrar una cuenta no borra lo que escribió

`user_id` es **SET NULL**, y esto fue lo contrario. Con CASCADE, dar de baja una cuenta
borraba sus comentarios y, por el CASCADE de `parent_id`, las respuestas que otros le habían
dado: medido, borrar una cuenta se llevó tres comentarios, uno de ellos escrito por otra
persona.

Era además el **único sitio del esquema** donde borrar un usuario destruía contenido — sus
artículos, sus revisiones y sus subidas quedan huérfanos y sobreviven.

Con `SET NULL` el comentario se queda sin cuenta y el hilo intacto; quien lo pinte dirá
«cuenta eliminada». Es también la práctica estándar de privacidad: ante una baja se
**anonimiza**, no se destruye. Si algún día hace falta borrado real por petición del
interesado, eso es una acción deliberada y explícita, no el efecto colateral de dar de baja a
alguien.

### El correo de un anónimo no sale de la tabla

Se pide al comentar sin cuenta, y **hoy no lo lee nadie**: no hay aviso de respuesta ni
comprobación antispam que lo use. Salía en el listado de moderación por el simple hecho de
estar en la tabla, y ya no.

Queda una decisión pendiente, y es de producto, no de esquema: **o se le da un uso —avisar a
quien comentó de que le han respondido es el clásico— o se deja de pedir**. Un dato personal
que se guarda para siempre sin usarse no tiene defensa.
Un hilo se reescribe sin que nadie se entere.

No es fácil de mejorar tal como está el esquema: `author_name` es nulo en los comentarios
con cuenta, así que cambiarlo a `SET NULL` dejaría comentarios sin ninguna firma. Conservar
el hilo exigiría copiar el nombre al comentar, aceptando que esa copia envejece si la
persona se renombra.

### Un comentario nace `pending`

Lo que se publica lo decide la moderación, no quien escribe. Un alta pública no debería
devolver el comentario creado: devolverlo invita a pintarlo en el hilo como si ya estuviera
aprobado.

---

## `kilkana_subscribers` — el boletín

| Columna | Tipo | Nulo | Qué es |
|---|---|---|---|
| `id` | bigint unsigned | no | Clave primaria. |
| `email` | varchar(255) | no · **único** | La identidad. |
| `status` | varchar(16) | no · def. `pending` | `pending` · `confirmed` · `unsubscribed`. |
| `verified_at` | timestamp | sí | **Columna muerta: nada la escribe.** |
| `created_at` · `updated_at` | timestamp | sí | Alta y última modificación. |

Sin relaciones con el resto del esquema. Un suscriptor **no es una cuenta**: quien recibe
el boletín no tiene por qué existir en `users`.

### El alta es idempotente y no confirma nada

Dar de alta un correo que ya está no crea una segunda fila; el `único` sobre `email` lo
garantiza. Y la respuesta debe ser la misma exista o no el correo: una respuesta distinta
convierte el formulario en un oráculo que dice quién está suscrito.

### `verified_at` no la escribe nadie

`status` es lo único que se mueve. La columna quedó de un flujo de doble confirmación que
no llegó a existir: hoy es una segunda representación del mismo hecho que nada mantiene al
día. O se cablea, o sobra.
