<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Http\Controllers\SnkKilkanaPostsController;
use Packages\Kilkana\Models\SnkKilkanaAuthorModel;
use Packages\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Kilkana\Models\SnkKilkanaMediaModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Services\SnkKilkanaRoleCatalogService;
use Tests\TestCase;

/**
 * La portada de una pieza es un medio de la mediateca, y guardar una pieza no inventa medios:
 * el id se comprueba contra la tabla, y lo que no existe se rechaza en vez de acuñarse.
 */
class KilkanaPostCoverTest extends TestCase
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

    public function test_la_portada_se_pone_por_id_se_quita_con_null_y_un_id_falso_no_crea_nada(): void
    {
        $marta = CoreUserModel::create(['name' => 'Marta Beneyto', 'email' => 'marta@eldiario.example', 'password' => 'secret1234']);
        $marta->assignRole('director');
        $this->actingAs($marta, 'sanctum');

        $seccion = SnkKilkanaCategoryModel::create(['slug' => 'local', 'name' => 'Local', 'status' => 'live', 'position' => 0]);
        $firma = SnkKilkanaAuthorModel::create(['slug' => 'redaccion', 'alias' => 'Redacción']);
        $foto = SnkKilkanaMediaModel::create(['url' => '/storage/a.jpg', 'url_hash' => sha1('a'), 'alt' => 'La plaza']);
        $pieza = SnkKilkanaPostModel::create([
            'title' => 'Plan', 'slug' => 'plan', 'category_id' => $seccion->id, 'author_id' => $firma->id,
            'status' => 'draft', 'body_blocks' => [], 'created_by' => $marta->id, 'updated_by' => $marta->id,
        ]);
        $guardar = fn (array $cuerpo) => app(SnkKilkanaPostsController::class)->update(Request::create('/', 'PUT', $cuerpo), $pieza->id);

        $this->assertSame(200, $guardar(['cover_media_id' => $foto->id])->getStatusCode());
        $this->assertSame($foto->id, $pieza->fresh()->cover_media_id);

        $respuesta = $guardar(['cover_media_id' => $foto->id + 1]);
        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertArrayHasKey('cover_media_id', $respuesta->getData(true));
        $this->assertSame($foto->id, $pieza->fresh()->cover_media_id, 'Un id falso no toca la pieza.');
        $this->assertSame(1, SnkKilkanaMediaModel::query()->count(), 'Y no acuña ningún medio.');

        $this->assertSame(200, $guardar(['cover_media_id' => null])->getStatusCode());
        $this->assertNull($pieza->fresh()->cover_media_id, 'Vacío quita la foto.');
    }

    /** Quien guarda desde una versión vieja no pisa la nueva: se le dice quién y cuándo. */
    public function test_guardar_desde_una_version_vieja_devuelve_quien_escribio_encima(): void
    {
        $marta = CoreUserModel::create(['name' => 'Marta Beneyto', 'email' => 'marta@eldiario.example', 'password' => 'secret1234']);
        $marta->assignRole('director');
        $this->actingAs($marta, 'sanctum');
        $seccion = SnkKilkanaCategoryModel::create(['slug' => 'local', 'name' => 'Local', 'status' => 'live', 'position' => 0]);
        $firma = SnkKilkanaAuthorModel::create(['slug' => 'redaccion', 'alias' => 'Redacción']);
        $pieza = SnkKilkanaPostModel::create(['title' => 'Plan', 'slug' => 'plan', 'category_id' => $seccion->id, 'author_id' => $firma->id, 'status' => 'draft', 'body_blocks' => [], 'created_by' => $marta->id, 'updated_by' => $marta->id]);
        $vieja = $pieza->updated_at->toIso8601String();
        $guardar = fn (array $cuerpo) => app(SnkKilkanaPostsController::class)->update(Request::create('/', 'PUT', $cuerpo), $pieza->id);

        $this->travel(1)->minute();
        $this->assertSame(200, $guardar(['title' => 'Plan B', 'expected_updated_at' => $vieja])->getStatusCode(), 'La primera escritura desde esa versión entra.');

        $tarde = $guardar(['title' => 'Plan C', 'expected_updated_at' => $vieja]);
        $this->assertSame(409, $tarde->getStatusCode());
        $this->assertSame('Marta Beneyto', $tarde->getData(true)['updated_by']);
        $this->assertSame('Plan B', $pieza->fresh()->title, 'Y no pisa.');
        $this->assertSame(200, $guardar(['title' => 'Plan D'])->getStatusCode(), 'Sin versión declarada, como siempre.');

        // El mismo instante en otra variante ISO —milisegundos y Z— es la misma versión.
        $ahora = $pieza->fresh()->updated_at->copy()->utc()->format('Y-m-d\\TH:i:s.000\\Z');
        $this->assertSame(200, $guardar(['title' => 'Plan E', 'expected_updated_at' => $ahora])->getStatusCode());
        $this->assertSame(409, $guardar(['title' => 'Plan F', 'expected_updated_at' => 'no es una fecha'])->getStatusCode(), 'Lo que no parsea no es una versión.');
    }
}
