<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Validator;
use Packages\Kilkana\Models\SnkKilkanaSettingModel;
use Packages\Kilkana\Models\SnkKilkanaThemeModel;
use Packages\Kilkana\Services\SnkKilkanaSettingService;
use Packages\Kilkana\Services\SnkKilkanaThemeWearService;
use Tests\TestCase;

/**
 * El vestido del periodico: los rangos del retoque y el mapeo que sale a la API.
 *
 * Estos casos no tocan la base a proposito: las migraciones de negocio de Kilkana no se cargan
 * en la conexion por defecto (ver `CoreServiceProvider::boot`) y la suite corre sobre sqlite,
 * que no admite el `ALTER TABLE ... ADD CONSTRAINT CHECK` con el que se declara el singleton.
 * Lo que si es comprobable aqui, y es lo que importa, es que un valor fuera de banda no entre.
 */
class SnkKilkanaThemeWearTest extends TestCase
{
    private function validate(array $data): \Illuminate\Validation\Validator
    {
        return Validator::make($data, SnkKilkanaSettingModel::shapeRules());
    }

    public function test_it_accepts_a_tweak_within_range(): void
    {
        $validator = $this->validate([
            'theme_overrides' => [
                'tokens' => ['brand' => '#1A1A1A', 'pageRadius' => '10px'],
                'density' => ['rowGap' => 44, 'padX' => 28],
                'hero' => ['size' => 54, 'weight' => 700, 'tracking' => '-0.02em', 'leading' => 1.1],
            ],
        ]);

        $this->assertFalse($validator->fails(), (string) $validator->errors());
    }

    public function test_it_accepts_the_edges_of_every_range(): void
    {
        $validator = $this->validate([
            'theme_overrides' => [
                'tokens' => ['pageRadius' => '0px'],
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
        $validator = $this->validate(['theme_overrides' => ['tokens' => ['brand' => 'red']]]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('theme_overrides.tokens.brand', $validator->errors()->toArray());
    }

    public function test_it_rejects_values_outside_their_range(): void
    {
        foreach ([
            'theme_overrides.hero.size' => ['hero' => ['size' => 300]],
            'theme_overrides.hero.weight' => ['hero' => ['weight' => 650]],
            'theme_overrides.hero.leading' => ['hero' => ['leading' => 4]],
            'theme_overrides.hero.tracking' => ['hero' => ['tracking' => '-1em']],
            'theme_overrides.density.padX' => ['density' => ['padX' => 99]],
            'theme_overrides.density.rowGap' => ['density' => ['rowGap' => 400]],
            'theme_overrides.tokens.pageRadius' => ['tokens' => ['pageRadius' => '40px']],
        ] as $field => $data) {
            $validator = $this->validate(['theme_overrides' => $data]);

            $this->assertTrue($validator->fails(), "{$field} deberia haber fallado");
            $this->assertArrayHasKey($field, $validator->errors()->toArray());
        }
    }

    /** Un numero sin unidad no es un valor CSS, y `themeVars()` lo emitiria tal cual. */
    public function test_it_rejects_a_css_value_without_its_unit(): void
    {
        $validator = $this->validate(['theme_overrides' => ['hero' => ['tracking' => '-0.02']]]);

        $this->assertTrue($validator->fails());
    }

    /**
     * Una clave que no esta declarada no se rechaza: no se guarda.
     *
     * Es la diferencia entre lo que el tema deja tocar y lo que es del tema —el kicker, la
     * tarjeta, las familias tipograficas—. Mandar `fontSerif` no es un error del cliente,
     * simplemente no es suyo.
     */
    public function test_it_drops_keys_that_are_not_part_of_the_tweak(): void
    {
        $validator = $this->validate([
            'theme_overrides' => ['tokens' => ['brand' => '#1a1a1a', 'fontSerif' => 'Comic Sans']],
        ]);

        $this->assertFalse($validator->fails());
        $this->assertSame(['brand' => '#1a1a1a'], $validator->validated()['theme_overrides']['tokens']);
    }

    /** Sin retoques, el retoque sale como objeto y no como lista. */
    public function test_a_theme_without_overrides_serialises_them_as_an_empty_object(): void
    {
        $settings = new SnkKilkanaSettingModel([
            'theme_spec' => ['masthead' => 'daily', 'tokens' => ['brand' => '#121212']],
            'theme_version' => '2.0.0',
        ]);

        $payload = $settings->themePayload();

        $this->assertSame('{}', json_encode($payload['overrides']));
        // El spec resuelto sin retoques es el spec tal cual.
        $this->assertSame('#121212', $payload['spec']->tokens['brand']);
    }

    /**
     * Con retoque, el resuelto lo aplica encima y el suelto sigue viajando aparte.
     *
     * El retoque que no es de color son dos cosas: el aire (`density`) y la APERTURA (`hero`).
     * Los otros nueve peldanos son la escala del tema, y tocarlos uno a uno seria redisenarlo
     * en vez de vestirlo.
     */
    public function test_the_resolved_spec_layers_the_newsroom_tweak_on_top(): void
    {
        $settings = new SnkKilkanaSettingModel([
            'theme_spec' => [
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
            'theme_overrides' => [
                'tokens' => ['brand' => '#0000ff'],
                'density' => ['rowGap' => 60],
                'hero' => ['size' => 64],
            ],
        ]);

        $resolved = $settings->resolvedSpec();

        $this->assertSame('#0000ff', $resolved['tokens']['brand']);
        $this->assertSame('#d32f2f', $resolved['tokens']['accent'], 'lo que no se toca se hereda del spec');
        $this->assertSame(60, $resolved['density']['rowGap']);
        $this->assertSame(18, $resolved['density']['blockGap'], 'lo que no se toca se hereda del spec');
        $this->assertSame(64, $resolved['type']['steps']['hero']['size']);
        $this->assertSame(700, $resolved['type']['steps']['hero']['weight'], 'el peldano se mezcla, no se sustituye');
        $this->assertSame(20, $resolved['type']['steps']['card']['size'], 'los otros peldanos no se tocan');
        $this->assertSame('Georgia, serif', $resolved['type']['display']);
    }

    /**
     * El payload de ajustes: plano en la base, anidado en la API, y con el tema resuelto.
     *
     * `version` no viaja: un control optimista que ningun cliente manda no protege de nada, y
     * el numero solo daria la impresion de que si.
     */
    public function test_settings_payload_nests_the_tenant_and_resolves_the_theme(): void
    {
        $theme = new SnkKilkanaThemeModel(['slug' => 'folio', 'name' => 'Folio', 'version' => '2.0.0']);
        $theme->id = 3;

        $settings = new SnkKilkanaSettingModel([
            'theme_id' => 3,
            'theme_spec' => ['masthead' => 'daily', 'tokens' => ['brand' => '#121212']],
            'theme_version' => '2.0.0',
            'tenant_name' => 'The Beacon',
            'tenant_city' => 'Springfield',
            'launched_at' => '2026-09-07T10:00:00+00:00',
        ]);
        $settings->setRelation('theme', $theme);

        $payload = app(SnkKilkanaSettingService::class)->payload($settings);

        $this->assertSame(3, $payload['theme_id']);
        // El tema viaja con su spec resuelto: quien pinta no necesita otra llamada.
        $this->assertSame('daily', $payload['theme']['spec']->masthead);
        $this->assertSame('folio', $payload['theme']['slug']);
        $this->assertSame(['name' => 'The Beacon', 'tagline' => null, 'city' => 'Springfield', 'url' => null], $payload['tenant']);
        $this->assertSame('2026-09-07T10:00:00+00:00', $payload['launched_at']);
        $this->assertArrayNotHasKey('version', $payload);
    }

    /**
     * El aviso de actualizacion compara VERSIONES, no los dos JSON.
     *
     * La version es lo que sube el fichero maestro al publicar, asi que es la unica senal que no
     * depende de como se serialice un spec.
     */
    public function test_it_flags_an_update_only_when_the_catalogue_moved_on(): void
    {
        $theme = new SnkKilkanaThemeModel(['slug' => 'folio', 'version' => '2.1.0']);

        $settings = new SnkKilkanaSettingModel(['theme_version' => '2.0.0']);
        $settings->setRelation('theme', $theme);
        $this->assertTrue(app(SnkKilkanaThemeWearService::class)->updateAvailable($settings));

        $upToDate = new SnkKilkanaSettingModel(['theme_version' => '2.1.0']);
        $upToDate->setRelation('theme', $theme);
        $this->assertFalse(app(SnkKilkanaThemeWearService::class)->updateAvailable($upToDate));

        // Sin tema del catalogo detras —retirado, o base recien sembrada— no hay con que comparar.
        $orphan = new SnkKilkanaSettingModel(['theme_version' => '2.0.0']);
        $orphan->setRelation('theme', null);
        $this->assertFalse(app(SnkKilkanaThemeWearService::class)->updateAvailable($orphan));
    }
}
