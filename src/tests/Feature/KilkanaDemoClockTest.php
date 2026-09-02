<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Packages\Kilkana\Database\Seeders\SnkKilkanaDatabaseSeeder;
use Packages\Kilkana\Enums\SnkKilkanaCommentStatusEnum;
use Packages\Kilkana\Models\SnkKilkanaCommentModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Models\SnkKilkanaPostViewModel;
use Packages\Kilkana\Services\SnkKilkanaRoleCatalogService;
use Tests\TestCase;

/**
 * La demo no envejece.
 *
 * El snapshot esta escrito en fechas fijas, y una demo que abre en septiembre con su ultima
 * pieza de junio parece un periodico cerrado. Lo que se fija aqui es que el archivo se pone al
 * dia, que ponerlo al dia no duplica nada al resembrar, y que lo que cuelga de las piezas
 * —comentarios, lecturas— se mueve con ellas.
 */
class KilkanaDemoClockTest extends TestCase
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

        // Claves foraneas encendidas: borrar una pieza tiene que arrastrar sus comentarios,
        // como en MariaDB; sqlite las trae apagadas.
        DB::statement('PRAGMA foreign_keys = ON');

        config(['kilkana.kilkana.seed_demo' => true]);
        config(['database.connections.tenant.database' => 'demo_kilkana']);
        app(SnkKilkanaRoleCatalogService::class)->sync();
    }

    public function test_la_pieza_mas_reciente_es_de_hoy_y_resembrar_no_duplica(): void
    {
        (new SnkKilkanaDatabaseSeeder)->run();

        $ultima = SnkKilkanaPostModel::query()->published()->orderByDesc('publish_at')->first();
        $this->assertSame(now()->toDateString(), $ultima->publish_at->toDateString());
        $this->assertTrue($ultima->publish_at->lessThanOrEqualTo(now()), 'Publicada, no programada.');

        // Los comentarios de primer nivel son exactamente los que el seeder reparte: uno por
        // (pieza, posicion). Si dos lectores o dos frases colapsaran en la misma clave, el
        // `firstOrCreate` se comeria uno en silencio y esta cuenta lo diria.
        $esperados = SnkKilkanaPostModel::query()->published()->pluck('slug')
            ->sum(fn (string $slug) => SnkKilkanaDatabaseSeeder::commentsFor($slug));
        $this->assertSame($esperados, SnkKilkanaCommentModel::query()->whereNull('parent_id')->count());

        $piezas = SnkKilkanaPostModel::query()->count();
        // De primer nivel: una respuesta al comentario de la pieza de hoy puede nacer en la
        // siembra de dentro de tres dias, porque entonces ya hay hueco para contestar.
        $comentarios = SnkKilkanaCommentModel::query()->whereNull('parent_id')->count();

        // Y sin la pieza ancla de hoy: quien ensena la papelera borra la ultima, no la primera.
        $ultima->delete();
        $this->travel(3)->days();
        (new SnkKilkanaDatabaseSeeder)->run();

        $this->assertSame($piezas, SnkKilkanaPostModel::query()->count(), 'El reloj se ancla a la base, no a hoy.');
        $this->assertSame($comentarios, SnkKilkanaCommentModel::query()->whereNull('parent_id')->count());
    }

    public function test_los_comentarios_y_las_lecturas_van_con_su_pieza(): void
    {
        (new SnkKilkanaDatabaseSeeder)->run();

        $antesDeLaPieza = SnkKilkanaCommentModel::query()
            ->join('kilkana_posts', 'kilkana_posts.id', '=', 'kilkana_comments.post_id')
            ->whereColumn('kilkana_comments.created_at', '<', 'kilkana_posts.publish_at')
            ->count();
        $this->assertSame(0, $antesDeLaPieza, 'Nadie comenta lo que aun no se ha publicado.');

        $pendienteViejo = SnkKilkanaCommentModel::query()
            ->where('status', SnkKilkanaCommentStatusEnum::Pending)
            ->where('created_at', '<', now()->subDays(21))
            ->count();
        $this->assertSame(0, $pendienteViejo, 'La cola es de las ultimas tres semanas.');

        // Los de la pieza de hoy no caen todos en el mismo minuto.
        $hoy = SnkKilkanaCommentModel::query()
            ->whereIn('post_id', SnkKilkanaPostModel::query()->published()->where('publish_at', '>=', now()->subDay())->pluck('id'))
            ->pluck('created_at')->map(fn ($c) => $c->toDateTimeString());
        $this->assertGreaterThan(1, $hoy->unique()->count(), 'Los comentarios recientes no se apilan en un instante.');
        $this->assertGreaterThan(0, SnkKilkanaCommentModel::query()->where('status', SnkKilkanaCommentStatusEnum::Rejected)->count());

        $this->assertGreaterThan(0, SnkKilkanaPostViewModel::query()->where('date', now()->toDateString())->sum('engaged'), 'Hoy se lee.');
    }

    /** Lo que la tanda C reparte: revisiones, SEO, destacadas, suscriptores y fotos sin amontonar. */
    public function test_el_archivo_tiene_historial_seo_destacadas_y_lista(): void
    {
        (new SnkKilkanaDatabaseSeeder)->run();

        // Lo que la calle enseña es lo del snapshot, con o sin historial: sembrar el historial
        // escribe la version vieja y luego la buena, y salir entre las dos dejo una entradilla
        // de portada a la mitad con la suite en verde.
        // Por slug, saltando los dos que el snapshot repite en dias distintos: son piezas
        // legitimas y aqui no hay forma de saber cual de las dos es cual sin la fecha.
        $publicadas = collect(json_decode((string) file_get_contents(base_path('packages/Kilkana/database/seeders/data/posts.json')), true))
            ->where('status', 'published');
        $repetidos = $publicadas->countBy('slug')->filter(fn (int $n) => $n > 1)->keys();
        $snapshot = $publicadas->whereNotIn('slug', $repetidos)->keyBy('slug');
        $saltadas = 0;
        foreach (SnkKilkanaPostModel::query()->published()->get(['slug', 'title', 'excerpt']) as $pieza) {
            if (! isset($snapshot[$pieza->slug])) {
                $saltadas++;

                continue;
            }
            $this->assertSame($snapshot[$pieza->slug]['title'], $pieza->title, $pieza->slug);
            $this->assertSame($snapshot[$pieza->slug]['excerpt'], $pieza->excerpt, $pieza->slug);
        }
        // Exactamente las que no se pueden comparar: los dos slugs repetidos (cuatro filas) y la
        // mas antigua, que cambia de slug en el bloque de las dos revisiones. Un slug corrupto
        // seria la sexta, y esta cuenta lo diria.
        $this->assertSame($repetidos->count() * 2 + 1, $saltadas);

        $conHistorial = DB::table('kilkana_post_revisions')->distinct()->count('post_id');
        $this->assertGreaterThanOrEqual(40, $conHistorial, 'Un periodico con quinientas piezas y dos ediciones no existe.');
        $this->assertSame(0, DB::table('kilkana_post_revisions')
            ->join('kilkana_posts', 'kilkana_posts.id', '=', 'kilkana_post_revisions.post_id')
            ->whereColumn('kilkana_post_revisions.created_at', '<', 'kilkana_posts.publish_at')
            ->count(), 'Nadie edita antes de publicar.');

        $conSeo = SnkKilkanaPostModel::query()->whereNotNull('seo_description')->count();
        $this->assertGreaterThan(100, $conSeo);
        $this->assertLessThanOrEqual(158, SnkKilkanaPostModel::query()->whereNotNull('seo_description')->get()->max(fn ($p) => mb_strlen($p->seo_description)));
        $this->assertSame(6, SnkKilkanaPostModel::query()->where('featured', true)->count());

        // Exacto y no «más de trescientos»: un correo repetido se lo comería el `firstOrCreate`
        // en silencio, y perder veinte pasaría igual con una cota holgada.
        $this->assertSame(409, DB::table('kilkana_subscribers')->count());
        $this->assertGreaterThan(0, DB::table('kilkana_subscribers')->where('status', 'unsubscribed')->count());

        $porFoto = SnkKilkanaPostModel::query()->whereNotNull('cover_media_id')
            ->selectRaw('cover_media_id, count(*) as total')->groupBy('cover_media_id')->pluck('total');
        $this->assertLessThanOrEqual(12, $porFoto->max(), 'Ninguna foto en veinticuatro titulares.');
        $this->assertGreaterThanOrEqual(58, $porFoto->count(), 'Casi todos los ficheros se estrenan.');
    }
}
