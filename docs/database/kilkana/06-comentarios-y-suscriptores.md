# Comentarios y suscriptores

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
fondo no tiene una representación manejable, y borrar su raíz dispara una cascada tan larga
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
| `status` | enum | no · def. `pending` | `pending` · `confirmed` · `unsubscribed`, en `SnkKilkanaSubscriberStatusEnum`. |
| `consent_source` | enum | sí | `public_form` · `import` · `admin`, en `SnkKilkanaConsentSourceEnum`. |
| `consent_at` | timestamp | sí | El instante del acto que **prueba** el consentimiento. |
| `consent_ip` | varchar(45) | sí | La del clic de confirmación. IPv6 cabe. |
| `created_at` · `updated_at` | timestamp | sí | Alta y última modificación. |

**Sin claves foráneas**, y es a propósito: un suscriptor **no es una cuenta**. Quien recibe
el boletín no tiene por qué existir en `users`, y de hecho la mayoría no existe — se apuntó
desde la portada dejando un correo. Es la única tabla del paquete sin ninguna relación.

`status` es TODO el estado. No hay marca de tiempo de verificación: `confirmed` ya dice que
lo está, y dos representaciones del mismo hecho acaban desincronizadas. Hubo una columna
`verified_at` de un flujo de doble confirmación que nunca llegó a existir; nadie la escribía.

### El alta es idempotente, y quien volvió vuelve

Dar de alta un correo que ya está no crea una segunda fila; el `único` sobre `email` lo
garantiza. Y la respuesta es la misma exista o no el correo: una distinta convertiría el
formulario en un oráculo que dice quién está suscrito.

Pero idempotente no es «no hacer nada». Quien se dio de baja y **vuelve a rellenar el
formulario** está dando consentimiento nuevo, así que se le devuelve a `pending`. Antes el
alta era un `firstOrCreate` a secas: encontraba la fila, no tocaba nada y respondía `202`.
El formulario parecía funcionar y la persona seguía dada de baja para siempre, sin ninguna
manera de volver que no fuese cambiar el estado a mano.

Un `confirmed` que se vuelve a apuntar **no** se toca: ya está dentro, y devolverlo a
`pending` sería castigar con una baja de facto a quien rellena el formulario dos veces.

### Doble opt-in: dos enlaces firmados y ninguna sesión

`POST public/subscribe` manda un correo con dos enlaces —confirmar y darse de baja—, y el
clic desde el buzón es lo único que demuestra que la dirección es de quien la escribió. Sin
eso, cualquiera apunta a cualquiera, y quien recibe el boletín que no pidió es justo quien
reclama.

Quien pulsa llega desde un correo, donde **no hay sesión ninguna**. Por eso toda la
autorización es la firma (`URL::signedRoute` + middleware `signed`): sin ella, recorrer ids
correlativos daría de baja a media lista.

Cuatro decisiones que conviene no deshacer sin motivo:

- **La firma se genera contra el host del PERIÓDICO**, no contra `APP_URL`. El paquete se
  resuelve por Host y `APP_URL` apunta a la API, así que un `signedRoute` normal produce un
  enlace que falla dos veces: ni resuelve el paquete ni valida la firma, porque el host entra
  en lo firmado. Se fuerza la raíz con el host de la petición.
- **Sin caducidad.** Un enlace de baja tiene que funcionar dos años después. Mismo mecanismo
  para los dos, uno menos que mantener.
- **Idempotentes.** Pulsar dos veces aterriza igual y no cambia nada la segunda.
- **Un enlace viejo de confirmación no resucita a quien se dio de baja.** Solo se confirma
  desde `pending`; si no, el enlace que vive para siempre en su buzón devolvería a la lista a
  alguien que pidió salir.

Y una contrapartida asumida: **el `GET` cambia el estado**. Se pulsa desde un buzón, donde no
hay formulario que mande un `POST`, así que un escáner corporativo de enlaces puede confirmar
o dar de baja por su cuenta. La alternativa exige una interacción adicional que todavía no forma
parte del contrato, y hasta entonces la baja no funcionaría en absoluto.
Cuando haya proveedor de correo real, el camino es RFC 8058 (`List-Unsubscribe-Post`).

Las dos rutas redirigen a `{tenant.url}/subscribe/{confirmed|unsubscribed}`. Si el periódico
no tiene URL puesta, responden texto plano: una redirección a la nada es peor que una frase
que confirma lo que acaba de pasar.

La fila **se queda** al darse de baja: borrarla perdería la prueba de que esa persona pidió no
recibir más, y la siguiente lista importada volvería a escribirle.

### El envío es síncrono, y su fallo no tumba el alta

El correo sale dentro de la propia petición. Si falla, se registra y se traga: la fila ya está
creada y la respuesta sigue siendo `202` para todos, que es lo que impide que el formulario
sea un oráculo de quién está suscrito. Un SMTP caído no puede convertir eso en un `500` que
además delate el fallo.

Techo conocido: con un proveedor de verdad esto quiere cola, y hoy el único worker consume
`--queue=provisioning`.

En local hay **Mailpit** en el `docker-compose` (bandeja web en `:8025`): captura todo lo que
sale por SMTP y no lo deja salir, así que nadie manda un boletín de pruebas a una dirección
real por accidente.

### El consentimiento se guarda porque hay que poder demostrarlo

El RGPD (art. 7.1) pone la carga de la prueba en el responsable, que aquí es **el periódico**,
no nosotros. Una lista sin constancia de cómo se recogió no se puede defender ante una
reclamación aunque el alta fuera legítima — y en un SaaS eso es una responsabilidad que le
estaríamos vendiendo al cliente.

**`consent_at` guarda el acto que PRUEBA el consentimiento**, no dos fechas: se sella al
apuntarse y se **sobrescribe** al confirmar, junto con la IP, porque el clic desde el buzón es
el acto más fuerte y sustituye al de rellenar un formulario. `status` ya dice de qué tipo de
prueba se trata, así que no hace falta una segunda columna que lo repita — la lección que dejó
`verified_at`.

**`consent_source` lo pone siempre el servidor**, nunca la petición: una prueba que elige quien
la aporta no prueba nada. `public_form` desde la portada, `import` desde una lista que trajo el
periódico, y `admin` por defecto en el modelo para todo lo demás, que hoy es solo el alta
administrativa. El día que exista un comando de importación tiene que declarar su origen, o quedará
etiquetado como tecleado a mano: una prueba falsa es peor que una ausente.

Separar el origen es lo que permite acreditar y purgar por separado: **la lista importada es la
que trae el riesgo**, y sin la columna todas las filas parecen iguales.
