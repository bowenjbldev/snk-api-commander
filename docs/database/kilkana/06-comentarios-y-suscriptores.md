# Comentarios y suscriptores

Las tablas donde escribe el público, y las que miden lo que hace.

---

## `kilkana_comments` — el hilo

| Columna                     | Tipo            | Nulo                | Qué es                                                                           |
| --------------------------- | --------------- | ------------------- | -------------------------------------------------------------------------------- |
| `id`                        | bigint unsigned | no                  | Clave primaria.                                                                  |
| `parent_id`                 | bigint unsigned | sí                  | Comentario al que responde. Nulo = de primer nivel. **CASCADE.**                 |
| `post_id`                   | bigint unsigned | no                  | Artículo comentado. **CASCADE.**                                                 |
| `user_id`                   | bigint unsigned | sí                  | Quién lo escribió. Nulo = **su cuenta se dio de baja**. **SET NULL.**            |
| `status`                    | enum            | no · def. `pending` | `pending` · `approved` · `rejected` · `spam`. Solo `approved` se ve en la calle. |
| `body`                      | text            | no                  | El texto.                                                                        |
| `created_at` · `updated_at` | timestamp       | sí                  | Alta y última modificación.                                                      |

Índice sobre `(post_id, status)`: la consulta natural es «los aprobados de este artículo».

### Comentar exige cuenta

No hay vía anónima: no existe endpoint público de alta, y el nombre que se ve sale siempre de
la cuenta que lo escribió. Por eso la tabla no guarda ni nombre ni correo propios.

Es una decisión de producto, y su coste es real: **habrá muchos menos comentarios**. Lo que
compra es que la identidad identifique de verdad. El correo de un anónimo sería autodeclarado
y sin verificar, así que no distinguiría a nadie —un mismo troll escribiría uno distinto cada
vez— y tampoco serviría para avisarle de una respuesta, porque escribir a una dirección que
alguien tecleó sin demostrar que es suya es hacer spam. Se cargaría con el peso legal de un
dato personal a cambio de ninguna garantía.

Con cuenta, bloquear a quien molesta es bloquear una cuenta y no jugar al topo, y avisar de
las respuestas funciona porque la dirección está confirmada al registrarse.

> Si algún día un periódico quiere abrir la puerta —un local vive de la participación y el
> registro es un muro para su lector—, eso es un **ajuste por periódico**
> (`accounts · anonymous · closed`), no un cambio de esquema: `user_id` ya es nulable.

### El hilo se arma con `parent_id`

Las filas son planas; la jerarquía la reconstruye quien las lea. Una respuesta solo debería
colgar de un comentario **aprobado del mismo artículo**: sin esa comprobación, un
`parent_id` cualquiera cuelga la respuesta de otra pieza o de algo que nadie ha aprobado.

`parent_id` es CASCADE contra sí misma: borrar un comentario se lleva sus respuestas. Es lo
correcto —una respuesta a algo que ya no existe no se sostiene— pero conviene saber que
**borrar un comentario borra texto de otras personas**.

### Un solo nivel de respuestas

`parent_id` admitiría por su cuenta una cadena sin fin, así que **el padre de una respuesta no
puede tener padre**: se responde al comentario, no a la respuesta.

Es la misma regla de forma que en las secciones, y por los mismos dos motivos: un hilo sin
fondo no tiene una representación manejable, y borrar su raíz dispara una cascada tan larga
como la conversación. Y como allí, **no es una restricción de la base porque no puede
serlo**: MariaDB no admite subconsultas en un `CHECK`, así que vive en el modelo, que es por
donde pasan también el seeder y la consola.

### Borrar una cuenta no borra lo que escribió

`user_id` es **SET NULL**. Con CASCADE, dar de baja una cuenta borraría sus comentarios y, por
el CASCADE de `parent_id`, las respuestas que otros le dieron — y sería el **único sitio del
esquema** donde borrar un usuario destruye contenido: sus artículos, sus revisiones y sus
subidas quedan huérfanos y sobreviven.

Con `SET NULL` el comentario se queda sin cuenta y el hilo intacto. Es también la práctica
estándar de privacidad: ante una baja se **anonimiza**, no se destruye. Un borrado real por
petición del interesado es una acción deliberada y explícita, no el efecto colateral de dar de
baja a alguien.

Por eso `user_id` es **nulable aunque comentar exija cuenta**: el nulo no significa «anónimo»,
significa «su cuenta ya no está». Quien pinte el hilo dirá «cuenta eliminada». La API nunca
acepta un nulo; solo lo produce una baja.

### Rechazado no es spam, y no es borrarlo

Un comentario leído y descartado queda `rejected`: no sale, y **se queda**, porque es el historial
de la cuenta —dos rechazos son un mal día, seis son una cuenta vigilada— y porque rechazado y spam
son desenlaces distintos que la moderación necesita ver por separado. Spam es lo que sirve para
reconocer el siguiente; borrar es para lo que no debe quedar ni en el historial.

### Lo que el panel lee y escribe de la cola

`GET comments` es la cola de moderación: acepta `status`, `q` (texto o cuenta, dos letras como
mínimo), `per_page` y `counts=1` —solo los conteos y la edad del pendiente más viejo, para el
raíl—, ordena por fecha descendente y devuelve `data`, `meta`, `counts` por estado del periódico
entero, `oldest_pending_at` y `blocked_readers`. Cada fila lleva `author` —nombre, correo, alta,
cuántos ha escrito, cuántos rechazados y si está bloqueada; `null` si la cuenta se dio de baja—,
`on` —la pieza— y `reply_to` —quién y qué dice el comentario al que responde—.

`POST comments/bulk` acepta `approve`, `reject`, `pending`, `spam` y `delete`.
`POST comments/{id}/block-author` suspende la cuenta del autor **solo si es únicamente lector** —a un
compañero no se le bloquea desde la moderación, y a una cuenta dada de baja tampoco: 409—, tira
sus sesiones y rechaza lo que tenía pendiente. Lo hace `SnkKilkanaReaderService`, el mismo que usa
la pantalla de lectores, y con **su** permiso, `update readers`: bloquear a una persona no es
moderar una frase, y la mesa de edición modera sin bloquear. Deshacerlo es de esa pantalla.

## Los lectores — la cuenta detrás del comentario

Un lector es una fila de `users` con el papel `reader` y ningún asiento: no hay tabla propia,
porque es la misma cuenta que comenta. Lo que la pantalla de lectores necesita es esa cuenta
**cruzada** con lo que ha hecho: cuántos comentarios y cuántos rechazados, si su correo está en
`kilkana_subscribers` y en qué estado, cuántas piezas medidas ha abierto este mes y cuándo se la
vio por última vez —lo más reciente entre usar una sesión, comentar y abrir una pieza medida—.

`readers` es un recurso del catálogo aparte de `comments` y de `subscribers`
([`01-cuentas-y-permisos.md`](01-cuentas-y-permisos.md)): una cuenta lectora y un correo del
boletín son dos listas que se cruzan y no se contienen —se recibe el boletín sin cuenta—, y
gobernar personas no es moderar frases. Lo tienen `director` y `moderador`.

### Lo que el panel lee y escribe de los lectores

`GET readers` (`index readers`) lista **solo** cuentas que son únicamente lectoras —la redacción no
sale ni con su id a mano—, de la más nueva a la más vieja. Acepta `tab` (`all`, `subscribers`
—correo confirmado en el boletín—, `commenters` —ha comentado algo que no es spam y no está
bloqueada—, `blocked`), `q` (nombre o correo, dos letras como mínimo), `per_page`, `counts=1`
—solo `accounts`, en una consulta, para el raíl— y `all=1` —lo filtrado entero sin paginar, hasta
cinco mil, que es lo que se lleva un export—. Devuelve `data`, `meta`, `counts` por pestaña y
`stats`: cuentas, suscriptores confirmados de la lista entera, quién comentó este mes y
bloqueados. Cada fila lleva `comments`, `rejected`, `reads` —las medidas del mes—, `newsletter`
(`status`, `consent_source`, `consent_at`; `null` sin suscripción), `last_seen_at` y `blocked`.

`POST readers/{id}/block` y `DELETE readers/{id}/block` (`update readers`) cierran y reabren la
cuenta. Bloquear tira las sesiones y rechaza lo pendiente; desbloquear no deshace ninguna decisión,
porque rechazar ya fue una. No hay `show`, y `DELETE readers/{id}` (`delete readers`) no borra:
**retira** —bloquea, y sustituye nombre, correo y contraseña por nada reconocible (`Reader`,
`retired-{id}@anonymised.invalid`)—, borra su fila del boletín y deja los comentarios, que son
parte de la conversación de cada pieza. Es lo que el RGPD pide cuando alguien se va, y no vuelve.

## `kilkana_reader_reads` — las lecturas medidas

Qué pieza **con muro** abrió cada cuenta y en qué mes: una fila por (`user_id`, `post_id`,
`period`), con `period` el primer día del mes y `read_at` el instante; las dos claves en CASCADE.
Es lo que sostiene el contador de «lecturas medidas del mes» de una cuenta, que es lo que el muro
consume: pasadas las del mes, una pieza medida pide suscripción.

- **Una fila por cuenta, pieza y mes, y no un contador.** Releer la misma pieza no gasta otra
  lectura, y el mes que viene vuelve a contar desde cero.
- **Solo entran piezas `metered`.** Una gratuita no consume nada y una cerrada no se abre;
  `SnkKilkanaReaderReadModel::record()` lo comprueba y no escribe.
- **La apunta el mismo `POST …/view` que cuenta la audiencia**, en el evento `open`, si la
  petición trae el token de una cuenta: la ruta es pública y sin token no hay a quién
  apuntársela. Las filas vienen de la demo y de quien llame con token.
- **`period` se compara con la fecha entera**, no con `Y-m-d`: la columna es `date`, pero el cast
  la escribe con hora y SQLite compara cadenas tal cual.

### Un comentario nace `pending`

Lo que se publica lo decide la moderación, no quien escribe. Ni siquiera con cuenta: el
registro dice quién habla, no que lo que diga salga solo.

**Esa es la protección real, no la identidad.** El riesgo de una sección de comentarios no es
el spam masivo —para eso está el registro— sino el vecino que acusa a otro con nombre y
apellidos, y eso lo firma igual alguien registrado. Lo que lo evita es que nadie publique sin
que un humano lo lea.

---

## `kilkana_subscribers` — el boletín

| Columna                     | Tipo            | Nulo                | Qué es                                                                         |
| --------------------------- | --------------- | ------------------- | ------------------------------------------------------------------------------ |
| `id`                        | bigint unsigned | no                  | Clave primaria.                                                                |
| `email`                     | varchar(255)    | no · **único**      | La identidad.                                                                  |
| `status`                    | enum            | no · def. `pending` | `pending` · `confirmed` · `unsubscribed`, en `SnkKilkanaSubscriberStatusEnum`. |
| `consent_source`            | enum            | sí                  | `public_form` · `import` · `admin`, en `SnkKilkanaConsentSourceEnum`.          |
| `consent_at`                | timestamp       | sí                  | El instante del acto que **prueba** el consentimiento.                         |
| `consent_ip`                | varchar(45)     | sí                  | La del clic de confirmación. IPv6 cabe.                                        |
| `created_at` · `updated_at` | timestamp       | sí                  | Alta y última modificación.                                                    |

Dos índices además de la clave y el único de `email`: `(status, consent_at)` y
`(status, updated_at)`. Las cuatro cifras de la banda preguntan por un estado y luego por una
ventana de tiempo, y las cuatro barrían la tabla. Sobre 52 352 filas, medido con `ANALYZE` de
MariaDB: las altas de la semana de 17,2 a 0,37 ms, las bajas de la ventana de 12,0 a 1,68, y el
`group by status` de 44,9 a 28,9 porque pasa a recorrer el índice y no la tabla. `(consent_source)`
se probó y **no está**: con él el otro `group by` va peor —18,2 a 22,4 ms—, porque son tres
valores repartidos sobre cincuenta mil filas y recorrer ese índice entero no ahorra nada.

**Sin claves foráneas**, y es a propósito: un suscriptor **no es una cuenta**. Quien recibe
el boletín no tiene por qué existir en `users`, y de hecho la mayoría no existe — se apuntó
desde la portada dejando un correo.

`status` es TODO el estado. No hay marca de tiempo de verificación: `confirmed` ya dice que
lo está, y dos representaciones del mismo hecho acaban desincronizadas.

### El alta es idempotente, y quien volvió vuelve

Dar de alta un correo que ya está no crea una segunda fila; el `único` sobre `email` lo
garantiza. Y la respuesta es la misma exista o no el correo: una distinta convertiría el
formulario en un oráculo que dice quién está suscrito.

Pero idempotente no es «no hacer nada». Quien se dio de baja y **vuelve a rellenar el
formulario** está dando consentimiento nuevo, así que se le devuelve a `pending`. Un
`firstOrCreate` a secas encontraría la fila, no tocaría nada y respondería `202`: el formulario
parecería funcionar y la persona seguiría dada de baja para siempre, sin ninguna manera de
volver que no fuese cambiar el estado a mano.

Un `confirmed` que se vuelve a apuntar **no** se toca: ya está dentro, y devolverlo a
`pending` sería castigar con una baja de facto a quien rellena el formulario dos veces.

### Doble opt-in: dos enlaces firmados y ninguna sesión

`POST public/subscribe` (freno `kilkana-subscribe`) manda un correo con dos enlaces —confirmar y
darse de baja—, y el clic desde el buzón es lo único que demuestra que la dirección es de quien
la escribió. Sin eso, cualquiera apunta a cualquiera, y quien recibe el boletín que no pidió es
justo quien reclama.

Quien pulsa llega desde un correo, donde **no hay sesión ninguna**. Por eso toda la
autorización es la firma (`URL::signedRoute` + middleware `signed`, rutas nombradas
`subscribe.confirm` y `subscribe.unsubscribe`): sin ella, recorrer ids correlativos daría de
baja a media lista.

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
o dar de baja por su cuenta. La alternativa exige una interacción adicional que no forma parte
del contrato, y sin ella la baja no funcionaría en absoluto. Con un proveedor de correo real, el
camino es RFC 8058 (`List-Unsubscribe-Post`).

Las dos rutas redirigen a `{tenant.url}/subscribe/{confirmed|unsubscribed}`. Si el periódico
no tiene URL puesta, responden texto plano: una redirección a la nada es peor que una frase
que confirma lo que acaba de pasar.

La fila **se queda** al darse de baja: borrarla perdería la prueba de que esa persona pidió no
recibir más, y es lo que hace que una lista importada que la traiga de nuevo se la salte.

### El envío es síncrono, y su fallo no tumba el alta

El correo de confirmación sale dentro de la propia petición. Si falla, se registra y se traga:
la fila ya está creada y la respuesta sigue siendo `202` para todos, que es lo que impide que
el formulario sea un oráculo de quién está suscrito. Un SMTP caído no puede convertir eso en un
`500` que además delate el fallo.

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
prueba se trata, así que no hace falta una segunda columna que lo repita.

**`consent_source` lo pone siempre el servidor**, nunca la petición: una prueba que elige quien
la aporta no prueba nada. `public_form` desde la portada, `import` desde una lista que trajo el
periódico por `POST subscribers/import`, y `admin` por defecto en el modelo para todo lo demás,
que es el alta administrativa. La importación declara su origen ella misma: sin eso quedaría
etiquetada como tecleada a mano, y una prueba falsa es peor que una ausente.

La importación es un CSV —`email` y, si vienen, `status` y `consent_at`, con cabecera o en ese
orden— con `consented` aceptado en la misma petición: quien sube la lista declara que las
direcciones consintieron, y sin la declaración no entra ninguna. Un correo que ya está se salta
esté como esté, así una baja no vuelve a alta por venir en la lista vieja; una dirección que no lo
es se cuenta en `invalid` y sigue. `GET subscribers/export` devuelve la lista entera con esas
cuatro columnas más `consent_source`, que es lo que permite demostrar de dónde salió cada una.
Las dos van declaradas antes del `apiResource` (`index`, `show`, `store`, `update`, `destroy`)
para que `show` no se trague «export» como id.

`GET subscribers` **pagina**: `per_page` de 25 por defecto y 100 de techo, `page`, de la última
alta a la primera y con el mismo `meta` que lectores y comentarios. Es el único listado del
paquete que crece sin cota —los demás que heredan el `index()` del base son catálogos acotados—
y devolvía la tabla entera: con cincuenta mil direcciones, megabytes de JSON para pintar
veinticinco filas. Llevarse la lista completa sigue teniendo su puerta, que es `export`, y esa
sí recorre la tabla, pero en streaming y como CSV.

Separar el origen es lo que permite acreditar y purgar por separado: **la lista importada es la
que trae el riesgo**, y sin la columna todas las filas parecen iguales.

## `kilkana_newsletter_issues` y `kilkana_newsletter_events` — los números

Un número tiene dos estados y ninguno intermedio: borrador y enviado. Enviar es irreversible, y
de ahí sale todo lo que guarda la fila: `subject` y `preheader`, `post_ids` son las piezas que
lleva —las elegidas a mano en su orden, hasta doce, o las cuatro últimas publicadas si no se
eligió ninguna; se fijan al enviar—, `scheduled_at` es la hora a la que sale solo (`PUT` la exige
por delante del reloj; nula es «cuando alguien pulse»), `recipients_count` es a cuántos salió ese
día y no cuántos hay hoy, `sent_at` es la fecha, `test_sent_to` y `test_sent_at` dejan constancia
de la última prueba, y `created_by` (SET NULL) de quién lo abrió. `GET newsletter` trae además
`candidates`, las veinte publicadas más recientes entre las que se elige. Un enviado no se
reescribe: `PUT newsletter/{id}` contesta 409, y no hay `DELETE`: un número enviado es lo que
hay en las bandejas. `kilkana:newsletter-send-due` corre al minuto por cada inquilino y manda los
borradores con hora cumplida, uno a uno y con la fila bloqueada, para que dos ticks no manden el
mismo dos veces. El correo sale con la dirección de la instalación y el nombre del periódico como
remitente, y `newsletter_reply_to` de los ajustes como `Reply-To`.

El listado resuelve las piezas y las cifras de **todos** los números a la vez y no número a
número: las elegidas de los enviados en una consulta, las de los borradores en otra, las cuatro
últimas publicadas en una tercera —que sirve a la vez a los borradores sin elección y al
`pieces` del número en blanco— y las cifras de todos en una cuarta, agrupando por `issue_id` y
clase. Pintar seis números cuesta las mismas consultas que pintar dos. De cada pieza se leen
solo `id`, `title`, `excerpt`, `url_date` y `slug`, y sin las cuatro relaciones que
`SnkKilkanaPostModel` carga él solo: aquí nadie serializa una pieza entera —el panel pinta el
titular, y el correo el titular, la entradilla y el enlace—, así que eran cuatro consultas por
llamada para nada.

### El envío va por tandas a la cola de la instalación

`POST newsletter/{id}/send` fija el número, lo marca enviado y encola una tanda
(`SnkKilkanaNewsletterSendJob`) por cada cincuenta confirmados (`BATCH`). La cola es la base de la
**instalación** —`DB_QUEUE_CONNECTION=commander`— y no la del periódico: una petición de un
periódico tiene el tenant como conexión por defecto, y sin esa variable encolaría en la base del
periódico, donde no hay worker. Cada tanda lleva la base y el subdominio, y se conecta al
inquilino (`SnkKilkanaTenantRunnerService::on()`) antes de mandar nada. Un correo que falla se
apunta y no arrastra la tanda; una baja entre el «Send» y la tanda no recibe nada. **Y cada
suscriptor recibe una vez**: la tanda apunta `sent` por suscriptor antes de mandar y se salta a
quien ya lo tiene, porque el worker se reinicia por diseño (`--max-time`) y una tanda en vuelo se
vuelve a ejecutar entera. Un correo perdido por un fallo entre la marca y el envío es el lado
bueno por el que equivocarse: un boletín duplicado cuesta bajas.

El correo (`SnkKilkanaNewsletterMail`) lleva las piezas con enlaces **firmados por número y
suscriptor** (`public/newsletter/{issue}/click/{subscriber}/{post}`), un píxel de apertura
(`public/newsletter/{issue}/open/{subscriber}`) y la baja firmada con el número dentro
(`?issue=`); los tres bajo `signed` y el freno `kilkana-signed`. Una prueba
(`POST newsletter/{id}/test`) es una copia a una dirección, sin píxel ni baja de nadie, y deja
constancia de a dónde fue.

### Lo que la lista hace con un número cuenta una vez

`kilkana_newsletter_events` es una fila por suscriptor y hecho —`sent`, `open`, `click` con la
pieza, `unsub`—, única (`kilkana_nl_events_once`, con `post_id` a cero sin pieza porque dos nulos
no chocan en un índice único): abrir veinte veces es una apertura, porque lo que se mide es cuánta
gente, no cuántas veces. `sent` no es una cifra del panel. Las cifras de un número son
`count(distinct subscriber)` por clase, y la salud de la lista suma aperturas entre destinatarios
de los envíos de noventa días. El píxel contesta un gif de un píxel sin caché; el clic redirige a
la pieza en el periódico, o a la portada si la pieza ya no está.

### Quién

Leer y escribir es de quien gobierna suscriptores (`index/create/update subscribers`): el boletín
es la lista, y quien puede tocar la lista puede escribirle. `counts=1` devuelve solo cuántos
borradores esperan, para el raíl.
