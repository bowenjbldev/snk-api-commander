# Idioma del código

El código habla inglés y la prosa, español. Esta es la regla de todo `src/`, de los paquetes y de
los tests, y la misma que sigue [kilkana.app](../../kilkana.app/docs/code-language.md) con su
vocabulario de TypeScript.

## En inglés

- **Clases, interfaces, traits, enums y sus casos.**
- **Métodos y funciones**, también los helpers privados de los tests: `asDirector()`, no
  `comoDirectora()`; `makeRequest()`, no `peticion()`.
- **Variables, parámetros y propiedades**, incluidas las locales de dominio: `$section`, `$byline`,
  `$published`, `$canEditOthers`; no `$seccion`, `$firma`, `$publicadas`, `$puedeEditarAjenas`.
- **Constantes**: `EXTERNAL_BYLINES`, no `FIRMAS_EXTERNAS`.
- **Nombres de test**, porque en PHPUnit el nombre es un método: `test_a_writer_cannot_publish`.
- **Alias de SQL y claves de arrays que solo viven dentro del código**: `selectRaw('mime, COUNT(*) AS
  items')`, no `AS piezas`.
- **Los mensajes de la API**, que son contrato y los lee el panel.
- **La salida de consola y las excepciones internas**: la `$description` y las opciones de un comando
  Artisan, lo que imprimen `info`, `error`, `line` y `fail`, las líneas de log y las excepciones que
  solo ve quien ejecuta un comando o lee los logs (los errores del validador de temas, entre ellas).
- **Las claves de las constantes de datos que solo lee el propio código**, como las de
  `SnkKilkanaDemoProse::MATERIAL`: `prose`, `subheadings`, no `prosa`, `intertitulos`.

## En español

- Comentarios y docblocks.
- La documentación de `docs/` y los mensajes de commit.
- Los mensajes de las aserciones: `assertSame('local', $reseeded->category->slug, 'La sección vuelve
  a la del catálogo.')`.
- **Los datos**: titulares, nombres y textos que siembra el seeder, y también los valores por
  defecto que el código guarda en la base y enseña el panel —el título de un número sin título, el
  nombre de un lector sin nombre—.

## Lo que no se renombra aunque esté en español

**Un contrato publicado conserva su vocabulario**: rutas, claves de payload, tablas y columnas,
permisos, nombres de cola, de evento y de configuración, y las claves de los ficheros de datos del
seeder (`data/*.json`). Cambiar uno de esos es un cambio de contrato, con su migración y sus dos
lados, y no un renombrado.

Los nombres propios de las fixtures —`$marta`, `$ramon`— no son palabras españolas: se quedan.

**Los comentarios de serie de Laravel y de los paquetes** —los bloques de `config/` y de los
ficheros que publica un `vendor:publish`— se quedan como vienen, en inglés: son de terceros y
vuelven a salir enteros al republicar. Lo que el equipo escribe encima o al lado va en español.

## Vocabulario del proyecto

Algunas palabras tienen ya un nombre inglés fijo, y se usa ese:

| Concepto | Nombre | No |
|---|---|---|
| la web pública del periódico, la que lee el lector | `street` | `public`, `site` |
| el servicio del registro de actividad, inyectado | `$activityLog` | `$record`, `$registro` |
| el estado de una sección que aún no se ve | `draft` | `preparation` |
| una pieza del periódico | `post` | `piece`, `article` |
| aplicar un tema | `wear` en la ruta publicada `themes/{id}/wear`, que es contrato | — |

## Se traduce por significado, no palabra a palabra

Una traducción palabra a palabra compila, pasa los tests y deja nombres que no dice nadie. Estos son
casos reales:

| Español | Palabra a palabra | Por significado |
|---|---|---|
| `test_volver_a_sembrar_no_revierte_lo_que_se_edito_encima` | `test_return_a_seed_no_revierte_it_that_itself_edito_encima` | `test_reseeding_does_not_revert_later_edits` |
| `test_una_pieza_cuenta_su_estado_y_quien_la_movio` | `test_one_post_account_its_status_and_who_the_movio` | `test_a_post_records_its_status_and_who_moved_it` |
| `$firma`, la firma HMAC del enlace de previa | `$byline` | `$signature` |
| `pasada()`, una pasada del gate | `past()` | `gateRun()` |

**La misma palabra pide nombres distintos según lo que hace en esa línea**: `firma` es `byline` en
una pieza y `signature` en un enlace firmado; `cuenta` es `account` como sustantivo y `counts` como
verbo; `se` casi nunca es `itself`. Si el nombre inglés no se entiende leído en voz alta, está mal
traducido.

## Los identificadores en español que ya existen

**Se traducen cuando se toca su trozo**, dentro del alcance del cambio. Un renombrado de golpe se
hace solo cuando se pide.

**Y un renombrado se hace entero**: la declaración y todos sus usos, también los que viven dentro de
una cadena —alias de `selectRaw`, `groupBy('…')`, atributos como `#[DataProvider('…')]`— y los de
otros ficheros, como una constante pública que lee un test o un método estático que llama un
seeder. `php -l` no ve una constante renombrada a medias; la suite sí.

## Cómo se comprueba

No hay regla de lint que lo cace. Después de renombrar:

```bash
docker compose exec -T app php artisan test
grep -rn "nombreAntiguo" src/packages src/tests
cd src && vendor/bin/pint --test
```
