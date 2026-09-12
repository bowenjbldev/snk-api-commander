<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Models\SnkKilkanaAuthorModel;
use Packages\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Services\SnkKilkanaPostService;
use Tests\TestCase;

/**
 * Sembrar dos veces tiene que dejar el mismo periodico, y esa es la parte que cede.
 *
 * El juego de datos de demo no solo crea filas: **edita**. Siembra dos revisiones de una pieza
 * y la ruta que esa edicion dejo atras, para que el historial del editor y los tres bloques de
 * «Route history» tengan algo dentro. El riesgo esta en la segunda siembra: el snapshot vuelve
 * a volcar el titulo con el que la pieza NACIO, y sin la regla que separa lo editorial de lo
 * estructural se quedaba con la URL nueva, su 301 y su revision — y el titular de antes. Un 301
 * que apunta a un titular que no cambio es lo contrario de lo que ese fixture viene a ensenar.
 *
 * No hace falta el snapshot entero para verlo: son tres piezas.
 */
class KilkanaDemoSeedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::setDefaultConnection('tenant');
        foreach (['Core', 'Kilkana'] as $package) {
            $this->artisan('migrate', [
                '--database' => 'tenant',
                '--path' => "packages/{$package}/database/migrations",
            ])->run();
        }
    }

    public function test_reseeding_does_not_revert_later_edits(): void
    {
        $post = $this->seedDemo();

        // Lo que hace `seedEditHistory`: editar de verdad, con el servicio.
        app(SnkKilkanaPostService::class)->update($post, [
            'title' => $post->title.' (actualizado)',
            'slug' => $post->slug.'-actualizado',
        ], (int) $post->created_by, false);

        // Y la siembra siguiente, que encuentra la pieza por la ruta que dejo atras.
        $other = $this->seedDemo();

        $this->assertSame($post->id, $other->id, 'La encuentra por su ruta retirada.');
        $this->assertSame('Pleno de julio (actualizado)', $other->title, 'Y no le devuelve el titulo del snapshot.');
        $this->assertSame('pleno-de-julio-actualizado', $other->slug);
    }

    /** Lo estructural sí se sincroniza: es lo que ata la pieza al resto del juego de datos. */
    public function test_reseeding_repairs_the_section_and_the_status(): void
    {
        $post = $this->seedDemo();
        $other = SnkKilkanaCategoryModel::create([
            'name' => 'Sucesos', 'slug' => 'sucesos', 'status' => 'live', 'position' => 1,
        ]);

        $post->forceFill(['category_id' => $other->id, 'featured' => true])->save();

        $reseeded = $this->seedDemo();

        $this->assertSame('local', $reseeded->category->slug, 'La sección vuelve a la del catálogo.');
        $this->assertFalse((bool) $reseeded->featured);
    }

    /**
     * La misma siembra que hace el seeder para una pieza, reducida a lo que la regla decide:
     * lo editorial solo al crear, lo estructural siempre.
     */
    private function seedDemo(): SnkKilkanaPostModel
    {
        $section = SnkKilkanaCategoryModel::firstOrCreate(
            ['slug' => 'local'],
            ['name' => 'Local', 'status' => 'live', 'position' => 0]
        );
        $account = CoreUserModel::firstOrCreate(
            ['email' => 'ramon@eldiario.example'],
            ['name' => 'Ramón Clavel', 'password' => 'secret1234']
        );
        $byline = SnkKilkanaAuthorModel::firstOrCreate(['user_id' => $account->id]);

        $editorial = ['title' => 'Pleno de julio', 'excerpt' => 'Entradilla del snapshot.'];
        $structure = [
            'category_id' => $section->id,
            'author_id' => $byline->id,
            'created_by' => $account->id,
            'status' => 'published',
            'publish_at' => '2026-07-01 08:00:00',
            'featured' => false,
        ];

        $retired = DB::table('kilkana_post_slugs')->where('path', '2026/07/01/pleno-de-julio')->first();
        $previous = $retired === null
            ? null
            : SnkKilkanaPostModel::query()->whereKey($retired->post_id)->first();

        $row = SnkKilkanaPostModel::firstOrCreate([
            'url_date' => $previous?->getRawOriginal('url_date') ?? '2026-07-01',
            'slug' => $previous?->slug ?? 'pleno-de-julio',
        ], $editorial + $structure);

        $row->fill($structure)->save();

        return $row->fresh();
    }
}
