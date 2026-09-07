# Composición y ajustes

Cómo se arma la portada, qué aspecto tiene el periódico y qué identidad publica. Cinco tablas
pequeñas y muy distintas del resto: no guardan contenido, guardan **configuración**.

---

## `kilkana_layouts` — la composición de las páginas

| Columna                     | Tipo            | Nulo           | Qué es                                   |
| --------------------------- | --------------- | -------------- | ---------------------------------------- |
| `id`                        | bigint unsigned | no             | Clave primaria.                          |
| `layout`                    | enum            | no · **único** | Qué página compone: `front` · `section`. |
| `schema`                    | longtext (JSON) | no             | La composición entera.                   |
| `version`                   | int unsigned    | no · def. 1    | Contador para escrituras concurrentes.   |
| `created_at` · `updated_at` | timestamp       | sí             | Alta y última modificación.              |

Hay **una fila por tipo de página**, no una por página. Dos en total:

| `layout`  | Compone                                                           |
| --------- | ----------------------------------------------------------------- |
| `front`   | La portada y las secciones.                                       |
| `section` | Reservada: todas las páginas de sección compartirían composición. |

### `schema` es el documento

Lo que el panel escribe en `front` es su propio documento: `{"version": 1, "sections":
[{"id": "home" | slug de sección, "bands": [...]}]}`, una entrada por edición abierta con las
bandas tal cual las edita el composer —reparto, filas, piezas elegidas por su id—. La fila
`section` no se usa: cada sección se compone aparte dentro de `front`, y las que nadie publica
salen con la plantilla del tema. La calle lo lee por `public/layouts/front` y lo normaliza en el
cliente; aquí solo se exige `schema.version` entero y `schema.sections` presente.

La base no valida su forma, así que **cualquier lectura debe tratarlo como entrada no
confiable**: normalizarlo y descartar lo que no reconozca en vez de confiar en que esté bien.
Esa normalización es lo que hace que retirar un tipo de módulo no invalide una composición
guardada: la que traiga un módulo desconocido pierde ese módulo y conserva el resto.

### `version` es control de concurrencia optimista

Quien guarda manda la versión que leyó (`PUT layouts/{layout}` la exige). Si no coincide con la
almacenada, alguien ha guardado en medio y la escritura se rechaza con 409 `version_conflict` y
el estado actual, en vez de pisar su trabajo.

**El conflicto no debe resolverse solo.** Adoptar la versión remota en silencio pierde uno
de los dos cambios sin que nadie lo sepa; el rechazo tiene que llegar hasta la persona.

**Y la comprobación tiene que ir bajo el mismo bloqueo que la escritura**, o el contador no
sirve para nada. Leer la versión, compararla y después guardar deja un hueco por el que cabe
otro guardado entero: los dos leen la 3, los dos pasan la comprobación y los dos escriben la
4, los dos reciben 200 y uno pierde su trabajo sin que nada falle. Con la fila bloqueada desde
la lectura hasta confirmar, el segundo espera, relee la versión ya subida y recibe su conflicto.

Le aplica igual a **restaurar** una revisión, que también sube la versión y por tanto compite
con un guardado normal.

---

## `kilkana_layout_revisions` — el historial de composiciones

| Columna      | Tipo            | Nulo   | Qué es                                    |
| ------------ | --------------- | ------ | ----------------------------------------- |
| `id`         | bigint unsigned | no     | Clave primaria.                           |
| `layout_id`  | bigint unsigned | no     | Composición. **CASCADE.**                 |
| `user_id`    | bigint unsigned | sí     | Quién guardó. SET NULL.                   |
| `schema`     | longtext (JSON) | no     | El documento **entero**, tal como estaba. |
| `created_at` | timestamp       | **no** | Cuándo se guardó.                         |

Mismo patrón que el historial de artículos: copia completa, no diferencia, y sin
`updated_at` porque una revisión no se modifica. `GET layouts/{layout}/revisions` (`show
layouts`) y `POST …/revisions/{revision}/restore` (`update layouts`).

Aquí importa especialmente, porque hay operaciones que **reemplazan la composición entera**
—aplicar una plantilla, por ejemplo—. Sin historial, eso sería irreversible.

---

## Los temas: un catálogo vendible dentro de cada periódico

Qué aspecto tiene el periódico, y qué se le puede vender. Tres tablas: el tema, el vocabulario
con el que se navega y el pivote que los une.

**Un tema es un `spec`, y no una familia de pieles.** No hay variantes: si dos pieles no se
reconocen como el mismo periódico son dos temas, y si se reconocen, lo que las separa —el acento,
el aire, el tamaño del titular— es justo lo que el periódico ajusta encima del tema que ya lleva
puesto, y eso vive en `kilkana_settings`. Un nivel intermedio obligaría a elegir dos veces antes
de ver un periódico y triplicaría el trabajo de diseñar cada tema.

**Y un tema entero es un dato.** Los tokens, la tipografía, la rejilla, la cabecera (un enum de
dos valores) y las plantillas de arranque son todo JSON, así que viven en `kilkana_themes.spec` y
publicar un tema nuevo no exige desplegar código. Eso permite producirlos deprisa y venderlos: el
consumidor solo necesita implementar el contrato del spec.

El catálogo **se replica en la base de cada periódico** y su maestro son ficheros versionados
en `database/seeders/data/themes/` (`_categories.json` y un fichero por tema).
`kilkana:sync-themes` los publica: compara por `version`, así que un catálogo de trescientos
temas sin cambios no escribe una fila. Puede reescribir el catálogo entero sin mirar a quién
viste qué: **el vestido de un periódico no es una fila de esta tabla**, es una copia congelada en
sus ajustes.

**`kilkana_themes`** — el tema vendible:

| Columna                                       | Qué es                                                                                            |
| --------------------------------------------- | ------------------------------------------------------------------------------------------------- |
| `slug` **único**                              | Identidad estable; la clave del fichero maestro                                                   |
| `name` · `tagline` · `description`            | La tarjeta del catálogo. FULLTEXT sobre las tres                                                  |
| `status`                                      | `draft` · `published` · `deprecated`. Solo se ofrece lo publicado                                 |
| `featured` · `position` · `popularity`        | Los tres órdenes curados                                                                          |
| `spec`                                        | **El tema entero**: `masthead`, `grid`, `tokens`, `type`, `media`, `density`, `chrome`, `layouts` |
| `version`                                     | Contra esta compara el sincronizador, y con ella se avisa de una actualización                    |
| `thumbnail_path` · `screenshots` · `demo_url` | Assets del producto, fuera de `kilkana_media`                                                     |
| `published_at`                                | Se sella al publicarlo por primera vez; ordena «novedades»                                        |

**`kilkana_theme_categories`** + **`kilkana_theme_category_theme`** — el vocabulario de
filtrado (`news`, `anime`, `technology`…) y su pivote N:M. Es cerrado y lo publica el fichero
maestro: una categoría que se pudiera crear al vuelo dejaría de ser un filtro y se convertiría
en la lista de sinónimos de quien la escribió, que es justo lo que pasa con `kilkana_tags`.

La API solo lee el catálogo: `GET themes`, `GET theme-categories` y `POST themes/{id}/wear`.

### `popularity` es un número curado, no medido

Con el catálogo replicado por inquilino, cada base solo se ve a sí misma: **no hay dónde contar
cuántos periódicos visten un tema**. El número lo fija quien publica. Medirlo de verdad exigiría
un contador global en el control plane, que es la pieza que se descartó al elegir el catálogo
por inquilino.

### Vestir es copiar, y por eso publicar no rompe nada

**Vestir un tema copia su `spec` a `kilkana_settings`** y anota su `version`. Si los ajustes
apuntaran al spec de la fila del catálogo, la siguiente sincronización lo reescribiría y el
periódico amanecería con otra portada, sin pedirlo y sin rastro.

Con la copia, publicar la versión 2 de un tema deja intacto a quien lleva la 1, y comparar
`kilkana_settings.theme_version` con la publicada es lo que enciende el aviso de «hay una
actualización» — que acepta la redacción, no el catálogo.

Por eso `kilkana_settings.theme_id` es **SET NULL** y no RESTRICT: como el vestido es la copia,
retirar un tema del catálogo deja al periódico vestido igual, y lo único que pierde es el aviso.

### Qué puede tocar una redacción, y qué no

El `spec` lo trae el catálogo entero. Lo que la redacción cambia encima —`theme_overrides`— es un
subconjunto acotado: los 15 colores, el radio de página, el peso, la escala y el tracking del
titular y la densidad de bloque. El lenguaje kicker/tarjeta, la cabecera, la rejilla y las
plantillas pertenecen al tema, y cambiarlos es cambiar de tema.

Los rangos (hex de seis dígitos, radio 0–12px, peso 600–800 en centenas, escala 0.8–1.1,
tracking −0.04–0.02em, padding 10–18px) están declarados una sola vez en el modelo y los miran
dos validaciones: la del retoque parcial que manda el inspector y la del spec completo que trae
el catálogo. El servidor responde 422; el cliente además normaliza al leer, porque una fila
tocada a mano en la base no debe poder tumbar la portada.

### Un tema no entra si rompe la accesibilidad

`kilkana:sync-themes` valida cada spec **antes de escribir nada** (`SnkKilkanaThemeSpecValidator`)
y falla ruidosamente con el par de colores en la mano. Se comprueba el contraste AA (≥4.5:1) de
`text`, `textMuted`, `textSoft` y `accent` sobre `surface`, los rangos, que `masthead` sea uno de
los soportados y que todos los bloques de las plantillas existan.

Es la contrapartida de producir temas deprisa: un gris elegante sobre blanco se ve bien en una
captura y deja por debajo de AA al periódico que lo instale, y este producto tiene presupuestos
Lighthouse de 100. Un bloque desconocido tampoco se descarta aquí —el normalizador del editor sí
lo hace, porque allí protege de composiciones viejas—: en el catálogo significa que esa banda no
se va a pintar y nadie lo sabría.

---

## `kilkana_settings` — la identidad del periódico

| Columna                     | Tipo             | Nulo                      | Qué es                                                                                                                                                                                 |
| --------------------------- | ---------------- | ------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `id`                        | tinyint unsigned | no                        | Clave primaria no autoincremental. **Siempre 1.**                                                                                                                                      |
| `theme_id`                  | bigint unsigned  | sí                        | De qué tema del catálogo salió el vestido. **SET NULL.**                                                                                                                               |
| `theme_spec`                | longtext (JSON)  | sí                        | **El vestido**: la copia congelada del spec el día que se eligió. Es lo que se dibuja.                                                                                                 |
| `theme_overrides`           | longtext (JSON)  | sí                        | Lo que la redacción movió encima, como diferencia: colores, densidad y el peldaño de apertura. Un solo mapa, el del tema puesto.                                                       |
| `theme_version`             | varchar(16)      | sí                        | La versión copiada. Contra la del catálogo enciende el aviso de actualización.                                                                                                         |
| `tenant_name`               | varchar(255)     | sí                        | Cómo se llama el periódico.                                                                                                                                                            |
| `tenant_tagline`            | varchar(255)     | sí                        | Su lema.                                                                                                                                                                               |
| `tenant_city`               | varchar(255)     | sí                        | Su ciudad.                                                                                                                                                                             |
| `tenant_url`                | varchar(255)     | sí                        | Su dirección pública. Validada `url:http,https`.                                                                                                                                       |
| `onboarded`                 | boolean          | no · def. `false`         | Si ya pasó la puesta en marcha inicial.                                                                                                                                                |
| `lang`                      | varchar(8)       | no · def. `es-ES`         | El idioma de la hoja: `lang`, `hreflang`, `inLanguage` y el formato de fecha. Lista cerrada en `SnkKilkanaSettingModel::LANGS` (`es-ES`, `ca-ES`, `gl-ES`, `eu-ES`, `pt-PT`, `en-GB`). |
| `tz`                        | varchar(64)      | no · def. `Europe/Madrid` | La zona horaria de la redacción: la hora de pared del panel y el sello del JSON-LD. Validada `timezone:all`.                                                                           |
| `search_visible`            | boolean          | no · def. `true`          | Si la calle se deja indexar. Apagada, `robots.txt` cierra y toda hoja sale `noindex`: el periódico sigue abierto por su dirección, fuera de los buscadores hasta el lanzamiento.       |
| `newsletter_reply_to`       | varchar(255)     | sí                        | A quién contestan los lectores del boletín. La dirección de envío es de la instalación y se lee, no se escribe.                                                                        |
| `legal`                     | longtext (JSON)  | sí                        | Grupo abierto: `name` (la razón social) y `email` (el contacto). Normalizado al escribir: solo esas claves, recortadas, nulas si vacías.                                               |
| `social`                    | longtext (JSON)  | sí                        | Grupo abierto: `x`, `facebook`, `instagram`, como URL `http(s)`. Salen en el `sameAs` del JSON-LD.                                                                                     |
| `created_at` · `updated_at` | timestamp        | sí                        | Alta y última modificación.                                                                                                                                                            |

`GET settings` (`show settings`) y `PUT settings` (`update settings`).

### Sin control de concurrencia, y dicho en voz alta

`theme_id` es clave foránea y no un `varchar` con el slug: la base rechaza vestir un tema que no
existe, y con texto libre guardar «stándar» respondería 200 y el público caería al primer tema en
silencio.

Los ajustes no llevan `version`: los toca poca gente y rara vez, así que la política es
last-write-wins. Un contador optimista que el cliente no manda —una regla `nullable` que nadie
rellena— se salta siempre y devuelve una versión que no garantiza nada, peor que no tenerlo
porque parece una garantía. `kilkana_layouts` **sí** lo lleva y allí es obligatorio: dos personas
componiendo la portada a la vez es el caso normal.

### La regla de crecimiento

Esta tabla va a crecer, y no crece de cualquier manera:

- **Columna** si el ajuste tiene tipo o clave foránea (`logo_media_id`, `default_og_image_id`).
  Es lo que la base puede defender, así que que lo defienda.
- **Columna JSON de grupo** si es un grupo abierto (`legal`, `social`, `comments`, `paywall`,
  `integrations`), normalizada al leer y al escribir. Añadir una clave dentro del grupo no toca
  la base, que es justo lo que ahorra la barrida de migraciones sobre todas las bases de
  inquilino.
- **Lo que no entra** es la bolsa única con todo dentro, ni una tabla clave-valor: aquí la
  clave siempre la conoce el código. El día que la elija un inquilino o una extensión, eso es
  un registro aparte (definiciones + valores), no convertir esta tabla.

**Una sola fila**, con `id = 1`. La migración añade `CHECK (id = 1)`, por lo que MariaDB rechaza
una segunda identidad con otro id. Es una excepción a la convención `$table->id()` del proyecto
y no se copia a tablas nuevas.

Los campos de `tenant_*` son la **identidad publicable**, y salen de aquí a la cabecera del
periódico. Con `tenant_name` nulo, quien la pinte tendrá que inventarse un valor por defecto —
y entonces el nombre del periódico vive en el cliente, que es justo lo contrario de lo que un
producto multi-inquilino necesita.

`tenant_url` no está por completar la ficha: **todo correo que sale necesita enlazar a algún
sitio**, y el enlace de un boletín no puede ser relativo. Sin ella, confirmar la suscripción y
darse de baja no tienen a dónde devolver al lector, y responden texto plano en vez de redirigir.
Va validada `url:http,https` y no `url` a secas: se pinta como enlace y sale en cada correo,
así que `javascript:` aquí sería un agujero, igual que en la web de una firma.

La API los sirve **anidados** (`tenant: {name, tagline, city, url}`) aunque debajo sean columnas
planas: es la forma que consume la salida pública, y el mapeo entre las dos vive en un solo
sitio.

### Por qué columnas y no una bolsa JSON

Una bolsa cobra el precio de la flexibilidad sin darla: quien escribe ya enumera cada clave que
acepta, así que nunca hay claves arbitrarias. A cambio se pierden los tipos —`onboarded`
guardado como lo que caiga— y hay que fusionar a mano para que un guardado parcial no borre lo
que no viene, con un caso especial para `tenant` que alguien tiene que acordarse de replicar en
el siguiente ajuste anidado. El día que se olvide, guardar la ciudad borra el nombre en
silencio. Con columnas eso desaparece: se escribe lo que llega y lo demás se queda donde estaba.

**`theme_spec` y `theme_overrides` son JSON, y esa es la diferencia entre usar JSON y esconderse
en él**: el primero es un tema entero, que ya es un contrato validado; el segundo, un mapa de
token → valor, abierto por definición y opaco para la base. Su forma la normaliza quien escribe,
por tipo y por rango — un valor fuera de banda puede dejar texto ilegible sobre su fondo.

> La tabla hermana `commander_settings` es una bolsa JSON. La divergencia es consciente:
> aquellos son ajustes de infraestructura del operador, y estos pertenecen al flujo editorial
> y salen al público.

---

## Aviso al recrear la base

Estas tablas guardan **decisiones, no contenido editorial**. El seeder repone snapshots base de
temas y settings, así que una instalación limpia arranca con estructura; no puede reconstruir
las decisiones hechas después por una redacción. Un `--fresh` sin backup seguido de seed
recupera los defaults, no la portada, ni los ajustes del tema, ni la identidad.

Y arrastra algo que no está en estas tablas: **`--fresh` vacía también `users`**, y el admin del
periódico lo crea el provisioning, no el seeder.

Con **datos demo y en local o testing** el seeder de Kilkana lo repone si falta, y solo entonces:
nace con `secret1234`, que **no es la contraseña del alta**. Es el agujero de recrear la base a
mano, y está cubierto a propósito.

Fuera de ese caso —un inquilino de verdad, o sin `KILKANA_SEED_DEMO`— la base recreada se queda
**sin cuenta administradora** aunque el contenido vuelva entero, y hay que reaprovisionar.
