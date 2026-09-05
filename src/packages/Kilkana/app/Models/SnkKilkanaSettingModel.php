<?php

namespace Packages\Kilkana\Models;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Packages\Kilkana\Services\SnkKilkanaThemeSpecValidator;

/**
 * Los ajustes del periódico, y **el vestido que lleva puesto**.
 *
 * Vestirse es copiar: `theme_spec` es el spec congelado del día que se eligió el tema, así que
 * publicar la versión siguiente no le cambia la portada a nadie. `theme_id` dice de dónde salió
 * —para el aviso de actualización— y `theme_overrides` guarda lo que la redacción movió encima,
 * como diferencia y no como otra copia entera.
 *
 * Los rangos de esa diferencia **se leen del validador de spec**, que es quien define el
 * contrato, y no se copian: dos copias divergen, y entonces un tema publicado trae un valor que
 * su propio inspector no deja escribir. El cliente aplica los mismos límites al leer, y la
 * diferencia está en qué hace cada uno con un valor fuera de banda —el cliente lo descarta en
 * silencio, el servidor responde 422—, porque una API que se traga lo que le mandas sin decirlo
 * deja a quien integra sin saber por qué su color no se aplicó.
 */
class SnkKilkanaSettingModel extends Model
{
    protected $table = 'kilkana_settings';

    protected $guarded = [];

    /**
     * La clave se escribe, no se genera: esta tabla tiene una fila y su id es 1.
     *
     * Sin esto, tras insertar con id explícito Eloquent lee `lastInsertId()` —que sin
     * autoincremento devuelve 0— y se lo asigna al modelo.
     */
    public $incrementing = false;

    protected $keyType = 'int';

    /** Los tokens de color que una redacción puede tocar sin salirse de lo que el tema es. */
    public const COLOR_KEYS = [
        'brand', 'brandDark', 'brandSoft', 'brandEdge', 'accent',
        'text', 'textMuted', 'textSoft', 'textFaint',
        'surface', 'surface2', 'surface3',
        'borderSubtle', 'borderSoft', 'borderStrong',
    ];

    /**
     * Las unidades que lleva el retoque, con su rango sacado del contrato.
     *
     * **Los límites no se escriben aquí: se leen del validador.** `shapeRules()` valida el
     * RETOQUE parcial que manda una redacción y el validador valida el tema COMPLETO que trae
     * el catálogo —dos entradas con reglas distintas, una acepta ausencias y la otra las
     * rechaza— pero los límites tienen que ser los mismos, o un tema publicado traería un valor
     * que su propio inspector no deja escribir.
     *
     * Cada entrada es [unidad, clave del rango en el validador].
     */
    public const UNITS = [
        'pageRadius' => ['px', 'pageRadius'],
        'tracking' => ['em', 'tracking'],
    ];

    /**
     * El mismo valor por defecto que la columna, pero del lado de PHP.
     *
     * Lo pone la base, así que la fila recién creada por `firstOrCreate` no lo conocería y
     * `onboarded` saldría nulo en la respuesta del primer guardado.
     */
    protected $attributes = [
        'onboarded' => false,
        'lang' => self::DEFAULT_LANG,
        'tz' => self::DEFAULT_TZ,
        'search_visible' => true,
    ];

    /** Los idiomas que la calle sabe servir: `lang`, `hreflang` y el formato de fecha. */
    public const LANGS = ['es-ES', 'ca-ES', 'gl-ES', 'eu-ES', 'pt-PT', 'en-GB'];

    public const DEFAULT_LANG = 'es-ES';

    public const DEFAULT_TZ = 'Europe/Madrid';

    /** Las claves de los dos grupos abiertos; lo que no esté aquí no se guarda. */
    public const LEGAL = ['name', 'email'];

    public const SOCIAL = ['x', 'facebook', 'instagram'];

    protected function casts(): array
    {
        return [
            'onboarded' => 'boolean',
            'search_visible' => 'boolean',
            'theme_spec' => 'array',
            'theme_overrides' => 'array',
            'legal' => 'array',
            'social' => 'array',
        ];
    }

    /**
     * De qué tema del catálogo salió el vestido.
     *
     * Es puntero y no dueño: lo que se dibuja es `theme_spec`, la copia. Por eso la clave es
     * `nullOnDelete` —retirar un tema del catálogo deja al periódico vestido igual, solo sin
     * aviso de actualización— y por eso el catálogo puede reescribirse entero sin tocar a nadie.
     */
    public function theme(): BelongsTo
    {
        return $this->belongsTo(SnkKilkanaThemeModel::class, 'theme_id');
    }

    /**
     * El tema tal como hay que pintarlo: el spec congelado con el retoque encima.
     *
     * La resolución vive aquí porque la consumen tanto el flujo editorial como la salida
     * pública, y una fila manipulada a mano no debe producir nada que el catálogo no permitiera.
     */
    public function resolvedSpec(): array
    {
        $spec = $this->theme_spec ?? [];
        $retoque = $this->theme_overrides ?? [];

        if ($spec === []) {
            return [];
        }

        $resuelto = [
            ...$spec,
            'tokens' => [...($spec['tokens'] ?? []), ...($retoque['tokens'] ?? [])],
        ];

        if (is_array($retoque['density'] ?? null)) {
            $resuelto['density'] = [...($spec['density'] ?? []), ...$retoque['density']];
        }

        // El retoque tipográfico llega solo a la APERTURA, que es el peldaño con el que se
        // decide si un tema se ve grande o pequeño. Los otros nueve son la escala del tema y
        // tocarlos uno a uno sería rediseñarlo, no vestirlo.
        if (is_array($retoque['hero'] ?? null) && is_array($spec['type'] ?? null)) {
            $resuelto['type'] = [
                ...$spec['type'],
                'steps' => [
                    ...($spec['type']['steps'] ?? []),
                    'hero' => [...($spec['type']['steps']['hero'] ?? []), ...$retoque['hero']],
                ],
            ];
        }

        return $resuelto;
    }

    /**
     * Lo que sale a la API sobre el vestido.
     *
     * Viaja el spec RESUELTO —el congelado con el retoque ya aplicado— y no las dos piezas por
     * separado. Quien pinta no tiene que saber que hubo una copia ni en qué orden se mezclan:
     * recibe el tema y lo emite. Es también lo que impide que dos clientes distintos resuelvan
     * la mezcla de dos maneras. El retoque suelto viaja además, porque el inspector necesita
     * saber qué tocó la redacción para pintar el «modificado» y ofrecer restaurar.
     */
    public function themePayload(): ?array
    {
        if (($this->theme_spec ?? []) === []) {
            return null;
        }

        return [
            'theme_id' => $this->theme_id,
            'slug' => $this->relationLoaded('theme') ? $this->theme?->slug : null,
            'name' => $this->relationLoaded('theme') ? $this->theme?->name : null,
            'version' => $this->theme_version,
            'spec' => (object) $this->resolvedSpec(),
            'overrides' => (object) ($this->theme_overrides ?? []),
        ];
    }

    /**
     * Las reglas de forma del retoque.
     *
     * Van aquí y no en el controlador porque una clave nueva del tema es un cambio de esta
     * lista y de nada más. Una clave que no esté declarada no se rechaza: `validated()`
     * simplemente no la devuelve, así que no se guarda. Lo que sí se rechaza es una clave
     * conocida con un valor imposible.
     */
    public static function shapeRules(): array
    {
        $entre = function (string $rango): string {
            [$min, $max] = SnkKilkanaThemeSpecValidator::RANGES[$rango];

            return "between:{$min},{$max}";
        };

        $reglas = [
            'theme_overrides' => 'nullable|array',
            'theme_overrides.tokens' => 'nullable|array',
            'theme_overrides.tokens.pageRadius' => ['nullable', 'string', self::inUnit('pageRadius')],
            // El aire, en px y como número: `density` viaja sin unidad.
            'theme_overrides.density.rowGap' => ['nullable', 'integer', $entre('gap')],
            'theme_overrides.density.blockGap' => ['nullable', 'integer', $entre('gap')],
            'theme_overrides.density.padX' => ['nullable', 'integer', $entre('pad')],
            'theme_overrides.density.padY' => ['nullable', 'integer', $entre('pad')],
            // Y la apertura, que es el peldaño con el que se decide si un tema se ve grande.
            'theme_overrides.hero.size' => ['nullable', 'integer', $entre('size')],
            'theme_overrides.hero.weight' => ['nullable', 'integer', $entre('weight'), 'multiple_of:100'],
            'theme_overrides.hero.leading' => ['nullable', 'numeric', $entre('leading')],
            'theme_overrides.hero.tracking' => ['nullable', 'string', self::inUnit('tracking')],
        ];

        foreach (self::COLOR_KEYS as $clave) {
            $reglas["theme_overrides.tokens.{$clave}"] = ['nullable', 'string', 'regex:/^#[0-9a-f]{6}$/i'];
        }

        return $reglas;
    }

    /**
     * Un número con unidad CSS dentro de un rango: `'14px'`, `'-0.02em'`.
     *
     * El valor viaja como cadena porque así lo consume `themeVars()` al emitir las custom
     * properties. Validar el rango exige separar número y unidad, y eso es justo lo que un
     * `regex` por sí solo no puede hacer.
     */
    private static function inUnit(string $clave): Closure
    {
        [$unidad, $rango] = self::UNITS[$clave];
        [$min, $max] = SnkKilkanaThemeSpecValidator::RANGES[$rango];

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
