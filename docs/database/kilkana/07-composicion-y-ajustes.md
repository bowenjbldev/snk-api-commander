# Composición y ajustes

Cómo se arma la portada y qué identidad tiene el periódico. Tres tablas pequeñas y muy
distintas del resto: no guardan contenido, guardan **configuración versionada**.

---

## `kilkana_layouts` — la composición de las páginas

| Columna | Tipo | Nulo | Qué es |
|---|---|---|---|
| `id` | bigint unsigned | no | Clave primaria. |
| `layout` | enum | no · **único** | Qué página compone: `front` · `section`. |
| `schema` | longtext (JSON) | no | La composición entera. |
| `version` | int unsigned | no · def. 1 | Contador para escrituras concurrentes. |
| `created_at` · `updated_at` | timestamp | sí | Alta y última modificación. |

Hay **una fila por tipo de página**, no una por página. Dos en total:

| `layout` | Compone |
|---|---|
| `front` | La portada. |
| `section` | **Todas** las páginas de sección. |

Que `section` sea una sola fila es deliberado: todas las secciones comparten composición y
lo que cambia entre ellas es el conjunto de artículos que se les da. Deportes y Cultura se
ven igual porque son la misma plantilla con otro contenido.

### `schema` es el documento

Un JSON con la estructura de la página: bandas, columnas y los módulos dentro de cada
columna con su configuración. La base no valida su forma, así que **cualquier lectura debe
tratarlo como entrada no confiable**: normalizarlo y descartar lo que no reconozca en vez
de confiar en que esté bien.

Esa normalización es lo que hace que retirar un tipo de módulo no invalide una composición
guardada: la que traiga un módulo desconocido pierde ese módulo y conserva el resto.

### `version` es control de concurrencia optimista

Quien guarda manda la versión que leyó. Si no coincide con la almacenada, alguien ha
guardado en medio y la escritura se rechaza en vez de pisar su trabajo.

**El conflicto no debe resolverse solo.** Adoptar la versión remota en silencio pierde uno
de los dos cambios sin que nadie lo sepa; el rechazo tiene que llegar hasta la persona.

**Y la comprobación tiene que ir bajo el mismo bloqueo que la escritura**, o el contador no
sirve para nada. Leer la versión, compararla y después guardar deja un hueco por el que cabe
otro guardado entero: los dos leen la 3, los dos pasan la comprobación y los dos escriben la
4. Medido sobre seis guardados simultáneos declarando la misma versión, **dos respondían 200**
y uno de los dos perdía su trabajo sin que nada fallara. Con la fila bloqueada desde la
lectura hasta confirmar, el segundo espera, relee la versión ya subida y recibe su conflicto.

Le aplica igual a **restaurar** una revisión, que también sube la versión y por tanto compite
con un guardado normal.

---

## `kilkana_layout_revisions` — el historial de composiciones

| Columna | Tipo | Nulo | Qué es |
|---|---|---|---|
| `id` | bigint unsigned | no | Clave primaria. |
| `layout_id` | bigint unsigned | no | Composición. **CASCADE.** |
| `user_id` | bigint unsigned | sí | Quién guardó. SET NULL. |
| `schema` | longtext (JSON) | no | El documento **entero**, tal como estaba. |
| `created_at` | timestamp | **no** | Cuándo se guardó. |

Mismo patrón que el historial de artículos: copia completa, no diferencia, y sin
`updated_at` porque una revisión no se modifica.

Aquí importa especialmente, porque hay operaciones que **reemplazan la composición entera**
—aplicar una plantilla, por ejemplo—. Sin historial, eso sería irreversible.

---

## `kilkana_settings` — la identidad del periódico

| Columna | Tipo | Nulo | Qué es |
|---|---|---|---|
| `id` | tinyint unsigned | no | Clave primaria no autoincremental. **Siempre 1.** |
| `theme` | varchar(64) | sí | La piel visual elegida. |
| `tenant_name` | varchar(255) | sí | Cómo se llama el periódico. |
| `tenant_tagline` | varchar(255) | sí | Su lema. |
| `tenant_city` | varchar(255) | sí | Su ciudad. |
| `tenant_url` | varchar(255) | sí | Su dirección pública. Validada `url:http,https`. |
| `theme_overrides` | longtext (JSON) | sí | Ajustes locales sobre esa piel. |
| `onboarded` | boolean | no · def. `false` | Si ya pasó la puesta en marcha inicial. |
| `version` | int unsigned | no · def. 1 | Igual que en `kilkana_layouts`. |
| `created_at` · `updated_at` | timestamp | sí | Alta y última modificación. |

**Una sola fila**, con `id = 1`. La migración añade `CHECK (id = 1)`, por lo que MariaDB rechaza
una segunda identidad con otro id. Es una excepción histórica a la convención `$table->id()` del
proyecto y no debe copiarse a tablas nuevas.

Los campos de `tenant_*` son la **identidad publicable**, y salen de aquí a la cabecera del
periódico. Con `tenant_name` nulo, quien la pinte tendrá que inventarse un valor por defecto —
y entonces el nombre del periódico vive en el cliente, que es justo lo contrario de lo que un
producto multi-inquilino necesita.

`tenant_url` no está por completar la ficha: **todo correo que sale necesita enlazar a algún
sitio**, y el enlace de un boletín no puede ser relativo. Sin ella, confirmar la suscripción y
darse de baja no tienen a dónde devolver al lector, y responden texto plano en vez de redirigir.
Va validada `url:http,https` y no `url` a secas: se pinta como enlace y sale en cada correo,
así que `javascript:` aquí sería un agujero, igual que lo era en la web de una firma.

La API los sirve **anidados** (`tenant: {name, tagline, city, url}`) aunque debajo sean columnas
planas: es la forma que ya consumía la salida pública, y el mapeo entre las dos vive en un
solo sitio.

### Por qué columnas y no una bolsa JSON

Esto **fue** una columna `settings` con todo dentro. La bolsa cobraba el precio de la
flexibilidad sin darla: quien escribe ya enumera cada clave que acepta, así que nunca hubo
claves arbitrarias. A cambio se perdían los tipos —`onboarded` guardado como lo que cayera— y
había que fusionar a mano para que un guardado parcial no borrara lo que no venía, con un caso
especial para `tenant` que alguien tenía que acordarse de replicar en el siguiente ajuste
anidado. El día que se olvidara, guardar la ciudad habría borrado el nombre en silencio.

Con columnas eso desaparece: se escribe lo que llega y lo demás se queda donde estaba.

**`theme_overrides` sigue siendo JSON, y esa es la diferencia entre usar JSON y esconderse en
él**: es un mapa de variante → token → valor, abierto por definición y opaco para la base. Su
forma la normaliza quien escribe, por tipo y por rango — un valor fuera de banda puede dejar
texto ilegible sobre su fondo.

> La tabla hermana `commander_settings` sigue siendo una bolsa JSON. La divergencia es
> consciente: aquellos son ajustes de infraestructura del operador, y estos los toca una
> redacción desde una pantalla y salen al público.

---

## Aviso al recrear la base

Estas tres tablas guardan **decisiones, no contenido editorial**. El seeder repone snapshots
base de layouts y settings, así que una instalación limpia arranca con estructura; no puede
reconstruir las decisiones hechas después por una redacción. Un `--fresh` sin backup seguido de
seed recupera los defaults, no la portada ni la identidad personalizadas.
