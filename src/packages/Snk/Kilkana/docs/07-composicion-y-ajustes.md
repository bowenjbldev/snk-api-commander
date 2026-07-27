# 7 · Composición y ajustes

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
| `id` | bigint unsigned | no | Clave primaria. **Siempre 1.** |
| `settings` | longtext (JSON) | sí | Todos los ajustes. |
| `version` | int unsigned | no · def. 1 | Igual que en `kilkana_layouts`. |
| `created_at` · `updated_at` | timestamp | sí | Alta y última modificación. |

**Una sola fila**, con `id = 1`. No hay restricción que lo obligue: es una convención, y una
segunda fila sería ignorada en el mejor de los casos.

`settings` agrupa tres cosas:

```json
{
  "tenant":          { "name": "…", "tagline": "…", "city": "…" },
  "theme":           "identificador de la piel visual",
  "theme_overrides": { "…": "ajustes locales sobre esa piel" },
  "onboarded":       true
}
```

- **`tenant`** es la identidad publicable: cómo se llama el periódico, su lema y su ciudad.
  Con `name` nulo, quien pinte la cabecera tendrá que usar un valor por defecto.
- **`theme`** elige la piel visual.
- **`theme_overrides`** la ajusta sin dejar de ser esa piel. Debe normalizarse por tipo y
  por rango antes de guardarse: un valor fuera de banda puede dejar texto ilegible.
- **`onboarded`** marca si el periódico ya pasó por su puesta en marcha inicial.

La lista no está cerrada por la base: `settings` es un JSON libre y admite claves nuevas sin
migración. Eso es cómodo y tiene su precio — nada impide que una clave con el nombre mal
escrito se guarde y no la lea nadie.

---

## Aviso al recrear la base

Estas tres tablas guardan **decisiones, no contenido**, y nada las regenera a partir de otra
cosa. Vaciarlas deja el periódico con la portada en blanco y sin nombre, aunque los sesenta
artículos sigan intactos. Es la diferencia con `kilkana_posts`, que se puede volver a
sembrar: una composición hecha a mano solo existe aquí.
