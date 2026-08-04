<?php

namespace Packages\Kilkana\Models;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Packages\Kilkana\Enums\SnkKilkanaThemeSourceEnum;

/**
 * La variante que viste el periódico: puntero + diferencia.
 *
 * `base_slug` dice de qué variante enviada por el producto deriva y `tokens`/`style` solo lo
 * que esta redacción cambió encima. Los dos nulos significan «tal como la envía el producto»,
 * así que actualizar el producto actualiza a todos los inquilinos.
 *
 * Los rangos de abajo son los mismos que aplica `kilkana.app/src/lib/themes/overrides.ts` al
 * leer. Están en los dos lados a propósito y no es duplicación ociosa: el cliente los usa para
 * no pintar basura y el servidor para no guardarla. La diferencia está en qué hace cada uno
 * con un valor fuera de banda —el cliente lo descarta en silencio, el servidor responde 422—,
 * porque una API que se traga lo que le mandas sin decirlo deja a quien integra sin saber por
 * qué su color no se aplicó.
 */
class SnkKilkanaThemeVariantModel extends Model
{
    protected $table = 'kilkana_theme_variants';

    protected $guarded = [];

    /** Los tokens de color que una redacción puede tocar sin salirse de la familia del tema. */
    public const COLOR_KEYS = [
        'brand', 'brandDark', 'brandSoft', 'brandEdge', 'accent',
        'text', 'textMuted', 'textSoft', 'textFaint',
        'surface', 'surface2', 'surface3',
        'borderSubtle', 'borderSoft', 'borderStrong',
    ];

    /**
     * Los rangos, en un solo sitio, porque los miran dos validaciones distintas.
     *
     * `shapeRules()` valida el RETOQUE parcial que manda una redacción; el validador de spec
     * valida el tema COMPLETO que trae el catálogo. Son dos entradas con reglas distintas
     * —una acepta ausencias, la otra las rechaza— pero los límites tienen que ser los mismos,
     * o un tema publicado podría traer un valor que su propio inspector no deja escribir.
     *
     * Cada entrada es [unidad, mínimo, máximo].
     */
    public const UNIT_RANGES = [
        'pageRadius' => ['px', 0, 12],
        'headlineTracking' => ['em', -0.04, 0.02],
        'blockPadX' => ['px', 10, 18],
        'blockPadY' => ['px', 10, 18],
    ];

    /** Peso de titular: centenas entre 600 y 800. */
    public const WEIGHT_RANGE = [600, 800];

    /** Escala de titular: multiplica el tamaño de toda la gama tipográfica. */
    public const SCALE_RANGE = [0.8, 1.1];

    protected function casts(): array
    {
        return [
            'source' => SnkKilkanaThemeSourceEnum::class,
            'spec' => 'array',
            'tokens' => 'array',
            'style' => 'array',
            'is_default' => 'boolean',
        ];
    }

    public function theme(): BelongsTo
    {
        return $this->belongsTo(SnkKilkanaThemeModel::class, 'theme_id');
    }

    /** De qué variante del catálogo se copió esta, si es una copia. */
    public function origin(): BelongsTo
    {
        return $this->belongsTo(self::class, 'origin_variant_id');
    }

    /** Lo que trajo el catálogo: se reescribe al sincronizar y no se viste directamente. */
    public function scopeFromCatalog(Builder $query): Builder
    {
        return $query->where('source', SnkKilkanaThemeSourceEnum::Catalog);
    }

    /** Lo que este periódico adoptó o retocó: intocable para el sincronizador. */
    public function scopeOwned(Builder $query): Builder
    {
        return $query->where('source', SnkKilkanaThemeSourceEnum::Tenant);
    }

    /**
     * El tema tal como hay que pintarlo: el spec congelado con el retoque encima.
     *
     * La resolución vive aquí y no en el cliente porque la consumen los dos —el panel y la
     * salida pública— y porque una fila manipulada a mano no debe poder pintar nada que el
     * catálogo no permitiera.
     */
    public function resolvedSpec(): array
    {
        $spec = $this->spec ?? [];

        return [
            ...$spec,
            'tokens' => [...($spec['tokens'] ?? []), ...($this->tokens ?? [])],
            'style' => [...($spec['style'] ?? []), ...($this->style ?? [])],
        ];
    }

    /**
     * Lo que sale a la API, aquí y en el payload público.
     *
     * Viaja el spec RESUELTO —el congelado con el retoque ya aplicado— y no las dos piezas por
     * separado. Quien pinta no tiene que saber que hubo una copia ni en qué orden se mezclan:
     * recibe el tema y lo emite. Es también lo que impide que dos clientes distintos resuelvan
     * la mezcla de dos maneras.
     */
    public function payload(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'source' => $this->source?->value,
            'is_default' => (bool) $this->is_default,
            'thumbnail_path' => $this->thumbnail_path,
            'origin_version' => $this->origin_version,
            // De qué variante del catálogo salió: es lo que permite a la galería marcar cuál
            // está puesta, porque la copia lleva un slug propio y no el del catálogo.
            // Solo si la relación viene cargada — el lazy loading está deshabilitado a propósito.
            'origin_slug' => $this->relationLoaded('origin') ? $this->origin?->slug : null,
            'spec' => (object) $this->resolvedSpec(),
            // El retoque suelto además del resuelto: el inspector necesita saber qué tocó la
            // redacción para poder pintar el «modificado» y ofrecer restaurar.
            'tokens' => (object) ($this->tokens ?? []),
            'style' => (object) ($this->style ?? []),
        ];
    }

    /**
     * Las reglas de forma de `tokens` y `style`.
     *
     * Van aquí y no en el controlador porque las necesitan dos entradas —alta y edición— y
     * porque una clave nueva del tema es un cambio de esta lista y de nada más. Una clave que
     * no esté declarada no se rechaza: `validated()` simplemente no la devuelve, así que no se
     * guarda. Lo que sí se rechaza es una clave conocida con un valor imposible.
     */
    public static function shapeRules(): array
    {
        [$pesoMin, $pesoMax] = self::WEIGHT_RANGE;
        [$escalaMin, $escalaMax] = self::SCALE_RANGE;

        $reglas = [
            'tokens' => 'nullable|array',
            'style' => 'nullable|array',
            'tokens.pageRadius' => ['nullable', 'string', self::inUnit(...self::UNIT_RANGES['pageRadius'])],
            'style.headlineWeight' => "nullable|integer|between:{$pesoMin},{$pesoMax}|multiple_of:100",
            'style.headlineScale' => "nullable|numeric|between:{$escalaMin},{$escalaMax}",
            'style.headlineTracking' => ['nullable', 'string', self::inUnit(...self::UNIT_RANGES['headlineTracking'])],
            'style.blockPadX' => ['nullable', 'string', self::inUnit(...self::UNIT_RANGES['blockPadX'])],
            'style.blockPadY' => ['nullable', 'string', self::inUnit(...self::UNIT_RANGES['blockPadY'])],
        ];

        foreach (self::COLOR_KEYS as $clave) {
            $reglas["tokens.{$clave}"] = ['nullable', 'string', 'regex:/^#[0-9a-f]{6}$/i'];
        }

        return $reglas;
    }

    /**
     * El slug se deriva del nombre al crear, y no se recalcula al renombrar.
     *
     * Misma regla que en el resto del esquema: es la identidad estable de la variante, y la
     * columna es UNIQUE. Sin derivación, crear una variante obligaría a la pantalla a inventar
     * un slug y a resolver ella los choques.
     *
     * Un nombre sin ninguna letra latina no deja segmento utilizable, así que cae a un
     * respaldo estable en vez de dar cadena vacía contra un UNIQUE.
     */
    protected static function booted(): void
    {
        static::creating(function (self $variante) {
            if ($variante->slug) {
                return;
            }

            $base = Str::slug((string) ($variante->name ?? '')) ?: 'variant-'.substr(sha1((string) $variante->name.microtime()), 0, 8);
            $slug = mb_substr($base, 0, 64);

            // Sufijo solo cuando de verdad choca: dos variantes con el mismo nombre son un caso
            // legítimo dentro de una redacción, y aquí el slug no es una URL pública.
            $intento = $slug;
            $n = 2;
            while (self::query()->where('slug', $intento)->exists()) {
                $intento = mb_substr($slug, 0, 61).'-'.$n++;
            }

            $variante->slug = $intento;
        });
    }

    /**
     * Un número con unidad CSS dentro de un rango: `'14px'`, `'-0.02em'`.
     *
     * El valor viaja como cadena porque así lo consume `themeVars()` al emitir las custom
     * properties. Validar el rango exige separar número y unidad, y eso es justo lo que un
     * `regex` por sí solo no puede hacer.
     */
    private static function inUnit(string $unidad, float $min, float $max): Closure
    {
        return function (string $atributo, mixed $valor, Closure $fallar) use ($unidad, $min, $max) {
            if (! is_string($valor) || ! preg_match('/^(-?\d+(?:\.\d+)?)'.$unidad.'$/', $valor, $partes)) {
                $fallar("The {$atributo} must be a number followed by {$unidad}.");

                return;
            }

            $numero = (float) $partes[1];
            if ($numero < $min || $numero > $max) {
                $fallar("The {$atributo} must be between {$min}{$unidad} and {$max}{$unidad}.");
            }
        };
    }
}
