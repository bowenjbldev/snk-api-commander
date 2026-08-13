<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Validator;
use Packages\Kilkana\Enums\SnkKilkanaThemeSourceEnum;
use Packages\Kilkana\Models\SnkKilkanaSettingModel;
use Packages\Kilkana\Models\SnkKilkanaThemeVariantModel;
use Packages\Kilkana\Services\SnkKilkanaSettingService;
use Tests\TestCase;

/**
 * Los rangos de una variante y el mapeo que sale a la API.
 *
 * Estos casos no tocan la base a proposito: las migraciones de negocio de Kilkana no se cargan
 * en la conexion por defecto (ver `CoreServiceProvider::boot`) y la suite corre sobre sqlite,
 * que no admite el `ALTER TABLE ... ADD CONSTRAINT CHECK` con el que se declara el singleton.
 * Lo que si es comprobable aqui, y es lo que importa, es que un valor fuera de banda no entre.
 */
class SnkKilkanaThemeVariantTest extends TestCase
{
    private function validate(array $datos): \Illuminate\Validation\Validator
    {
        return Validator::make($datos, SnkKilkanaThemeVariantModel::shapeRules());
    }

    public function test_it_accepts_a_variant_within_range(): void
    {
        $validator = $this->validate([
            'tokens' => ['brand' => '#1A1A1A', 'pageRadius' => '10px'],
            'style' => [
                'density' => ['rowGap' => 44, 'padX' => 28],
                'hero' => ['size' => 54, 'weight' => 700, 'tracking' => '-0.02em', 'leading' => 1.1],
            ],
        ]);

        $this->assertFalse($validator->fails(), (string) $validator->errors());
    }

    public function test_it_accepts_the_edges_of_every_range(): void
    {
        $validator = $this->validate([
            'tokens' => ['pageRadius' => '0px'],
            'style' => [
                'density' => ['rowGap' => 240, 'blockGap' => 0, 'padX' => 80, 'padY' => 0],
                'hero' => ['size' => 120, 'weight' => 900, 'tracking' => '-0.06em', 'leading' => 0.9],
            ],
        ]);

        $this->assertFalse($validator->fails(), (string) $validator->errors());
    }

    /**
     * Un color tiene que ser un hex de seis digitos.
     *
     * `red` o `var(--algo)` son CSS validos y por eso es tentador dejarlos pasar, pero acaban
     * emitidos como custom property en la hoja del periodico sin que nadie los acote.
     */
    public function test_it_rejects_a_colour_that_is_not_hex(): void
    {
        $validator = $this->validate(['tokens' => ['brand' => 'red']]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('tokens.brand', $validator->errors()->toArray());
    }

    public function test_it_rejects_values_outside_their_range(): void
    {
        foreach ([
            'style.hero.size' => ['style' => ['hero' => ['size' => 300]]],
            'style.hero.weight' => ['style' => ['hero' => ['weight' => 650]]],
            'style.hero.leading' => ['style' => ['hero' => ['leading' => 4]]],
            'style.hero.tracking' => ['style' => ['hero' => ['tracking' => '-1em']]],
            'style.density.padX' => ['style' => ['density' => ['padX' => 99]]],
            'style.density.rowGap' => ['style' => ['density' => ['rowGap' => 400]]],
            'tokens.pageRadius' => ['tokens' => ['pageRadius' => '40px']],
        ] as $campo => $datos) {
            $validator = $this->validate($datos);

            $this->assertTrue($validator->fails(), "{$campo} deberia haber fallado");
            $this->assertArrayHasKey($campo, $validator->errors()->toArray());
        }
    }

    /** Un numero sin unidad no es un valor CSS, y `themeVars()` lo emitiria tal cual. */
    public function test_it_rejects_a_css_value_without_its_unit(): void
    {
        $validator = $this->validate(['style' => ['hero' => ['tracking' => '-0.02']]]);

        $this->assertTrue($validator->fails());
    }

    /**
     * Una clave que no esta declarada no se rechaza: no se guarda.
     *
     * Es la diferencia entre lo que el tema deja tocar y lo que pertenece al paquete —el
     * kicker, la tarjeta, las familias tipograficas—. Mandar `fontSerif` no es un error del
     * cliente, simplemente no es suyo.
     */
    public function test_it_drops_keys_that_are_not_part_of_a_variant(): void
    {
        $validator = $this->validate(['tokens' => ['brand' => '#1a1a1a', 'fontSerif' => 'Comic Sans']]);

        $this->assertFalse($validator->fails());
        $this->assertSame(['brand' => '#1a1a1a'], $validator->validated()['tokens']);
    }

    /** Sin retoques, `tokens` y `style` salen como objeto y no como lista. */
    public function test_a_pristine_variant_serialises_empty_objects(): void
    {
        $variante = new SnkKilkanaThemeVariantModel([
            'slug' => 'standard',
            'source' => SnkKilkanaThemeSourceEnum::Catalog->value,
            'spec' => ['masthead' => 'daily', 'tokens' => ['brand' => '#121212'], 'style' => []],
        ]);

        $payload = $variante->payload();

        $this->assertSame('{}', json_encode($payload['tokens']));
        $this->assertSame('catalog', $payload['source']);
        // El spec resuelto sin retoques es el spec tal cual.
        $this->assertSame('#121212', $payload['spec']->tokens['brand']);
    }

    /**
     * Con retoque, el resuelto lo aplica encima y el suelto sigue viajando aparte.
     *
     * El retoque que no es de color son dos cosas: el aire (`density`) y la APERTURA (`hero`).
     * Los otros nueve peldanos son la escala del paquete, y tocarlos uno a uno seria redisenar
     * el tema en vez de vestirlo.
     */
    public function test_the_resolved_spec_layers_the_newsroom_tweak_on_top(): void
    {
        $variante = new SnkKilkanaThemeVariantModel([
            'slug' => 'verano',
            'source' => SnkKilkanaThemeSourceEnum::Tenant->value,
            'spec' => [
                'tokens' => ['brand' => '#121212', 'accent' => '#d32f2f'],
                'density' => ['rowGap' => 44, 'blockGap' => 18],
                'type' => [
                    'display' => 'Georgia, serif',
                    'steps' => [
                        'hero' => ['size' => 54, 'weight' => 700, 'leading' => 1.09],
                        'card' => ['size' => 20, 'weight' => 700, 'leading' => 1.18],
                    ],
                ],
            ],
            'tokens' => ['brand' => '#0000ff'],
            'style' => ['density' => ['rowGap' => 60], 'hero' => ['size' => 64]],
        ]);

        $resuelto = $variante->resolvedSpec();

        $this->assertSame('#0000ff', $resuelto['tokens']['brand']);
        $this->assertSame('#d32f2f', $resuelto['tokens']['accent'], 'lo que no se toca se hereda del spec');
        $this->assertSame(60, $resuelto['density']['rowGap']);
        $this->assertSame(18, $resuelto['density']['blockGap'], 'lo que no se toca se hereda del spec');
        $this->assertSame(64, $resuelto['type']['steps']['hero']['size']);
        $this->assertSame(700, $resuelto['type']['steps']['hero']['weight'], 'el peldano se mezcla, no se sustituye');
        $this->assertSame(20, $resuelto['type']['steps']['card']['size'], 'los otros peldanos no se tocan');
        $this->assertSame('Georgia, serif', $resuelto['type']['display']);
    }

    /**
     * El payload de ajustes: plano en la base, anidado en la API, y con la variante resuelta.
     *
     * `version` no viaja: un control optimista que ningun cliente manda no protege de nada, y
     * el numero solo daria la impresion de que si.
     */
    public function test_settings_payload_nests_the_tenant_and_resolves_the_variant(): void
    {
        $variante = new SnkKilkanaThemeVariantModel([
            'slug' => 'verano',
            'source' => SnkKilkanaThemeSourceEnum::Tenant->value,
            'spec' => ['masthead' => 'daily', 'tokens' => ['brand' => '#121212'], 'style' => []],
        ]);
        $variante->id = 3;

        $ajustes = new SnkKilkanaSettingModel([
            'theme_variant_id' => 3,
            'tenant_name' => 'The Beacon',
            'tenant_city' => 'Springfield',
            'onboarded' => true,
        ]);
        $ajustes->setRelation('themeVariant', $variante);

        $payload = app(SnkKilkanaSettingService::class)->payload($ajustes);

        $this->assertSame(3, $payload['theme_variant_id']);
        // La variante viaja con su spec resuelto: quien pinta no necesita otra llamada.
        $this->assertSame('daily', $payload['theme']['spec']->masthead);
        $this->assertSame('tenant', $payload['theme']['source']);
        $this->assertSame(['name' => 'The Beacon', 'tagline' => null, 'city' => 'Springfield', 'url' => null], $payload['tenant']);
        $this->assertTrue($payload['onboarded']);
        $this->assertArrayNotHasKey('version', $payload);
    }
}
