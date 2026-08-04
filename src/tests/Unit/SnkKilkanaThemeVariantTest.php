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
 * Lo que si es comprobable aqui es justo lo nuevo: que un valor fuera de banda no entre.
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
            'style' => ['headlineWeight' => 700, 'headlineScale' => 0.9, 'headlineTracking' => '-0.02em', 'blockPadX' => '14px'],
        ]);

        $this->assertFalse($validator->fails(), (string) $validator->errors());
    }

    public function test_it_accepts_the_edges_of_every_range(): void
    {
        $validator = $this->validate([
            'tokens' => ['pageRadius' => '0px'],
            'style' => ['headlineWeight' => 600, 'headlineScale' => 0.8, 'headlineTracking' => '-0.04em', 'blockPadY' => '18px'],
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
            'style.headlineScale' => ['style' => ['headlineScale' => 3]],
            'style.headlineWeight' => ['style' => ['headlineWeight' => 650]],
            'style.blockPadX' => ['style' => ['blockPadX' => '99px']],
            'style.headlineTracking' => ['style' => ['headlineTracking' => '-1em']],
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
        $validator = $this->validate(['style' => ['blockPadX' => '14']]);

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

    /** Con retoque, el resuelto lo aplica encima y el suelto sigue viajando aparte. */
    public function test_the_resolved_spec_layers_the_newsroom_tweak_on_top(): void
    {
        $variante = new SnkKilkanaThemeVariantModel([
            'slug' => 'verano',
            'source' => SnkKilkanaThemeSourceEnum::Tenant->value,
            'spec' => ['tokens' => ['brand' => '#121212', 'accent' => '#d32f2f'], 'style' => ['headlineScale' => 1]],
            'tokens' => ['brand' => '#0000ff'],
            'style' => ['headlineScale' => 0.9],
        ]);

        $resuelto = $variante->resolvedSpec();

        $this->assertSame('#0000ff', $resuelto['tokens']['brand']);
        $this->assertSame('#d32f2f', $resuelto['tokens']['accent'], 'lo que no se toca se hereda del spec');
        $this->assertSame(0.9, $resuelto['style']['headlineScale']);
    }

    /**
     * El payload de ajustes: plano en la base, anidado en la API, y con la variante resuelta.
     *
     * `version` ya no sale. Era control optimista opcional que ningun cliente mandaba, asi que
     * el numero no garantizaba nada; quien lea esto y lo eche de menos, esa es la razon.
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
