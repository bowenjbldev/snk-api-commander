# PHP y Laravel, como se escribe aquí

Todo lo de abajo sale del código de este repositorio, no de la guía de Laravel. Donde discrepen,
manda esto.

---

## 1. Dónde va cada clase

```
src/packages/<Paquete>/
├── app/
│   ├── Console/Commands/       SnkKilkanaPublishScheduledCommand.php
│   ├── Enums/                  SnkKilkanaPostStatusEnum.php
│   │   └── Concerns/           SnkKilkanaEnumValuesSupport.php   (traits de enum)
│   ├── Http/
│   │   ├── Controllers/        SnkKilkanaPostsController.php
│   │   ├── Middleware/
│   │   └── Requests/           ← existe y está VACÍA en los cuatro paquetes
│   ├── Mail/ Metrics/ Providers/
│   ├── Models/
│   │   └── Concerns/           SnkKilkanaRetiredSlugsSupport.php (traits de modelo)
│   ├── Services/               SnkKilkanaPostService.php
│   └── Support/                SnkKilkanaRichText.php  (helpers estáticos sin estado)
├── config/  database/{migrations,seeders}  resources/views  routes/api.php
```

**`Http/Resources/` sí existe, y es donde se declara la forma de salida.** Un `JsonResource` por
recurso, con el prefijo N2: `SnkKilkanaPostResource`, `CoreUserResource`. Ver «La salida la
declara un Resource» más abajo.

**Carpetas que no existen y no se crean**: `Policies/`, `Actions/`, `DTOs/`, `Repositories/`,
`Observers/`, `Events/`. `Jobs/` solo en Commander. Y los tests **no** viven en el paquete: van en
`src/tests/{Unit,Feature}`.

**Lo que sí se apoya en un paquete**: la query string la resuelve `spatie/laravel-query-builder`,
con sus nombres por defecto. Ver «La query la resuelve…» más abajo. La regla es no reinventar lo
que el gremio ya tiene resuelto, y no ponerle encima un vocabulario propio.

**No hay FormRequests. Cero.** Se valida con `$validationRulesStore` / `$validationRulesUpdate`,
que el CRUD del base aplica y que heredan treinta controladores. Hubo un andamiaje para
FormRequests que nadie usaba —y que además estaba roto: lanzaba `ValidationException`, que el
manejador envuelve en `{message, errors}`, donde este CRUD devuelve el objeto de errores **pelado**—
y se retiró. Añadir uno ahora sería una segunda forma de validar conviviendo con la primera.

### El overlay

`src/packages/<Cliente>/<Paquete>` lleva **solo las diferencias**. Gana sus rutas exactas porque el
router registra primero los overlays concretos y después el wildcard del compartido. Y sus
providers **no se cargan globalmente**:

```php
// Los providers de overlays de cliente no se cargan globalmente: un binding de
// ElMundo no puede modificar las peticiones de ElConfidencial.
if (str_contains($module, '\\')) {
    continue;
}
```

---

## 2. Nomenclatura

| Tipo | Sufijo | Número | Ejemplo |
|---|---|---|---|
| Controlador | `Controller` | **plural** del recurso | `SnkKilkanaCategoriesController` |
| Modelo | `Model` | **singular** | `SnkKilkanaPostModel` |
| Servicio | `Service` | singular del dominio | `SnkKilkanaPostService` |
| Enum | `Enum` | singular | `SnkKilkanaPostStatusEnum` |
| Trait | `Support` | — | `SnkKilkanaRetiredSlugsSupport` |
| Helper estático | sin sufijo | — | `SnkKilkanaRichText` |
| Comando / Job / Mail | `Command` / `Job` / `Mail` | — | `SnkKilkanaPublishScheduledCommand` |

Tablas: prefijo del paquete + plural snake_case (`kilkana_post_revisions`). Pivotes en singular
(`kilkana_post_tag`). FKs `*_id`; auditoría `created_by` / `updated_by`, nunca `user_id`.
Rutas kebab-case y plural, sin nombre salvo cuando `URL::signedRoute` lo exige.

**Idioma.** Todo identificador en **inglés** —clases, métodos, variables locales de dominio,
constantes y nombres de test—; docblocks, comentarios y mensajes de aserción en **español**. La
regla entera, lo que no se renombra y cómo se traduce por significado están en
[`docs/code-language.md`](../../../../docs/code-language.md):

```php
$status, $settings, $theme, $days, $rules, $liveSection, $onPublish, $catalog
$query, $data, $post, $media, $user, $count, $page
```

Hasta las closures se nombran por lo que son:

```php
$query->where(fn (Builder $any) => $any
    ->where('title', 'like', $like)
    ->orWhereHas('author', fn (Builder $byline) => $this->matchesByline($byline, $like)));
```

---

## 3. Controladores

### El contrato: `CoreBaseApiController`

Un CRUD es **declaración, no código**. Ejemplo mínimo completo, `SnkKilkanaTagsController`:

```php
#[Group('Tags')]
class SnkKilkanaTagsController extends CoreBaseApiController
{
    protected string $modelClass = SnkKilkanaTagModel::class;

    protected string $indexPermission = 'index tags';

    protected string $showPermission = 'show tags';

    protected string $storePermission = 'create tags';

    protected string $updatePermission = 'update tags';

    protected string $destroyPermission = 'delete tags';

    protected array $validationRulesStore = [
        'name' => 'required|string|max:'.SnkKilkanaTagModel::NAME_MAX,
        'slug' => 'nullable|string|max:'.SnkKilkanaTagModel::SLUG_MAX.'|unique:kilkana_tags,slug',
    ];

    protected array $validationRulesUpdate = [
        'name' => 'sometimes|required|string|max:'.SnkKilkanaTagModel::NAME_MAX,
        'slug' => 'nullable|string|max:'.SnkKilkanaTagModel::SLUG_MAX.'|unique:kilkana_tags,slug,{id}',
    ];
}
```

Detalles que no son cosméticos:

- **Reglas como string con pipes**, nunca arrays de reglas.
- **Los topes salen de constantes del modelo**, las mismas que usa la migración.
- `{id}` es un marcador propio que `interpolateRules()` sustituye en el `unique` del update.
- Una línea en blanco entre propiedades `protected string`. Lo pone Pint y se deja.
- `#[Group('…')]` de Scramble en **toda** clase de controlador.

### La guarda de permiso, siempre igual

Primera línea de todo método público, con asignación dentro del `if`:

```php
if ($response = $this->requirePermission($this->indexPermission)) {
    return $response;
}
```

### Cuándo sobreescribir el base

Cuando la consulta o la búsqueda del modelo dependen de quién pregunta:

```php
protected function newQuery()
{
    return $this->posts->query((int) auth()->id(), $this->canEditOthers(), $this->readsWindow());
}

/** Una pieza ajena no existe: 404, no 403 — el id es correlativo y un 403 confirmaría cuántas hay. */
protected function findModel($id, bool $applyWith = false): ?Model
{
    return $this->posts->find($id, (int) auth()->id(), $this->canEditOthers());
}
```

### Reglas que dependen de un enum: al constructor

Una propiedad solo admite expresión constante, así que ahí es donde se juntan las dos listas:

```php
public function __construct(private readonly SnkKilkanaPostService $posts)
{
    $this->validationRulesStore['status'] = 'required|'.SnkKilkanaPostStatusEnum::rule();
    $this->validationRulesUpdate['status'] = 'sometimes|required|'.SnkKilkanaPostStatusEnum::rule();

    $onPublish = 'required_if:status,'.implode(',', SnkKilkanaPostStatusEnum::publishing());

    foreach (['validationRulesStore', 'validationRulesUpdate'] as $property) {
        $this->{$property}['content_tier'] = 'nullable|'.SnkKilkanaContentTierEnum::rule();
        $this->{$property}['excerpt'] = $onPublish.'|nullable|string';
        $this->{$property}['publish_at'] = $onPublish.'|nullable|date';
    }
}
```

Inyección **siempre** por propiedad promovida `private readonly`.

### `store()` cuando hay reglas de propiedad

`validator()` a mano, no `$request->validate()`, porque las reglas viven en la propiedad:

```php
$validator = validator($request->all(), $this->validationRulesStore, $this->validationMessages);
if ($validator->fails()) {
    return response()->json($validator->errors(), 422);
}
$data = $validator->validated();
```

Fuera del CRUD (`bulk`, `upload`, `subscribe`, `settings`) sí va `$request->validate([...])` inline.

### La query la resuelve `spatie/laravel-query-builder`, con SUS nombres

No se escribe a mano el parseo de la query, y **no se inventa vocabulario de la casa**: se usan
los nombres por defecto del paquete, que es el estándar del gremio. Quien lo conoce conoce esta
API sin leer nada.

| Idea | Parámetro | Se declara con |
|---|---|---|
| Relaciones | `?include=author,tags` | `$allowedIncludes` |
| Columnas | `?fields=id,title` | `$allowedFields` |
| Orden | `?sort=-publish_at` (`-` es descendente) | `$allowedSorts` |
| Filtros | `?filter[status]=draft` | `$allowedFilters` |
| Paginación | `?per_page=25&page=2` | el paginador de Laravel |

`CoreBaseApiController::queryBuilder()` las aplica todas; el controlador solo las declara.
**Todo es lista blanca**: lo que no esté declarado no se aplica, y el paquete no lo inventa.

**`newQuery()` sigue siendo el gancho** por el que un recurso acota lo suyo —por propiedad, por
permiso— *antes* de que nadie pida nada. Quien llama elige dentro de eso; nunca fuera.

**El alias del orden no es cosmético.** `AllowedSort::field('date', 'publish_at')` mantiene los
nombres de columna fuera de la URL, y en un API multi-inquilino eso es contención, no comodidad.
Lo mismo con `AllowedFilter::scope()`, que expone un scope y no una columna.

**Lo desconocido cae al defecto, nunca 422.** Es la regla de siempre y el paquete la respeta: lo
que no está declarado se ignora. Quien teclea `?sort=banana` se merece la lista, no una pantalla
en blanco.

**Lo que NO es del paquete y sigue siendo nuestro:**

- **`?summary=counts,stats`**, con `$allowedSummary` y `wants()`. Son cifras **sobre la
  colección** —las pestañas, la banda—, y los agregados del paquete van por relación
  (`include=commentsCount`). No viajan si no se piden: cuentan la tabla entera y no la página,
  así que una llamada que solo quiere filas no los paga.
- **El techo de `?fields=`, que es de la puerta y no del modelo.** `SnkKilkanaPostService::FIELDS`
  para el panel, `SnkKilkanaPublicService::FIELDS` para la calle: la misma fila la leen un anónimo,
  el listado y el editor, y el conjunto seguro es distinto en cada uno. En una puerta anónima el
  techo es **además el valor por defecto**: sin `?fields=` el paquete no recorta, y eso llegó a
  servir `body_blocks` —el artículo entero con su muro dentro— a quien pidiera `/public/posts` a
  secas.
- **`per_page` y el sobre de la paginación**, en `CoreBaseApiController::perPage()` y
  `CorePaginationSupport::meta()`. El paquete no pagina.

**Cinco trampas ya pagadas**, todas silenciosas:

1. Las cuatro `allowed*` son **variádicas**: pasarles un array da un 500, van con `...`.
2. **`allowedFields()` va antes que `allowedIncludes()`.** Declarar una inclusión resuelve ahí
   mismo los campos de esa relación, y sin la lista puesta lanza `UnknownIncludedFieldsQuery`.
3. **Los agregados van después de recortar: en `decorate()`, nunca en `newQuery()`.**
   `allowedFields()` hace `select()` —no `addSelect()`— en el momento de declararse, y borra
   cualquier `withCount`, `withMax` o `addSelect` con subconsulta que hubiera antes. Un
   `newQuery()->asCatalogue()` funcionaba mientras nadie mandó `?fields=`. Por lo mismo, un alias
   de agregado —`views_total`, `seat`, `posts_count`— **no puede pedirse con `?fields=`**: el
   paquete le antepondría la tabla y la columna no existe. Llega solo.
4. **Una relación `belongsTo` sin su clave foránea llega nula, y no avisa.** Quien incluye
   `category` pide también `category_id`. Si la relación la declara el propio modelo en su `$with`
   —la cuenta de una firma— la llave es obligatoria en cualquier proyección sobre ella.
5. **`?fields=` no tiene bandera para ignorar lo desconocido.** Un filtro, un orden o una
   inclusión que no estén declarados se ignoran; un campo que no esté, lanza. Ahí la lista blanca
   del recurso es la única puerta.

### Formato de respuesta

No hay envoltura `data` genérica. Los Resources se devuelven dentro de `response()->json()`, que
serializa por `jsonSerialize()` y **no aplica el `$wrap`** — devolver el Resource directamente sí
lo aplicaría, y diecinueve endpoints que hoy salen pelados dejarían de estarlo.

| Caso | Cuerpo |
|---|---|
| `show`/`store`/`update` | **un Resource**, `201` en el alta — sin envoltura `data` |
| `index` sin paginar | el array pelado de Resources |
| `index` paginado | `{data, meta:{current_page, per_page, total, last_page}}`, más `counts`/`stats` **solo si se piden** con `?summary=` |
| `destroy` | `{"id": 12}` |
| `bulk` | `{"ok": true, "count": 3}` |
| error | `{"error": "Texto en inglés"}` (+ campos extra en 409) |
| 422 | el objeto de errores **pelado**, sin envoltura |

Códigos: **404 para lo ajeno** (nunca 403), **409 para conflicto de negocio** con el recuento
dentro, 422 validación, 202 aviso aceptado, 402 kill-switch, 503 base del inquilino caída.
`abort()` solo en middleware; en un controlador, nunca.

El 409 se comprueba antes y se protege después:

```php
if ($posts > 0 || $subsections > 0) {
    return response()->json([
        'error' => 'Section in use',
        'posts' => $posts,
        'subsections' => $subsections,
    ], 409);
}

try {
    return parent::destroy($id);
} catch (QueryException) {
    // Red de seguridad: entre el recuento y el borrado cabe una escritura.
    return response()->json(['error' => 'Section in use'], 409);
}
```

### Orden de rutas

```php
// Antes del apiResource: si no, `show` se traga «bulk» como id de post.
Route::post('posts/bulk', [SnkKilkanaPostsController::class, 'bulk']);
Route::apiResource('posts', SnkKilkanaPostsController::class);
```

---

## 4. Modelos

```php
class SnkKilkanaPostModel extends Model
{
    protected $table = 'kilkana_posts';

    protected $guarded = [];

    // Ni `$with`, ni `$appends`, ni `$hidden` de presentación: los tres son globales por
    // modelo, y la misma pieza la leen un anónimo, la tabla del panel y el editor.
```

- **El modelo no declara qué se carga ni qué sale.** `$with` cargaba cuatro relaciones en toda
  consulta de piezas, las pintara alguien o no; `$appends` publicaba dos accesores que dependían de
  columnas que un recorte podía no traer; y un `$hidden` de presentación obliga a poner listas
  blancas encima para volver a recortar por puerta. Lo que se carga lo pide `?include=`, lo que se
  recorta `?fields=`, y lo que sale lo declara el Resource.
- **`$hidden` de secretos sí se queda**: `password`, `remember_token`, las credenciales de base de
  una instancia. Un Resource se declara puerta a puerta y quedan caminos que serializan el modelo
  directo; `$hidden` es la red de esos.

- **`$guarded = []` universal**, nunca `$fillable`. Es seguro **solo mientras el controlador
  declare reglas**: controlador nuevo sobre un modelo así = reglas declaradas, o la ruta no debería
  existir. Mordió en el alta de medios, dejando inventar `disk` y `path`, que son los que borran
  ficheros del disco.
- Casts por **método `casts()`** en lo nuevo; la propiedad `$casts` sobrevive en lo simple.
- Relaciones **siempre tipadas y con FK explícita**, aunque sea la convencional:
  `return $this->belongsTo(SnkKilkanaCategoryModel::class, 'category_id');`
- Scopes por concepto de negocio: `scopePublished(Builder $query): Builder`, y se componen
  (`scopeIndexable` = `published()->where('noindex', false)`).
- **Constantes públicas para los topes**, compartidas con la migración y con la validación:
  `public const SLUG_MAX = 200;`
- `$attributes` replica en PHP los `default` de la columna.
- `$connection = 'commander'` solo en los modelos `Commander*`.

### Las invariantes de dominio van en `booted()`

Y la razón se escribe: **el seeder y la consola no pasan por la validación de la API**.

```php
if (! $categoryReachable) {
    throw ValidationException::withMessages([
        'category' => 'That section has not been launched yet; launch it or pick another.',
    ]);
}
```

Mensaje en inglés, dirigido a quien lo va a leer en pantalla.

---

## 5. Enums

Siempre backed `: string`, siempre con el trait, siempre con docblock que dice por qué la lista es
cerrada. **El orden de los `case` es el del ciclo de vida**, no el alfabético: en una columna `ENUM`
ese es el orden con el que la base ordena.

```php
trait SnkKilkanaEnumValuesSupport
{
    /**
     * Los valores en crudo, para que la migración declare la columna con esta misma lista.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Regla de validación de la API, derivada. */
    public static function rule(): string
    {
        return 'in:'.implode(',', self::values());
    }
}
```

Lo que **no** entra en el trait es lo que cada enum SIGNIFICA. Eso es un método del enum, y vive
ahí y no en el controlador ni en el servicio **porque los dos lo necesitan**:

```php
public function requiresPublishPermission(): bool
{
    return $this === self::Scheduled || $this === self::Published;
}

public static function fromBulkAction(string $action): ?self
{
    return $action === 'publish' ? self::Published : self::tryFrom($action);
}
```

`case` en PascalCase, valor en minúscula.

---

## 6. Servicios

Un servicio por agregado. `class` normal — ni `final`, ni interfaz, ni contenedor de una sola
implementación. Es dueño de: transacciones, consultas, proyecciones de salida y las reglas que no
son CRUD.

**La transacción va en el servicio, nunca en el controlador**, y su docblock dice qué pasa sin ella:

```php
/**
 * Guardar un artículo son VARIAS escrituras, y o entran todas o no entra ninguna.
 *
 * Un alta toca el post, puede dar de alta el medio de portada y da de alta las etiquetas
 * que no existan más su pivote. Sin transacción, un fallo a mitad deja el desecho puesto y
 * el cliente recibe un error: cree que no ha pasado nada y sí ha pasado.
 */
public function create(array $data, int $userId): SnkKilkanaPostModel
{
    return DB::transaction(function () use ($data, $userId) {
        ...
        return $post->fresh();
    });
}
```

Y cuando **no** la lleva, también se dice por qué: `publishDue()` va sin transacción a propósito,
porque envolver el lote impediría salir a las demás piezas ese minuto.

### La salida la declara un Resource

Un `JsonResource` por recurso, y **cada campo con `whenHas()`**, que es
`array_key_exists()` sobre los atributos cargados: lo que la consulta no seleccionó sale
**ausente**, no `null`. Esa diferencia es el contrato — quien lee distingue «no lo pedí» de «está
vacío», que con un `setVisible()` no podía.

```php
class SnkKilkanaPostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->whenHas('id'),
            'title' => $this->whenHas('title'),
            'cover_media_id' => $this->whenHas('cover_media_id'),
            // Derivado: no se pide por su nombre —no es una columna, y `?fields=` le
            // antepondría la tabla— y sale cuando la suya está.
            'url_path' => $this->whenHas('url_date', fn () => $this->resource->url_path),
            'category' => new SnkKilkanaCategoryResource($this->whenLoaded('category')),
            'tags' => SnkKilkanaTagResource::collection($this->whenLoaded('tags')),
            'comments_count' => $this->whenCounted('comments'),
        ];
    }
}
```

El controlador lo declara con `protected ?string $resourceClass = …::class;` y el CRUD del base
envuelve `index()` y `show()`. Donde la respuesta se compone a mano —un listado con su `meta` y
sus cifras— se usa `Resource::collection($collection)` dentro del array.

**Un Resource no consulta la base.** La precarga y los agregados van en el servicio, que los pide
en una tanda para toda la página y los cuelga de la fila con `setAttribute()`; el Resource los lee
con `whenHas()` sin saber de dónde vienen. Lo caro se condiciona a haberse cargado y no a la
puerta: `usage_count` son tres `COUNT(*)` por foto, y solo sale donde alguien hizo `loadCount()`.

**Lo que NO pasa a Resource**: lo que compone un documento en vez de serializar un modelo —el plan
con sus medidores, los ajustes, la composición de portada— y la papelera, que arma una fila
uniforme a partir de dos tipos de modelo distintos. Envolverlos no quitaría ninguna lista de
campos; partiría la que hay.

Con una lista de ocultos, cada columna nueva sale al JSON el día que se añade y nadie lo decide.

**Si la regla la necesitan dos puertas, es un método estático del servicio**, no una copia en cada
una — así se puede probar sin levantar la petición:

```php
public static function stampFor(string $status, ?string $wanted): ?string
```

---

## 7. Migraciones

Una tabla por fichero, clase anónima, y la secuencia numérica del nombre codifica el orden de
dependencias (`..._000010_create_kilkana_posts_table.php`).

```php
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kilkana_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')->constrained('kilkana_authors');
            $table->foreignId('cover_media_id')->nullable()->constrained('kilkana_media')->nullOnDelete();
            $table->string('title', 500);
            $table->string('slug', SnkKilkanaPostModel::SLUG_MAX);
            $table->enum('status', SnkKilkanaPostStatusEnum::values())->default(SnkKilkanaPostStatusEnum::Draft->value);
            $table->json('body_blocks')->nullable();
            $table->boolean('featured')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('url_date')->nullable();
            $table->timestamp('publish_at')->nullable();
            $table->timestamps();
            $table->unique(['url_date', 'slug']);
            $table->index(['status', 'publish_at']);
        });

        // Va aparte y con guarda de driver porque sqlite no compila un índice FULLTEXT y
        // aborta la migración entera: sin esto el esquema de un inquilino no se puede
        // levantar en pruebas.
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('kilkana_posts', function (Blueprint $table) {
                $table->fullText(['title', 'excerpt']);
            });
        }
    }
```

- Orden **PK → FKs → negocio por relevancia → fechas al final**; índices y únicos tras las columnas.
- `Enum::values()` para la columna; constante del modelo para el ancho.
- FK siempre con tabla explícita y política de borrado explícita
  (`nullOnDelete` / `restrictOnDelete` / `cascadeOnDelete`).
- Guarda `getDriverName() !== 'sqlite'` para lo que solo hay en MariaDB.
- `down()` reversible **de verdad**, incluso si eso exige tocar datos antes del `change()`.
- Alterar lleva `->after('col')` explícito y docblock de clase con el motivo.
- Excepciones históricas que **no se copian**: `kilkana_settings.id` tinyint con `CHECK (id=1)`, y
  `post_views` y los pivotes sin timestamps.

---

## 8. Estilo PHP verificado

| Rasgo | Aquí |
|---|---|
| `declare(strict_types=1)` | **Nunca.** Cero ficheros. |
| `final class` | Solo en `Support/` y seeders auxiliares. Modelos, controladores y servicios nunca. |
| `readonly` | Propiedades promovidas de constructor, y dos `final readonly class` con sufijo `Data`. |
| Inyección | Promovida siempre: `__construct(private readonly SnkKilkanaPostService $posts)` |
| Tipos de retorno | Sí en casi todo. Excepción viva: `$id` sin tipar en firmas de controlador y `newQuery()` sin retorno. |
| Arrow fns | Muy usadas, con **el parámetro tipado dentro** de la closure. |
| First-class callable | `$page->pipe(SnkKilkanaPostResource::collection(...))` |
| Spread | `[...$this->columnsFrom($data, ...), 'created_by' => $userId]` |
| Negación | `! $post` **con espacio** (Pint, preset laravel). |
| Herramienta | `laravel/pint` sin `pint.json`. No hay PHPStan ni Rector. |
| Longitud | Comentarios cortados a ~95; código raramente pasa de 120. |
| `@return` genérico | `list<string>`, `array{tenants: int, errors: array<string, string>}`, `class-string<ServiceProvider>` |

### Los comentarios

Son el rasgo más reconocible del repositorio. Estructura: **titular corto → línea en blanco → el
incidente concreto con su medición**. Negrita markdown dentro del docblock, mayúsculas para
enfatizar, comillas angulares.

```php
/**
 * Lo que devuelve una fila del listado, y **nada más**.
 *
 * Es una lista blanca y no una de ocultos a propósito: con una lista de ocultos, cada
 * columna nueva de la tabla sale al JSON el día que se añade y nadie lo decide.
 *
 * **Y no es aseo, es tiempo.** Medido con 522 piezas: serializar veinticinco filas pasa de
 * 28,0 a 14,5 ms y de 59,7 a 14,2 kB.
 */
```

```php
// El desempate por `id` no es adorno: sin él, dos piezas con la misma fecha pueden
// salir en distinto orden en dos consultas, y entonces paginar repite una fila en la
// página 2 y se salta otra. Un orden estable es requisito de la paginación.
```

- **`ponytail:`** marca una simplificación deliberada con su techo conocido.
- **Cero `TODO` / `FIXME` / `XXX`** en todo el repositorio. No se estrena uno.
- **Los comentarios llevan tildes.** Uno antiguo sin ellas se corrige cuando se toca su trozo.

---

## 9. Tests

PHPUnit 12, no Pest. Suites `tests/Unit` y `tests/Feature`, **todas en `src/tests/`**.

- Nombre de método: `test_` + frase **en inglés** snake_case, traducida por significado; en
  PHPUnit el nombre es un método y un método es código.
  `test_the_batch_does_not_publish_a_post_without_an_excerpt()`
- **No hay factories de modelo.** Los datos se construyen con helpers privados al final de la clase
  (`private function category(...)`, `private function makePost(...)`).
- **Se prueban servicios, no rutas HTTP**, y el docblock de la clase dice por qué.
- El unitario puro extiende `PHPUnit\Framework\TestCase` directamente, sin Laravel.
- El tenant se monta a mano, sin `RefreshDatabase`:

```php
// `tenant` es sqlite `:memory:` en pruebas (`phpunit.xml`), así que la base vive
// mientras viva la conexión: basta con migrar encima y no hay nada que limpiar.
DB::setDefaultConnection('tenant');
$this->artisan('migrate', ['--database' => 'tenant', '--path' => 'packages/Kilkana/database/migrations'])->run();
```

- Los mensajes de aserción van en español, con comillas angulares:
  `"«{$field}» es de la ficha, no de la fila del listado"`.
- Hay tests que fijan **el coste**, no solo el resultado: contar consultas con `DB::enableQueryLog()`
  para que un N+1 no vuelva, o comprobar el SQL con `->toSql()`.
- **Las pruebas gemelas**: `SnkKilkanaBlockVocabularyTest`, `SnkKilkanaPostsListRowTest` y
  `SnkKilkanaMediaRowTest` fijan a mano tres contratos que el frontend repite en su lado. Cuando se
  ponen rojas, **el arreglo no es cambiar la lista de aquí**: es mirar el otro repositorio y
  decidir a la vez.

---

## 10. Trampas ya pagadas

- **`Hash::make` en un seeder = doble hash** → «Invalid credentials». La contraseña la hashea el
  mutator `CoreUserModel::setPasswordAttribute` al asignarla; se pasa en claro.
  **No existe un cast `hashed`** — si un comentario o un documento lo dice, está obsoleto.
- El modelo de usuario es `Packages\Core\Models\CoreUserModel`, **no** `App\Models\User`.
- **El contenedor `app` cachea rutas en `bootstrap/cache/routes-v7.php`**, que no está
  bind-mounteado: una ruta nueva no sale en `route:list` hasta correr `php artisan route:clear`
  dentro de `app`. El contenedor `provisioner` sí la ve, y esa discrepancia es la pista.
- **`KILKANA_SEED_DEMO` no está en el entorno y por defecto es `false`**: sembrar sin ella deja la
  base sin artículos, sin secciones y sin identidad.
- El admin del periódico lo crea el provisioning, no el seeder. Tras un `--fresh` solo lo repone el
  seeder de Kilkana, y con otra contraseña.
- El binario de la base es **`mariadb`**, no `mysql`.
- Config de paquete se lee con doble nombre: `config('kilkana.kilkana.seed_demo', false)`.
- **`preventAccessingMissingAttributes()` está encendido fuera de producción.** Leer una columna
  que la consulta no seleccionó revienta en vez de devolver `null`. Lo que puede no estar se lee
  del array crudo: `$model->getAttributes()['x'] ?? null`. Pasa más de lo que parece — un modelo
  recién creado solo lleva lo que se le pasó a `create()`, y `actingAs()` conserva ese objeto.
- **Un `try/catch` que protege algo accesorio puede esconder un fallo evitable.** El registro de
  actividad leía una columna que podía faltar, lanzaba, y su `catch` con `report()` se lo tragaba:
  la acción seguía y **la línea de auditoría no se escribía**. El arreglo va en la lectura, no en
  el `catch` — que está bien puesto.
