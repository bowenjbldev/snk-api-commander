# 6 · Comentarios y suscriptores

Las dos tablas donde escribe el público.

---

## `kilkana_comments` — el hilo

| Columna | Tipo | Nulo | Qué es |
|---|---|---|---|
| `id` | bigint unsigned | no | Clave primaria. |
| `post_id` | bigint unsigned | no | Artículo comentado. **CASCADE.** |
| `user_id` | bigint unsigned | sí | Cuenta, si quien comenta tiene una. **CASCADE.** |
| `parent_id` | bigint unsigned | sí | Comentario al que responde. Nulo = comentario de primer nivel. **CASCADE.** |
| `author_name` | varchar(80) | sí | Nombre de quien comenta **sin cuenta**. |
| `author_email` | varchar(190) | sí | Su correo. **No sale nunca al público.** |
| `body` | text | no | El texto. |
| `status` | varchar(16) | no · def. `pending` | `pending` · `approved` · `spam`. |
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

`parent_id` es CASCADE contra sí misma: borrar un comentario se lleva sus respuestas, y las
respuestas de esas. Es lo correcto —una respuesta a algo que ya no existe no se sostiene—
pero conviene saber que **borrar un comentario borra texto de otras personas**.

### Consecuencia de que `user_id` sea CASCADE

Borrar una cuenta borra sus comentarios y, en cascada, las respuestas que otros le dieron.
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
