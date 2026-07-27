# 6 · Comentarios y suscriptores

Las dos tablas donde escribe el público.

---

## `kilkana_comments` — el hilo

| Columna | Tipo | Nulo | Qué es |
|---|---|---|---|
| `id` | bigint unsigned | no | Clave primaria. |
| `parent_id` | bigint unsigned | sí | Comentario al que responde. Nulo = de primer nivel. **CASCADE.** |
| `post_id` | bigint unsigned | no | Artículo comentado. **CASCADE.** |
| `user_id` | bigint unsigned | sí | Quién lo escribió. Nulo = **su cuenta se dio de baja**. **SET NULL.** |
| `status` | enum | no · def. `pending` | `pending` · `approved` · `spam`. |
| `body` | text | no | El texto. |
| `created_at` · `updated_at` | timestamp | sí | Alta y última modificación. |

Índice sobre `(post_id, status)`: la consulta natural es «los aprobados de este artículo».

### Comentar exige cuenta

No hay vía anónima: no existe endpoint público de alta, y el nombre que se ve sale siempre de
la cuenta que lo escribió. Por eso la tabla no guarda ni nombre ni correo propios — los tenía,
y eran el rastro de cuando se podía comentar sin registrarse.

Es una decisión de producto, y su coste es real: **habrá muchos menos comentarios**. Lo que
compra es que la identidad identifique de verdad. El correo del anónimo era autodeclarado y
sin verificar, así que no distinguía a nadie —un mismo troll escribía uno distinto cada vez— y
tampoco se podía usar para avisarle de una respuesta, porque escribir a una dirección que
alguien tecleó sin demostrar que es suya es hacer spam. Se cargaba con el peso legal de un
dato personal a cambio de ninguna garantía.

Con cuenta, bloquear a quien molesta es bloquear una cuenta y no jugar al topo, y avisar de
las respuestas funciona porque la dirección está confirmada al registrarse.

> Si algún día un periódico quiere abrir la puerta —un local vive de la participación y el
> registro es un muro para su lector—, eso es un **ajuste por periódico**
> (`accounts · anonymous · closed`), no una vuelta atrás del esquema: `user_id` ya es nulable.

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

Con `SET NULL` el comentario se queda sin cuenta y el hilo intacto. Es también la práctica
estándar de privacidad: ante una baja se **anonimiza**, no se destruye. Si algún día hace
falta borrado real por petición del interesado, eso es una acción deliberada y explícita, no
el efecto colateral de dar de baja a alguien.

Por eso `user_id` es **nulable aunque comentar exija cuenta**: el nulo no significa «anónimo»,
significa «su cuenta ya no está». Quien pinte el hilo dirá «cuenta eliminada». La API nunca
acepta un nulo; solo lo produce una baja.

### Un comentario nace `pending`

Lo que se publica lo decide la moderación, no quien escribe. Ni siquiera con cuenta: el
registro dice quién habla, no que lo que diga salga solo.

**Esa es la protección real, no la identidad.** El riesgo de una sección de comentarios no es
el spam masivo —para eso está el registro— sino el vecino que acusa a otro con nombre y
apellidos, y eso lo firma igual alguien registrado. Lo que lo evita es que nadie publique sin
que un humano lo lea.

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
