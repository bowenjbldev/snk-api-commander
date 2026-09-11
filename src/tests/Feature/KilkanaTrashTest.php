<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Http\Controllers\SnkKilkanaTrashController;
use Packages\Kilkana\Models\SnkKilkanaActivityModel;
use Packages\Kilkana\Models\SnkKilkanaAuthorModel;
use Packages\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Kilkana\Models\SnkKilkanaMediaModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Services\SnkKilkanaPublicService;
use Packages\Kilkana\Services\SnkKilkanaRoleCatalogService;
use Packages\Kilkana\Services\SnkKilkanaTrashService;
use Tests\TestCase;

/**
 * La papelera es una cuenta atrás con la URL reservada. Lo que se fija: que una pieza tirada
 * desaparece del panel y de la calle pero su URL no se puede reutilizar, que restaurarla la
 * devuelve como borrador con la misma URL, que un medio tirado dice qué piezas publicadas lo
 * llevan, que purgar y vaciar se van de verdad y la purga nocturna respeta el plazo, y que
 * cada clase entra con el permiso de borrarla.
 */
class KilkanaTrashTest extends TestCase
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
        // SQLite no aplica las claves foráneas si no se le pide: purgar un medio deja la
        // portada a null por `nullOnDelete`, y sin esto la columna se queda apuntando a nada.
        DB::statement('PRAGMA foreign_keys = ON');
    }

    public function test_una_pieza_tirada_reserva_su_url_y_vuelve_como_borrador(): void
    {
        $marta = $this->cuenta('marta@eldiario.example', 'Marta Beneyto', 'director');
        $this->actingAs($marta, 'sanctum');
        $pieza = $this->pieza('Plan de aparcamientos');
        $ruta = $pieza->url_path;

        $pieza->delete();

        $this->assertNull(SnkKilkanaPostModel::query()->find($pieza->id), 'El panel no la ve.');
        $this->assertNull(app(SnkKilkanaPublicService::class)->post('2026', '07', '01', 'plan-de-aparcamientos'), 'La calle tampoco.');
        $this->assertSame($marta->id, SnkKilkanaPostModel::withTrashed()->find($pieza->id)->deleted_by);
        $this->assertTrue(SnkKilkanaPostModel::pathTaken('2026-07-01', 'plan-de-aparcamientos'), 'La URL sigue reservada.');

        $lista = $this->papelera()->index()->getData(true);
        $this->assertSame(['post'], array_column($lista['data'], 'kind'));
        $this->assertEquals(['who' => 'Marta Beneyto', 'days_left' => 30, 'path' => '/'.$ruta], array_intersect_key($lista['data'][0], ['who' => 1, 'days_left' => 1, 'path' => 1]));

        $this->assertSame(200, $this->papelera()->restore('post', $pieza->id)->getStatusCode());
        $vuelta = SnkKilkanaPostModel::query()->find($pieza->id);
        $this->assertSame('draft', $vuelta->status->value, 'Vuelve como borrador, no publicada.');
        $this->assertSame($ruta, $vuelta->url_path, 'Con la misma URL.');
        $this->assertNull($vuelta->deleted_by);
        $this->assertSame(['deleted', 'restored_from_trash'], SnkKilkanaActivityModel::query()->where('kind', '<>', 'category')->where('kind', '<>', 'edit')->orderBy('id')->pluck('verb')->all());
    }

    public function test_un_medio_tirado_dice_que_piezas_lo_llevan_y_purgar_se_lo_lleva(): void
    {
        $this->actingAs($this->cuenta('marta@eldiario.example', 'Marta Beneyto', 'director'), 'sanctum');
        $foto = SnkKilkanaMediaModel::create(['url' => 'https://cdn.example/a.jpg', 'url_hash' => sha1('a'), 'title' => 'Plaza mayor', 'width' => 1600, 'height' => 900, 'size_bytes' => 120000]);
        $portada = $this->pieza('Plan de aparcamientos', ['cover_media_id' => $foto->id]);
        $this->pieza('Fiestas de agosto', ['body_blocks' => [['type' => 'image', 'config' => ['media_id' => $foto->id]]]]);
        $this->pieza('Un borrador', ['status' => 'draft', 'cover_media_id' => $foto->id]);
        $foto->delete();

        $fila = $this->papelera()->index()->getData(true)['data'][0];
        $this->assertSame('media', $fila['kind']);
        $this->assertSame('1600 × 900', $fila['path']);
        $this->assertSame(['Fiestas de agosto', 'Plan de aparcamientos', 'Un borrador'], collect($fila['refs'])->sort()->values()->all(), 'Un borrador pierde la foto igual: cuenta.');

        $this->assertSame(200, $this->papelera()->purge('media', $foto->id)->getStatusCode());
        $this->assertNull(SnkKilkanaMediaModel::withTrashed()->find($foto->id));
        $this->assertNull($portada->fresh()->cover_media_id, 'La portada se queda sin foto: eso es lo que purgar rompe.');
        $this->assertSame(404, $this->papelera()->purge('media', $foto->id)->getStatusCode());
    }

    public function test_vaciar_purga_y_la_purga_nocturna_respeta_el_plazo(): void
    {
        $this->actingAs($this->cuenta('marta@eldiario.example', 'Marta Beneyto', 'director'), 'sanctum');
        $vieja = $this->pieza('Vieja');
        $nueva = $this->pieza('Nueva');
        $vieja->delete();
        $nueva->delete();
        SnkKilkanaPostModel::withTrashed()->whereKey($vieja->id)->update(['deleted_at' => now()->subDays(31)]);

        $this->assertSame(1, app(SnkKilkanaTrashService::class)->purgeExpired());
        $this->assertNull(SnkKilkanaPostModel::withTrashed()->find($vieja->id));
        $this->assertNotNull(SnkKilkanaPostModel::withTrashed()->find($nueva->id), 'La reciente sigue esperando.');

        $this->assertSame(['purged' => 1, 'kinds' => ['post', 'media']], $this->papelera()->empty()->getData(true));
        $this->assertSame(0, SnkKilkanaPostModel::withTrashed()->count());
    }

    /** Cada clase entra con el permiso de borrarla: la mesa de edición ve piezas y medios; la moderación, nada. */
    public function test_cada_clase_entra_con_el_permiso_de_borrarla(): void
    {
        $director = $this->cuenta('marta@eldiario.example', 'Marta Beneyto', 'director');
        $this->actingAs($director, 'sanctum');
        $this->pieza('Plan de aparcamientos')->delete();

        $this->actingAs($this->cuenta('elena@eldiario.example', 'Elena Vara', 'moderador'), 'sanctum');
        $this->assertSame(403, $this->papelera()->index()->getStatusCode());
        $this->assertSame(403, $this->papelera()->empty()->getStatusCode());

        $this->actingAs($this->cuenta('ruben@eldiario.example', 'Rubén Nieto', 'colaborador'), 'sanctum');
        $this->assertSame(403, $this->papelera()->index()->getStatusCode());

        $this->actingAs($director, 'sanctum');
        $this->assertSame(404, $this->papelera()->restore('banana', 1)->getStatusCode());
        $this->assertSame(1, $this->papelera(['counts' => '1'])->index()->getData(true)['count']);
    }

    private function papelera(array $query = []): SnkKilkanaTrashController
    {
        request()->query->replace($query);

        return app(SnkKilkanaTrashController::class);
    }

    private function cuenta(string $email, string $nombre, string $rol): CoreUserModel
    {
        $user = CoreUserModel::create(['name' => $nombre, 'email' => $email, 'password' => 'secret1234']);
        $user->assignRole($rol);

        return $user;
    }

    private function pieza(string $titulo, array $extra = []): SnkKilkanaPostModel
    {
        $seccion = SnkKilkanaCategoryModel::firstOrCreate(['slug' => 'local'], ['name' => 'Local', 'status' => 'live', 'position' => 0]);
        $firma = SnkKilkanaAuthorModel::firstOrCreate(['slug' => 'redaccion'], ['alias' => 'Redacción']);

        return SnkKilkanaPostModel::create([
            'title' => $titulo,
            'slug' => str($titulo)->slug()->value(),
            'url_date' => '2026-07-01',
            'category_id' => $seccion->id,
            'author_id' => $firma->id,
            'status' => 'published',
            'publish_at' => '2026-07-01 08:00:00',
            'excerpt' => 'Una entradilla.',
            ...$extra,
        ]);
    }
}
