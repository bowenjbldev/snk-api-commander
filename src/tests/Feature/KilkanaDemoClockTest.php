<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Database\Seeders\SnkKilkanaDatabaseSeeder;
use Packages\Kilkana\Enums\SnkKilkanaCommentStatusEnum;
use Packages\Kilkana\Enums\SnkKilkanaContentTierEnum;
use Packages\Kilkana\Models\SnkKilkanaCommentModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Models\SnkKilkanaPostViewModel;
use Packages\Kilkana\Models\SnkKilkanaReaderReadModel;
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
        foreach (['Core', 'Kilkana'] as $package) {
            $this->artisan('migrate', [
                '--database' => 'tenant',
                '--path' => "packages/{$package}/database/migrations",
            ])->run();
        }

        // Claves foraneas encendidas: borrar una pieza tiene que arrastrar sus comentarios,
        // como en MariaDB; sqlite las trae apagadas.
        DB::statement('PRAGMA foreign_keys = ON');

        config(['kilkana.kilkana.seed_demo' => true]);
        config(['database.connections.tenant.database' => 'demo_kilkana']);
        app(SnkKilkanaRoleCatalogService::class)->sync();
    }

    public function test_the_newest_post_is_from_today_and_reseeding_does_not_duplicate(): void
    {
        (new SnkKilkanaDatabaseSeeder)->run();

        $last = SnkKilkanaPostModel::query()->published()->orderByDesc('publish_at')->first();
        $this->assertSame(now()->toDateString(), $last->publish_at->toDateString());
        $this->assertTrue($last->publish_at->lessThanOrEqualTo(now()), 'Publicada, no programada.');

        // Los comentarios de primer nivel son exactamente los que el seeder reparte: uno por
        // (pieza, posicion). Si dos lectores o dos frases colapsaran en la misma clave, el
        // `firstOrCreate` se comeria uno en silencio y esta cuenta lo diria.
        // Con la papelera dentro: una pieza tirada conserva sus comentarios.
        $expected = SnkKilkanaPostModel::withTrashed()->published()->pluck('slug')
            ->sum(fn (string $slug) => SnkKilkanaDatabaseSeeder::commentsFor($slug));
        $this->assertSame($expected, SnkKilkanaCommentModel::query()->whereNull('parent_id')->count());

        // Con la papelera: borrar desde el panel es tirar, y la pieza tirada sigue en la base.
        $posts = SnkKilkanaPostModel::withTrashed()->count();
        // De primer nivel: una respuesta al comentario de la pieza de hoy puede nacer en la
        // siembra de dentro de tres dias, porque entonces ya hay hueco para contestar.
        $comments = SnkKilkanaCommentModel::query()->whereNull('parent_id')->count();

        // Y sin la pieza ancla de hoy: quien ensena la papelera borra la ultima, no la primera.
        $last->delete();
        $this->travel(3)->days();
        (new SnkKilkanaDatabaseSeeder)->run();

        $this->assertSame($posts, SnkKilkanaPostModel::withTrashed()->count(), 'El reloj se ancla a la base, no a hoy.');
        $this->assertTrue($last->fresh()->trashed(), 'Resembrar no saca nada de la papelera.');
        $this->assertSame($comments, SnkKilkanaCommentModel::query()->whereNull('parent_id')->count());
    }

    public function test_comments_and_reads_follow_their_post(): void
    {
        (new SnkKilkanaDatabaseSeeder)->run();

        $beforePost = SnkKilkanaCommentModel::query()
            ->join('kilkana_posts', 'kilkana_posts.id', '=', 'kilkana_comments.post_id')
            ->whereColumn('kilkana_comments.created_at', '<', 'kilkana_posts.publish_at')
            ->count();
        $this->assertSame(0, $beforePost, 'Nadie comenta lo que aun no se ha publicado.');

        $stalePending = SnkKilkanaCommentModel::query()
            ->where('status', SnkKilkanaCommentStatusEnum::Pending)
            ->where('created_at', '<', now()->subDays(21))
            ->count();
        $this->assertSame(0, $stalePending, 'La cola es de las ultimas tres semanas.');

        // Ningún comentario lleva el sello exacto de su pieza: ni los de hoy.
        $sameSecond = SnkKilkanaCommentModel::query()
            ->join('kilkana_posts', 'kilkana_posts.id', '=', 'kilkana_comments.post_id')
            ->whereColumn('kilkana_comments.created_at', '=', 'kilkana_posts.publish_at')
            ->count();
        $this->assertSame(0, $sameSecond, 'Un comentario en el mismo segundo que la pieza es un artefacto.');

        // Los de la pieza de hoy no caen todos en el mismo minuto.
        $today = SnkKilkanaCommentModel::query()
            ->whereIn('post_id', SnkKilkanaPostModel::query()->published()->where('publish_at', '>=', now()->subDay())->pluck('id'))
            ->pluck('created_at')->map(fn ($c) => $c->toDateTimeString());
        $this->assertGreaterThan(1, $today->unique()->count(), 'Los comentarios recientes no se apilan en un instante.');
        $this->assertGreaterThan(0, SnkKilkanaCommentModel::query()->where('status', SnkKilkanaCommentStatusEnum::Rejected)->count());

        $this->assertGreaterThan(0, SnkKilkanaPostViewModel::query()->where('date', now()->toDateString())->sum('engaged'), 'Hoy se lee.');

        // El spam lo firman las cuentas de usar y tirar, y tres de ellas ya estan bloqueadas.
        $spamAuthors = SnkKilkanaCommentModel::query()
            ->where('status', SnkKilkanaCommentStatusEnum::Spam)
            ->join('users', 'users.id', '=', 'kilkana_comments.user_id')
            ->pluck('users.lastname')->unique();
        $this->assertGreaterThan(0, $spamAuthors->count());
        $this->assertTrue($spamAuthors->every(fn ($name) => $name === null), 'Un vecino con nombre no firma publicidad.');
        $this->assertSame(3, CoreUserModel::query()->whereNotNull('suspended_at')->count());

        // Las lecturas medidas: de este mes, sobre piezas con muro, y despues de que existan.
        $reads = SnkKilkanaReaderReadModel::query()
            ->join('kilkana_posts', 'kilkana_posts.id', '=', 'kilkana_reader_reads.post_id')
            ->get(['kilkana_reader_reads.period', 'kilkana_reader_reads.read_at', 'kilkana_posts.publish_at', 'kilkana_posts.content_tier']);
        $this->assertGreaterThan(0, $reads->count());
        $this->assertSame($reads->count(), SnkKilkanaReaderReadModel::query()->inMonth()->count());
        // Columnas de la pieza leidas desde el modelo de lecturas: llegan sin sus casts.
        $this->assertTrue($reads->every(fn ($l) => $l->content_tier === SnkKilkanaContentTierEnum::Metered->value));
        $this->assertTrue($reads->every(fn ($l) => $l->read_at->greaterThan(Carbon::parse($l->publish_at)) && $l->read_at->lessThan(now())));
    }

    /** Lo que la tanda C reparte: revisiones, SEO, destacadas, suscriptores y fotos sin amontonar. */
    public function test_the_demo_archive_has_revisions_seo_featured_posts_and_subscribers(): void
    {
        (new SnkKilkanaDatabaseSeeder)->run();

        // Lo que la calle enseña es lo del snapshot, con o sin historial: sembrar el historial
        // escribe la version vieja y luego la buena, y salir entre las dos dejo una entradilla
        // de portada a la mitad con la suite en verde.
        // Por slug, saltando los dos que el snapshot repite en dias distintos: son piezas
        // legitimas y aqui no hay forma de saber cual de las dos es cual sin la fecha.
        $published = collect(json_decode((string) file_get_contents(base_path('packages/Kilkana/database/seeders/data/posts.json')), true))
            ->where('status', 'published');
        $duplicates = $published->countBy('slug')->filter(fn (int $n) => $n > 1)->keys();
        $snapshot = $published->whereNotIn('slug', $duplicates)->keyBy('slug');
        $skipped = 0;
        foreach (SnkKilkanaPostModel::query()->published()->get(['slug', 'title', 'excerpt']) as $post) {
            if (! isset($snapshot[$post->slug])) {
                $skipped++;

                continue;
            }
            $this->assertSame($snapshot[$post->slug]['title'], $post->title, $post->slug);
            $this->assertSame($snapshot[$post->slug]['excerpt'], $post->excerpt, $post->slug);
        }
        // Exactamente las que no se pueden comparar: los dos slugs repetidos (cuatro filas) y la
        // mas antigua, que cambia de slug en el bloque de las dos revisiones. Un slug corrupto
        // seria la sexta, y esta cuenta lo diria.
        $this->assertSame($duplicates->count() * 2 + 1, $skipped);

        $withHistory = DB::table('kilkana_post_revisions')->distinct()->count('post_id');
        $this->assertGreaterThanOrEqual(40, $withHistory, 'Un periodico con quinientas piezas y dos ediciones no existe.');
        $this->assertSame(0, DB::table('kilkana_post_revisions')
            ->join('kilkana_posts', 'kilkana_posts.id', '=', 'kilkana_post_revisions.post_id')
            ->whereColumn('kilkana_post_revisions.created_at', '<', 'kilkana_posts.publish_at')
            ->count(), 'Nadie edita antes de publicar.');

        $withSeo = SnkKilkanaPostModel::query()->whereNotNull('seo_description')->count();
        $this->assertGreaterThan(100, $withSeo);
        $this->assertLessThanOrEqual(158, SnkKilkanaPostModel::query()->whereNotNull('seo_description')->get()->max(fn ($p) => mb_strlen($p->seo_description)));
        $this->assertSame(6, SnkKilkanaPostModel::query()->where('featured', true)->count());

        // Exacto y no «más de trescientos»: un correo repetido se lo comería el `firstOrCreate`
        // en silencio, y perder veinte pasaría igual con una cota holgada.
        $this->assertSame(409, DB::table('kilkana_subscribers')->count());
        $this->assertGreaterThan(0, DB::table('kilkana_subscribers')->where('status', 'unsubscribed')->count());

        $perPhoto = SnkKilkanaPostModel::query()->whereNotNull('cover_media_id')
            ->selectRaw('cover_media_id, count(*) as total')->groupBy('cover_media_id')->pluck('total');
        $this->assertLessThanOrEqual(12, $perPhoto->max(), 'Ninguna foto en veinticuatro titulares.');
        $this->assertGreaterThanOrEqual(58, $perPhoto->count(), 'Casi todos los ficheros se estrenan.');
    }
}
