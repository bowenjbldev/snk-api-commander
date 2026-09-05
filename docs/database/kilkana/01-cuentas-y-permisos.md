# Cuentas y permisos

Quién existe en el periódico y qué puede hacer.

Son seis tablas: una de cuentas, dos de vocabulario (roles y permisos) y tres pivotes que
los reparten.

---

## `users` — las cuentas

Una fila por persona con acceso, más las cuentas de servicio. Es la identidad: **el correo
es único y es lo que identifica a alguien**, no el nombre.

| Columna                     | Tipo            | Nulo           | Qué es                                                                                                                                     |
| --------------------------- | --------------- | -------------- | ------------------------------------------------------------------------------------------------------------------------------------------ |
| `id`                        | bigint unsigned | no             | Clave primaria.                                                                                                                            |
| `name`                      | varchar(255)    | no             | Nombre de trabajo y firma por defecto si no hay alias. Puede repetirse: dos personas pueden llamarse igual.                                |
| `full_name`                 | varchar(255)    | sí             | Nombre completo, con los dos apellidos. Sirve para desempatar a dos homónimos.                                                             |
| `company`                   | varchar(255)    | sí             | Organización a la que pertenece. Para la plantilla, el propio periódico; para quien colabora desde fuera, la suya. **No sale al público.** |
| `phone`                     | varchar(50)     | sí             | Teléfono de contacto interno.                                                                                                              |
| `email`                     | varchar(255)    | no · **único** | La identidad.                                                                                                                              |
| `email_verified_at`         | timestamp       | sí             | Cuándo se verificó el correo. Nulo = sin verificar.                                                                                        |
| `invited_at`                | timestamp       | sí             | Cuándo se invitó. Nulo = ya puso su contraseña. Una cuenta invitada ocupa asiento y no entra.                                              |
| `suspended_at`              | timestamp       | sí             | Cuándo se le cerró el paso. Nulo = puede entrar. Suspender guarda el asiento y la firma.                                                   |
| `activity_seen_at`          | timestamp       | sí             | Cuándo abrió por última vez la campana del registro ([08](08-actividad.md)).                                                               |
| `password`                  | varchar(255)    | no             | Hash de la contraseña. Nunca texto plano.                                                                                                  |
| `remember_token`            | varchar(100)    | sí             | Testigo de sesión persistente.                                                                                                             |
| `created_at` · `updated_at` | timestamp       | sí             | Alta y última modificación.                                                                                                                |

### Invariantes

- **`email` es único**; `name` no. Buscar a alguien por nombre puede devolver varias filas.
- **No hay columna de foto.** La foto de un periodista se publica, así que vive en la
  biblioteca de medios y cuelga de su firma (documento 2), donde tiene texto alternativo,
  crédito, licencia y encuadre. Una cuenta sin foto no es un dato incompleto: quien la
  pinte dibuja un monograma con las iniciales.
- Una cuenta **puede no tener rol**. Es el caso de las cuentas de servicio, con las que nadie
  entra.

---

## `roles` y `permissions` — el vocabulario

| Columna                     | Tipo            | Nulo | Qué es                                 |
| --------------------------- | --------------- | ---- | -------------------------------------- |
| `id`                        | bigint unsigned | no   | Clave primaria.                        |
| `name`                      | varchar(255)    | no   | Nombre del rol o del permiso.          |
| `guard_name`                | varchar(255)    | no   | Ámbito de autenticación al que aplica. |
| `created_at` · `updated_at` | timestamp       | sí   | Alta y última modificación.            |

`name` + `guard_name` forman la pareja única en las dos tablas.

### Los siete roles

| Rol           | Para quién                                                                                       |
| ------------- | ------------------------------------------------------------------------------------------------ |
| `admin`       | Control total, incluida la gestión de cuentas.                                                   |
| `director`    | Dirección: publica, edita cualquier pieza y compone la portada.                                  |
| `editor`      | Mesa de edición: publica y edita piezas ajenas.                                                  |
| `redactor`    | Plantilla: escribe lo suyo y lo manda a revisión; **no publica**. Ve el sumario de la redacción. |
| `colaborador` | Escribe lo suyo pero **no publica**: entrega y espera. Único rol fuera de la sala.               |
| `moderador`   | Comentarios, lectores y suscriptores. No escribe artículos.                                      |
| `reader`      | Cuenta de lectura registrada. Sin acceso editorial.                                              |

El reparto lo escribe `SnkKilkanaRoleCatalogService` (`PANEL_ROLES` son los seis que abren el
panel; `WITHOUT_SEAT`, `reader` y `service`), y lo reaplica `kilkana:sync-permissions` en cada
inquilino ([`operations.md`](../../operations.md)).

### Los tres permisos que no salen del CRUD

El CRUD por recurso (`index/show/create/update/delete` × recurso) no describe un periódico,
porque funde en «editar artículos» tres cosas que en una redacción están separadas. Por eso
existen tres permisos más, y son los que de verdad reparten el poder:

- **`publish posts`** — quien firma no decide cuándo sale. Sin este permiso, una cuenta
  puede crear y editar sus piezas pero no ponerlas en estado `published` ni `scheduled`.
- **`edit others posts`** — sin él, el listado, la edición, el historial y el borrado se
  acotan a las piezas cuyo `created_by` es esa cuenta. Una pieza ajena responde **404, no
  403**: los identificadores son correlativos y un 403 confirmaría cuántas piezas hay.
- **`view others posts`** — **saber que existe** lo ajeno, que es otra pregunta. Abre el
  sumario de la redacción —los recuentos por estado del periódico entero, `counts_all` en
  `GET posts`— sin dar acceso a una sola letra ajena.

Componer la portada no necesita permiso propio: ya es `update layouts`, y ahí está la línea
entre la dirección y la mesa de edición.

### Ver lo ajeno y editarlo son dos preguntas

Si un solo permiso contestara las dos, el sumario de la redacción se serviría a cualquiera que
abriese el panel —`colaborador` incluido— o no se serviría a nadie que no pudiera editar.
Ninguna de las dos es correcta: la primera publica actividad editorial ajena a quien entrega
desde fuera; la segunda deja al redactor con su «Published 34» al lado de una sección con 40
del periódico entero, que se lee como una contradicción.

**La línea no es «puede editar / no puede editar», es plantilla contra fuera**: un redactor
«writes and sends to review», un colaborador «files drafts from outside the newsroom». Así
funciona una redacción — la reunión de sumario es todo el mundo sabiendo en qué anda el resto,
y a quien se le oculta la planificación es a quien no está en la sala. Por eso lo decide el rol
y **no hay ajuste por cliente**: para recortar visibilidad ya existe una palanca, y dos
mecanismos que hacen lo mismo por caminos distintos son dos fuentes de verdad que un día
discrepan.

`moderador` entra aunque por necesidad no le haga falta —modera comentarios de piezas ya
publicadas—: dejarlo fuera reintroduce la excepción por rol que este permiso viene a quitar.

**`edit others posts` implica ver, y la implicación es de la consulta.** Quien edita lo ajeno lo
tiene delante en el listado, así que `countsByStatus()` le devuelve las dos escalas iguales sin
mirar el segundo permiso: a `director` y `editor` se les concede `view others posts` para que el
reparto se lea entero en una columna, pero quitárselo no les cambiaría el tablero. Donde el
permiso decide de verdad es en `redactor` y `moderador`, que no editan lo ajeno.

Esa implicación es lo que disfraza un despliegue a medias: si el permiso no llega a un periódico
—**`core:migrate --all-tenants` rechaza `--seed`**, así que no llega solo—, los tres roles que
editan siguen viendo el sumario y los dos que no lo pierden en silencio. Por eso existe
`kilkana:sync-permissions`.

Sin el permiso, `counts_all` **se iguala a `counts`, no se omite**. El panel deduce de que las
dos cifras coincidan que hay una sola escala que dibujar, así que quitar la clave le dejaría la
barra sin saber qué medir.

### Acotar mira quién TECLEÓ, no quién firma

El acotado usa `kilkana_posts.created_by`, no `author_id`. Quien escribe y quien firma
pueden ser distintos: un breve tecleado por una redactora puede ir firmado por la casa.

---

## `model_has_roles`, `model_has_permissions`, `role_has_permissions` — el reparto

Tres pivotes. Los dos primeros cuelgan de una cuenta; el tercero une rol y permiso.

| Columna                     | Qué es                                                             |
| --------------------------- | ------------------------------------------------------------------ |
| `role_id` / `permission_id` | El rol o permiso asignado.                                         |
| `model_type`                | Tipo de entidad que lo recibe (en la práctica, siempre la cuenta). |
| `model_id`                  | Identificador de esa entidad, es decir `users.id`.                 |

Los permisos efectivos de una cuenta son la unión de los que le llegan por su rol
(`model_has_roles` → `role_has_permissions`) y los que tenga asignados directamente
(`model_has_permissions`).

### Lo que el panel lee y escribe de una cuenta

Solo las cuentas con asiento pasan por `users`: `reader` y `service` no salen en el listado ni se
pueden asignar desde él (`not_in` en la validación del rol). Cada fila viaja con el rol plano, el
`state` —`active`, `invited` o `suspended`, derivado de las dos fechas—, `last_active_at` —la
última vez que un token suyo se usó— y `posts_count` —lo que firma su cuenta—.

- **`POST users/invite`** `{email, full_name, role}` crea la cuenta invitada con una contraseña
  que nadie conoce y manda el correo con el enlace a `/admin/password` del periódico. Si el
  correo no sale, la cuenta se borra y contesta 502: una invitada sin invitación es un asiento
  pagado al que nadie llega.
- **`POST users/{id}/invitation`** reenvía el enlace; 409 si la cuenta ya entró.
  **`POST users/{id}/password-link`** manda el de recuperación; 409 si sigue invitada.
- **`POST auth/register`** `{name, email, password}` es el alta de un lector desde la calle,
  público y con freno: nace con el papel `reader` —`create comments` y nada más— y contesta como
  el acceso, con su token. Un correo que ya existe es un 422, como en cualquier registro, **y eso
  dice que existe**: es lo que `auth/password-link` calla con su 202. Se acepta, y el 202 de la
  recuperación queda como coherencia interna y no como defensa; cerrarlo sería mandar el «ya
  tienes cuenta» al correo en vez de al navegador.
- **`POST auth/password-link`** `{email}` es el mismo enlace pedido por quien lo olvidó, sin
  sesión y con freno: contesta 202 exista la cuenta o no, solo lo manda a una cuenta con asiento
  que ya entró y no está suspendida, y lo encola en vez de mandarlo: un envío síncrono tardaría
  solo cuando la cuenta existe. El enlace se construye con `tenant_url` de los ajustes (o
  `https://{cliente}.{dominio base}`), nunca con la cabecera `Host`.
- **`POST` / `DELETE users/{id}/suspension`** suspende y restaura. Suspender tira sus tokens, y
  `auth/login` contesta 403 «Account suspended» **después** de comprobar la contraseña, para no
  confirmar a quien no la sabe que la cuenta existe.
- **`POST auth/password`** `{email, token, password}` canjea el enlace del correo: pone la
  contraseña, vacía `invited_at`, tira las sesiones y gasta el token. Público, con freno propio
  (`throttle:6,1`): el del login solo cuenta los 401, y un canje fallido es un 422.
- **Nadie se toca a sí mismo**: cambiarse el rol es 422, y suspenderse o borrarse 409.
- **`GET roles`** (tras `index users`) lista los siete con sus permisos, cuánta gente los tiene y
  si ocupan asiento. De solo lectura: el reparto lo escribe `SnkKilkanaRoleCatalogService`.

### Notas

- El modelo admite **varios roles por cuenta**, pero un periódico reparte un puesto por
  persona: en la práctica hay uno o ninguno.
- Reasignar los permisos de un rol **sustituye** su lista entera, no la amplía. Es lo que
  hace que volver a sembrar los roles repare uno que alguien haya tocado a mano.
- Borrar un rol o un permiso arrastra sus filas de pivote (`CASCADE`).
