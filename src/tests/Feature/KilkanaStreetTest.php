<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Packages\Kilkana\Models\SnkKilkanaAuthorModel;
use Packages\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Kilkana\Models\SnkKilkanaMediaModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Models\SnkKilkanaPostViewModel;
use Packages\Kilkana\Models\SnkKilkanaSettingModel;
use Packages\Kilkana\Services\SnkKilkanaPublicService;
use Packages\Kilkana\Services\SnkKilkanaRoleCatalogService;
use Tests\TestCase;

/**
 * Lo que la calle necesita del periódico y no puede pedir de otra manera: la cabecera del
 * sitio, y las fotos del cuerpo de una pieza resueltas, porque la mediateca es del panel.
 */
class KilkanaStreetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::setDefaultConnection('tenant');
        foreach (['Core', 'Kilkana'] as $paquete) {
            $this->artisan('migrate', [
                '--database' => 'tenant',
                '--path' => "packages/{$paquete}/database/migrations",
            ])->run();
        }

        app(SnkKilkanaRoleCatalogService::class)->sync();
    }

    public function test_el_sitio_trae_la_cabecera_aunque_no_haya_tema(): void
    {
        SnkKilkanaSettingModel::create(['id' => 1, 'tenant_name' => 'El Diario', 'tenant_tagline' => 'La comarca, contada cada día']);

        $sitio = app(SnkKilkanaPublicService::class)->site();

        $this->assertSame('El Diario', $sitio['tenant']['name']);
        $this->assertSame('La comarca, contada cada día', $sitio['tenant']['tagline']);
        $this->assertNull($sitio['theme']);
    }

    public function test_una_pieza_publica_trae_las_fotos_de_su_cuerpo_resueltas(): void
    {
        $seccion = SnkKilkanaCategoryModel::create(['slug' => 'local', 'name' => 'Local', 'status' => 'live', 'position' => 0]);
        $firma = SnkKilkanaAuthorModel::create(['slug' => 'redaccion', 'alias' => 'Redacción']);
        $foto = SnkKilkanaMediaModel::create(['url' => '/storage/a.jpg', 'url_hash' => sha1('a'), 'alt' => 'La plaza', 'caption' => 'Ayer', 'credit' => 'E. D.', 'width' => 1600, 'height' => 900]);
        SnkKilkanaPostModel::create([
            'title' => 'Plan', 'slug' => 'plan', 'url_date' => '2026-07-01', 'category_id' => $seccion->id, 'author_id' => $firma->id,
            'status' => 'published', 'publish_at' => '2026-07-01 08:00:00', 'excerpt' => 'Entradilla.',
            'body_blocks' => [
                ['id' => 'p1', 'type' => 'paragraph', 'config' => ['text' => 'Hola']],
                ['id' => 'i1', 'type' => 'image', 'config' => ['media_id' => $foto->id, 'caption' => 'Ayer']],
                ['id' => 'i2', 'type' => 'image', 'config' => ['media_id' => 999]],
            ],
        ]);

        $pieza = app(SnkKilkanaPublicService::class)->post('2026', '07', '01', 'plan')->toArray();

        $this->assertSame([[
            'id' => (string) $foto->id, 'url' => '/storage/a.jpg', 'alt' => 'La plaza', 'caption' => 'Ayer', 'credit' => 'E. D.', 'width' => 1600, 'height' => 900,
        ]], $pieza['media'], 'Solo las que existen, y con lo que la calle pinta.');
        $this->assertSame('image', $pieza['body_blocks'][1]['type']);
    }

    /** «Lo más leído» se ordena por lecturas, y una lectura es un `read`: si eso revienta, la cifra es cero para siempre. */
    public function test_una_lectura_cuenta_y_el_mismo_visitante_es_un_solo_lector(): void
    {
        $seccion = SnkKilkanaCategoryModel::create(['slug' => 'local', 'name' => 'Local', 'status' => 'live', 'position' => 0]);
        $firma = SnkKilkanaAuthorModel::create(['slug' => 'redaccion', 'alias' => 'Redacción']);
        $pieza = SnkKilkanaPostModel::create([
            'title' => 'Plan', 'slug' => 'plan', 'url_date' => '2026-07-01', 'category_id' => $seccion->id, 'author_id' => $firma->id,
            'status' => 'published', 'publish_at' => '2026-07-01 08:00:00', 'excerpt' => 'Entradilla.', 'body_blocks' => [],
        ]);
        $visitante = Request::create('/', 'POST', server: ['REMOTE_ADDR' => '10.0.0.7', 'HTTP_USER_AGENT' => 'lector']);
        $servicio = app(SnkKilkanaPublicService::class);

        $this->assertTrue($servicio->recordView('2026', '07', '01', 'plan', 'open', $visitante));
        $this->assertTrue($servicio->recordView('2026', '07', '01', 'plan', 'read', $visitante));
        $this->assertTrue($servicio->recordView('2026', '07', '01', 'plan', 'read', $visitante));
        $this->assertFalse($servicio->recordView('2026', '07', '01', 'nadie', 'read', $visitante));

        $hoy = SnkKilkanaPostViewModel::query()->where('post_id', $pieza->id)->where('date', now()->toDateString())->first();
        $this->assertSame(1, (int) $hoy->opens);
        $this->assertSame(2, (int) $hoy->engaged);
        $this->assertSame(1, (int) $hoy->readers, 'Dos lecturas del mismo visitante en el día son un lector.');
    }
}
