# Firmas

`kilkana_authors` — quién firma en el periódico.

Una firma **es una cuenta**. La identidad de un periodista es su cuenta de `users`, con el
correo que ya vive allí; esta tabla guarda solo lo **editorial**: cómo firma, en qué URL se
publica su ficha y qué se cuenta de él.

---

## Columnas

| Columna | Tipo | Nulo | Qué es |
|---|---|---|---|
| `id` | bigint unsigned | no | Clave primaria. Es lo que referencia `kilkana_posts.author_id`. |
| `avatar_media_id` | bigint unsigned | sí | Foto de la firma. Apunta a `kilkana_media`: es una foto que se publica, así que necesita texto alternativo, crédito, licencia y encuadre. Nula = sin foto. |
| `user_id` | bigint unsigned | no · **único** | La cuenta. Es la identidad de la firma. |
| `alias` | varchar(255) | sí | Seudónimo. **Nulo = firma con el nombre de su cuenta.** |
| `job_title` | varchar(255) | sí | Cargo que se publica: «Redactora de Economía», «Corresponsal en Bruselas». |
| `slug` | varchar(255) | no · **único** | Segmento de la URL pública de su ficha. |
| `seo_title` | varchar(500) | sí | Titular para buscadores. **Nulo = se usa el nombre publicado.** |
| `seo_description` | text | sí | Descripción para buscadores, unos 155 caracteres. **Nulo = se usa `bio`.** |
| `bio` | text | sí | Biografía breve, visible en la ficha. |
| `noindex` | boolean | no · def. `false` | Pide a los buscadores que no indexen su ficha. |
| `website` | varchar(2048) | sí | Web personal. |
| `socials` | longtext (JSON) | sí | Objeto `{red: url}`, con claves libres: `{"x": "https://…", "linkedin": "https://…"}`. |
| `created_at` · `updated_at` | timestamp | sí | Alta y última modificación. |

## Relaciones

| | Apunta a | Al borrar el destino |
|---|---|---|
| `user_id` | `users.id` | **RESTRICT** |
| `avatar_media_id` | `kilkana_media.id` | **SET NULL** |

`RESTRICT` en `user_id` porque borrar la cuenta dejaría su hemeroteca sin autor. Retirar
una firma es una decisión editorial y se toma antes, a mano.

Los dos `RESTRICT` que rodean a una firma —el suyo hacia la cuenta y el de
`kilkana_posts.author_id` hacia ella— **dicen qué los impide** en vez de dejar que salte el
error de la base: borrar una firma con artículos responde cuántos son, y borrar una cuenta que
firma responde qué firma es. Lo accionable no es el mismo dato en los dos casos: en el primero
hay que mover un número de piezas, en el segundo hay que retirar una firma concreta.

---

## El nombre publicado NO está en esta tabla

No hay columna `name`. El nombre que se publica se **calcula**:

```
nombre publicado = alias, si lo hay
                   si no, users.name de su cuenta
```

Aquí hubo una columna `name` copiada de la cuenta —idéntica en 10 de 10 firmas—, y una
copia solo sabe hacer una cosa: quedarse vieja. Renombrar a la persona dejaba el periódico
entero firmando con el nombre antiguo. Derivándolo, renombrar la cuenta renombra su firma
en todas partes a la vez.

`alias` **no es único** a propósito: dos firmas pueden usar el mismo texto. Lo que no puede
repetirse —la URL— ya lo protege `slug`.

## El slug no sigue al nombre

`slug` se fija al crear la firma y **no se recalcula**: es la URL pública, y moverla rompe
cada enlace que apunte ahí. Cambiarlo es una acción deliberada.

Y se puede cambiar, pero **no vaciar**: la columna no admite nulo y el derivador solo corre
al crear la firma, así que no hay nadie que rellene el hueco después. Una firma que ya
existe tiene URL siempre.

Al crear una firma sin slug, se deriva bajando dos peldaños y **parándose**:

```
1.  alias, o si no el nombre de la cuenta   →  jorge-bowen
2.  users.full_name (el segundo apellido)   →  jorge-bowen-lopez
3.  no se inventa nada: error
```

Sin sufijos numéricos. Un `-2` automático fija para siempre una URL que nadie eligió y que
nadie vuelve a revisar. Si los dos peldaños están ocupados —o el nombre no deja un slug
utilizable—, el alta **falla sobre el campo `slug`** y alguien elige la URL a mano.

Esa negativa no puede tumbar el alta de una **cuenta**: cuando una cuenta con permiso de
escritura no consigue firma, la cuenta se guarda igual y queda sin ella, disponible para
que alguien le asigne una URL después. Guardar un usuario no puede fallar por su firma.

### `kilkana_author_slugs` — las URLs que dejó atrás

| Columna | Tipo | Nulo | Qué es |
|---|---|---|---|
| `id` | bigint unsigned | no | Clave primaria. |
| `author_id` | bigint unsigned | no | Firma a la que apunta. **CASCADE.** |
| `path` | varchar(255) | no · **único** | El slug antiguo: `pedro-aranda`. |
| `created_at` · `updated_at` | timestamp | sí | Alta y última modificación. |

Cambiar el seudónimo de alguien mueve su ficha de dirección, y los enlaces que ya existen
fuera —de otro medio, de una red social, del currículum de esa persona— apuntan a la vieja.
Esta tabla es lo que hace que sigan llegando.

Se escribe sola al renombrar: la ruta que la firma deja entra en el historial, y la que
adopta sale de él si era suya —o apuntaría a sí misma—. La ficha pública la consulta: si el
slug pedido no es de nadie pero está aquí, se responde el actual para que quien pregunte
redirija.

**Una URL retirada no vuelve a estar libre.** Otra firma no puede adoptar el seudónimo que
esta dejó: recibe un 422. Si pudiera, la redirección de la primera se perdería y sus lectores
acabarían en la ficha de otra persona.

## Una firma colectiva es una cuenta de servicio

«Redacción» o «Agencias» no son personas, y `user_id` es obligatorio. El hueco se cubre sin
tocar el esquema: una cuenta **sin rol** —nada que hacer, nadie entra con ella— y el `alias`
encima. Los breves que firma la casa apuntan a esa firma mientras `kilkana_posts.created_by`
sigue siendo la persona que los tecleó.

## El cargo se publica; la organización no

`job_title` es editorial y sale al público: es la señal que busca quien comprueba a un
autor. `users.company` es la organización de la cuenta y se queda dentro. Son datos
distintos aunque suenen parecido — la mesa de redacción llegó a guardarse en `company`, y
eso dejaba un dato editorial en la columna administrativa.

## `website` y `socials` son enlaces que se publican

Las dos columnas acaban en un enlace de la ficha pública, así que lo que se guarde ahí lo
va a pulsar un lector. Quien escriba en esta tabla tiene que acotar el **esquema** a `http`
y `https`: la base guarda cualquier cadena, y `javascript:…` o `data:text/html,…` son
direcciones válidas para un navegador que ejecutan código en la página del periódico.

No es una restricción de la base porque no puede serlo —no hay forma de escribir «esto es
una URL de verdad» en la columna—, así que vive en la validación de la API.

## El correo no sale de aquí

`kilkana_authors` no guarda correo. Quien sirva una ficha al público debe recortar a lo
editorial (nombre calculado, slug, cargo, bio, web, redes, foto); el correo vive en `users`
y no tiene por qué salir.
