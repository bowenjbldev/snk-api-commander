# 1 · Cuentas y permisos

Quién existe en el periódico y qué puede hacer.

Son seis tablas: una de cuentas, dos de vocabulario (roles y permisos) y tres pivotes que
los reparten.

---

## `users` — las cuentas

Una fila por persona con acceso, más las cuentas de servicio. Es la identidad: **el correo
es único y es lo que identifica a alguien**, no el nombre.

| Columna | Tipo | Nulo | Qué es |
|---|---|---|---|
| `id` | bigint unsigned | no | Clave primaria. |
| `name` | varchar(255) | no | Nombre de trabajo, el que se ve en pantalla y con el que se firma si no hay alias. Puede repetirse: dos personas pueden llamarse igual. |
| `full_name` | varchar(255) | sí | Nombre completo, con los dos apellidos. Sirve para desempatar a dos homónimos. |
| `company` | varchar(255) | sí | Organización a la que pertenece. Para la plantilla, el propio periódico; para quien colabora desde fuera, la suya. **No sale al público.** |
| `phone` | varchar(50) | sí | Teléfono de contacto interno. |
| `email` | varchar(255) | no · **único** | La identidad. |
| `email_verified_at` | timestamp | sí | Cuándo se verificó el correo. Nulo = sin verificar. |
| `password` | varchar(255) | no | Hash de la contraseña. Nunca texto plano. |
| `remember_token` | varchar(100) | sí | Testigo de sesión persistente. |
| `created_at` · `updated_at` | timestamp | sí | Alta y última modificación. |

### Invariantes

- **`email` es único**; `name` no. Buscar a alguien por nombre puede devolver varias filas.
- **No hay columna de foto.** La foto de un periodista se publica, así que vive en la
  biblioteca de medios y cuelga de su firma (documento 2), donde tiene texto alternativo,
  crédito, licencia y encuadre. Una cuenta sin foto no es un dato incompleto: quien la
  pinte dibuja un monograma con las iniciales.
- Una cuenta **puede no tener rol**. Es el caso de las cuentas de servicio, que existen solo
  para sostener una firma colectiva («Redacción», «Agencias») y con las que nadie entra.

---

## `roles` y `permissions` — el vocabulario

| Columna | Tipo | Nulo | Qué es |
|---|---|---|---|
| `id` | bigint unsigned | no | Clave primaria. |
| `name` | varchar(255) | no | Nombre del rol o del permiso. |
| `guard_name` | varchar(255) | no | Ámbito de autenticación al que aplica. |
| `created_at` · `updated_at` | timestamp | sí | Alta y última modificación. |

`name` + `guard_name` forman la pareja única en las dos tablas.

### Los siete roles

| Rol | Para quién |
|---|---|
| `admin` | Control total, incluida la gestión de cuentas. |
| `director` | Dirección: publica, edita cualquier pieza y compone la portada. |
| `editor` | Mesa de edición: publica y edita piezas ajenas. |
| `redactor` | Plantilla: escribe y publica **lo suyo**. |
| `colaborador` | Escribe lo suyo pero **no publica**: entrega y espera. |
| `moderador` | Comentarios y suscriptores. No escribe artículos. |
| `reader` | Cuenta de lectura registrada. Nada del panel. |

### Los dos permisos que no salen del CRUD

El CRUD por recurso (`index/show/create/update/delete` × recurso) no describe un periódico,
porque funde en «editar artículos» tres cosas que en una redacción están separadas. Por eso
existen dos permisos más, y son los que de verdad reparten el poder:

- **`publish posts`** — quien firma no decide cuándo sale. Sin este permiso, una cuenta
  puede crear y editar sus piezas pero no ponerlas en estado `published` ni `scheduled`.
- **`edit others posts`** — sin él, el listado, la edición, el historial y el borrado se
  acotan a las piezas cuyo `created_by` es esa cuenta. Una pieza ajena responde **404, no
  403**: los identificadores son correlativos y un 403 confirmaría cuántas piezas hay.

Componer la portada no necesita permiso propio: ya es `update layouts`, y ahí está la línea
entre la dirección y la mesa de edición.

### Acotar mira quién TECLEÓ, no quién firma

El acotado usa `kilkana_posts.created_by`, no `author_id`. Quien escribe y quien firma
pueden ser distintos: un breve tecleado por una redactora puede ir firmado por la casa.

---

## `model_has_roles`, `model_has_permissions`, `role_has_permissions` — el reparto

Tres pivotes. Los dos primeros cuelgan de una cuenta; el tercero une rol y permiso.

| Columna | Qué es |
|---|---|
| `role_id` / `permission_id` | El rol o permiso asignado. |
| `model_type` | Tipo de entidad que lo recibe (en la práctica, siempre la cuenta). |
| `model_id` | Identificador de esa entidad, es decir `users.id`. |

Los permisos efectivos de una cuenta son la unión de los que le llegan por su rol
(`model_has_roles` → `role_has_permissions`) y los que tenga asignados directamente
(`model_has_permissions`).

### Notas

- El modelo admite **varios roles por cuenta**, pero un periódico reparte un puesto por
  persona: en la práctica hay uno o ninguno.
- Reasignar los permisos de un rol **sustituye** su lista entera, no la amplía. Es lo que
  hace que volver a sembrar los roles repare uno que alguien haya tocado a mano.
- Borrar un rol o un permiso arrastra sus filas de pivote (`CASCADE`).
