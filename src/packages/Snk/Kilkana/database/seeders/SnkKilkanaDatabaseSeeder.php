<?php

namespace Packages\Snk\Kilkana\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Http\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Packages\Core\Models\CoreUserModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaAuthorModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaCommentModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaLayoutModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaMediaCollectionModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaMediaModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaMediaTagModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaSettingModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaTagModel;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Permisos de los recursos del CMS + taxonomía base (7 mesas + 12 subsecciones) + los 60
 * artículos demo de kilkana (data/posts.json) resolviendo strings → relaciones
 * (autor, media de portada, tags). firstOrCreate: re-sembrar no duplica ni pisa.
 */
class SnkKilkanaDatabaseSeeder extends Seeder
{
    /**
     * Taxonomía del periódico. Dos niveles: las mesas de redacción y sus subsecciones.
     *
     * El orden de declaración manda: una hija tiene que ir DESPUÉS de su madre, porque
     * `seedCategories` resuelve `parent_id` con los ids ya creados. `position` se numera
     * por nivel, así que las hermanas se ordenan entre ellas y no contra el resto.
     *
     * `motor` nace sin artículos a propósito: una sección recién abierta es un estado
     * real, y es el que ejercita el bloque vacío del builder y la página de sección sin
     * contenido.
     */
    private const CATEGORIES = [
        'politics' => ['name' => 'Politics', 'description' => 'Government, parliament, elections and public policy.'],

        'economy' => ['name' => 'Economy', 'description' => 'Markets, business, employment and personal finance.'],
        'housing' => ['name' => 'Housing', 'parent' => 'economy', 'description' => 'Prices, rent, mortgages and public housing policy.'],
        'labour' => ['name' => 'Labour', 'parent' => 'economy', 'description' => 'Jobs, wages, collective bargaining and working conditions.'],

        'culture' => ['name' => 'Culture', 'description' => 'Books, film, music, arts and heritage.'],
        'cinema' => ['name' => 'Cinema', 'parent' => 'culture', 'description' => 'Premieres, festivals and the people behind the camera.'],
        'books' => ['name' => 'Books', 'parent' => 'culture', 'description' => 'Publishing, libraries, reading and the book trade.'],
        'heritage' => ['name' => 'Heritage', 'parent' => 'culture', 'description' => 'Archaeology, museums, restoration and cultural property.'],

        'society' => ['name' => 'Society', 'description' => 'Education, health, housing and everyday life.'],
        'education' => ['name' => 'Education', 'parent' => 'society', 'description' => 'Schools, universities, teaching and student life.'],

        'sports' => ['name' => 'Sports', 'description' => 'Results, matches and the stories behind the game.'],
        'football' => ['name' => 'Football', 'parent' => 'sports', 'description' => 'Leagues, cups and the national teams.'],
        'basketball' => ['name' => 'Basketball', 'parent' => 'sports', 'description' => 'Domestic league, European competition and the youth ranks.'],
        'cycling' => ['name' => 'Cycling', 'parent' => 'sports', 'description' => 'Grand tours, classics and the local teams.'],
        'motor' => ['name' => 'Motor', 'parent' => 'sports', 'description' => 'Rallies, circuits and the motorcycling calendar.'],

        'technology' => ['name' => 'Technology', 'description' => 'Science, innovation and the digital world.'],
        'ai' => ['name' => 'Artificial intelligence', 'parent' => 'technology', 'description' => 'Models, regulation and how AI reaches everyday work.'],
        'networks' => ['name' => 'Networks', 'parent' => 'technology', 'description' => 'Connectivity, infrastructure and the outages that expose it.'],

        'international' => ['name' => 'International', 'description' => 'World news and global affairs.'],
    ];

    public function run(): void
    {
        $this->seedPermissions();
        $this->seedCategories();
        $this->seedPosts();
        $this->seedComments();
        $this->seedLayouts();
        $this->seedSettings();
    }

    /**
     * Comentarios de demo. Sin ellos el contador del panel es siempre 0 y no hay nada que
     * moderar en /admin/comments, así que la funcionalidad no se puede ni ver ni probar.
     *
     * Determinista a propósito —reparto por posición, no al azar—: dos siembras dan la
     * misma base. Idempotente por (post, autor, texto), como el resto del seeder.
     */
    private function seedComments(): void
    {
        $readers = $this->seedReaders();
        if ($readers === []) {
            return;
        }

        // Voces distintas, no una plantilla repetida: la cola de moderación solo se puede
        // juzgar si los textos se parecen a los de verdad (agradecen, discrepan, corrigen).
        $bodies = [
            'Gran cobertura, gracias por el contexto histórico.',
            'Faltan las cifras de la fuente original, ¿las tenéis?',
            'Lo he compartido con mi equipo, muy útil.',
            'No estoy de acuerdo con la conclusión, pero se agradece el dato.',
            'Seguid así, es el tipo de periodismo que hace falta.',
            'Yo vivo en el barrio y la situación es bastante peor de lo que se cuenta aquí.',
            'El titular promete más de lo que luego desarrolla el texto.',
            '¿Vais a hacer seguimiento del tema dentro de unos meses?',
            'Enhorabuena por el trabajo de campo, se nota que hay calle detrás.',
            'Falta la versión de la otra parte, así queda cojo.',
            'La foto de portada no corresponde con lo que se describe en el tercer párrafo.',
            'Llevo años suscrito y este es de los mejores reportajes que os he leído.',
        ];
        // El spam existe en la vida real y esa pestaña estaba siempre a cero.
        $spam = [
            'GANA DINERO DESDE CASA >>> clic aquí, plazas limitadas!!!',
            'Compra seguidores baratos en www.ejemplo-no-real.test/promo',
        ];

        // Solo publicados: un borrador no ha tenido lectores que comenten.
        $posts = SnkKilkanaPostModel::query()->where('status', 'published')->orderBy('id')->get();
        foreach ($posts as $i => $post) {
            // 0, 1, 2 o 3 comentarios según la posición: la tabla enseña la columna con
            // variedad real en vez de todo a uno.
            for ($n = 0; $n < $i % 4; $n++) {
                $slot = $i + $n;
                $reader = $readers[$slot % count($readers)];
                $esSpam = $slot % 11 === 5;
                $comment = SnkKilkanaCommentModel::firstOrCreate([
                    'post_id' => $post->id,
                    'user_id' => $reader->id,
                    'body' => $esSpam ? $spam[$slot % count($spam)] : $bodies[$slot % count($bodies)],
                ], [
                    // Uno de cada tres queda pendiente: la moderación tiene cola que enseñar.
                    'status' => $esSpam ? 'spam' : ($slot % 3 === 0 ? 'pending' : 'approved'),
                    // Repartidos por las últimas cuatro semanas: sin esto entran todos a la
                    // vez y el filtro de periodo del panel no distingue nada.
                    'created_at' => now()->subHours(($slot * 7) % 672),
                    'updated_at' => now()->subHours(($slot * 7) % 672),
                ]);

                // Una respuesta cada pocos hilos: la conversación anidada también hay que
                // poder verla (el panel la indenta y la API la borra en cascada).
                if ($n === 0 && $i % 5 === 3) {
                    $otro = $readers[($slot + 1) % count($readers)];
                    SnkKilkanaCommentModel::firstOrCreate([
                        'post_id' => $post->id,
                        'user_id' => $otro->id,
                        'parent_id' => $comment->id,
                        'body' => 'Justo lo que iba a comentar. Añado que la última parte contradice lo que publicasteis en mayo.',
                    ], [
                        'status' => 'approved',
                        'created_at' => now()->subHours(max(0, (($slot * 7) % 672) - 3)),
                        'updated_at' => now()->subHours(max(0, (($slot * 7) % 672) - 3)),
                    ]);
                }
            }
        }
    }

    /**
     * Lectores registrados de demo con perfil completo (nombre, nombre legal, empresa,
     * teléfono y avatar). Sin esto la moderación es una columna de «Demo Reader» repetido:
     * no se ve si la tabla aguanta nombres largos, correos distintos ni avatares.
     *
     * @return list<CoreUserModel>
     */
    private function seedReaders(): array
    {
        $password = config('snk.kilkana.kilkana.demo_reader_password');
        if ((! is_string($password) || $password === '') && app()->environment(['local', 'testing'])) {
            $password = 'password';
        }
        if (! is_string($password) || $password === '') {
            return [];
        }

        // El hue pinta el avatar generado; van repartidos por la rueda para que la columna
        // no salga monocroma.
        $people = [
            ['name' => 'Marta Ruiz', 'email' => 'marta.ruiz@snk.test', 'full_name' => 'Marta Ruiz Alonso', 'company' => 'Ayuntamiento de Gijón', 'phone' => '+34 985 112 233', 'hue' => 20],
            ['name' => 'Aitor Etxeberria', 'email' => 'aitor.etxeberria@snk.test', 'full_name' => 'Aitor Etxeberria Lasa', 'company' => 'Cooperativa Elkar', 'phone' => '+34 943 445 566', 'hue' => 150],
            ['name' => 'Nuria Sanchís', 'email' => 'nuria.sanchis@snk.test', 'full_name' => 'Nuria Sanchís Ferrer', 'company' => 'Universitat de València', 'phone' => '+34 963 778 899', 'hue' => 275],
            ['name' => 'Diego Ferreiro', 'email' => 'diego.ferreiro@snk.test', 'full_name' => 'Diego Ferreiro Nogueira', 'company' => 'Portos de Galicia', 'phone' => '+34 981 334 455', 'hue' => 200],
            ['name' => 'Lucía Ibarra', 'email' => 'lucia.ibarra@snk.test', 'full_name' => 'Lucía Ibarra Montes', 'company' => 'Fundación Lectura Viva', 'phone' => '+34 917 223 344', 'hue' => 330],
            ['name' => 'Samuel Okonkwo', 'email' => 'samuel.okonkwo@snk.test', 'full_name' => 'Samuel Okonkwo Ebele', 'company' => 'Clínica San Rafael', 'phone' => '+34 954 667 788', 'hue' => 45],
            ['name' => 'Hélène Dubois', 'email' => 'helene.dubois@snk.test', 'full_name' => 'Hélène Dubois Renard', 'company' => 'Institut Français', 'phone' => '+33 1 44 55 66 77', 'hue' => 250],
            ['name' => 'Pau Comas', 'email' => 'pau.comas@snk.test', 'full_name' => 'Pau Comas Vidal', 'company' => 'Mercat de Sant Antoni', 'phone' => '+34 934 556 677', 'hue' => 95],
        ];

        $role = Role::findOrCreate('reader', 'sanctum');
        $readers = [];
        foreach ($people as $i => $person) {
            $user = CoreUserModel::firstOrCreate(
                ['email' => $person['email']],
                [
                    // Sin Hash::make: el cast 'hashed' del modelo ya lo hace.
                    'password' => $password,
                    'name' => $person['name'],
                    'full_name' => $person['full_name'],
                    'company' => $person['company'],
                    'phone' => $person['phone'],
                ]
            );
            // Fuera del create: así una siembra sobre una base que ya tenía al usuario
            // también le pone el avatar, en vez de dejarlo a medias.
            $user->forceFill(['avatar' => $this->seedAvatar($person['name'], $person['hue'], $i + 1)])->save();
            $user->assignRole($role);
            $readers[] = $user;
        }

        return $readers;
    }

    /**
     * Avatar como fichero propio del tenant: un SVG de monograma generado aquí mismo. Se
     * evita a propósito un servicio remoto de caras (pravatar y compañía): la demo tiene que
     * funcionar sin red y sin mandar nada a terceros.
     */
    private function seedAvatar(string $name, int $hue, int $n): string
    {
        $initials = collect(preg_split('/\s+/', trim($name)) ?: [])
            ->take(2)
            ->map(fn (string $word) => mb_strtoupper(mb_substr($word, 0, 1)))
            ->implode('');

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 96 96" width="96" height="96" role="img" aria-label="'.$initials.'">'
            .'<rect width="96" height="96" rx="48" fill="hsl('.$hue.' 62% 88%)"/>'
            .'<text x="48" y="62" text-anchor="middle" font-family="system-ui, sans-serif" font-size="38" font-weight="600" fill="hsl('.$hue.' 55% 28%)">'.$initials.'</text>'
            .'</svg>';

        $path = 'avatars/'.DB::connection()->getDatabaseName().'/reader-'.$n.'.svg';
        // put es idempotente por ruta: re-sembrar reescribe el mismo fichero.
        Storage::disk('public')->put($path, $svg);

        return '/storage/'.$path;
    }

    /** Composición de arranque (snapshot del builder): un fresh ya no deja la portada en blanco. */
    private function seedLayouts(): void
    {
        $layouts = json_decode((string) file_get_contents(__DIR__.'/data/layouts.json'), true);
        foreach ($layouts as $row) {
            SnkKilkanaLayoutModel::firstOrCreate(['layout' => $row['layout']], ['schema' => $row['schema']]);
        }
    }

    /** Identidad/tema de arranque; firstOrCreate: nunca pisa lo que el tenant ya editó. */
    private function seedSettings(): void
    {
        $settings = json_decode((string) file_get_contents(__DIR__.'/data/settings.json'), true);
        SnkKilkanaSettingModel::firstOrCreate(['id' => 1], ['settings' => $settings]);
    }

    private function seedPermissions(): void
    {
        $names = [];
        foreach (['posts', 'categories', 'authors', 'media', 'tags', 'comments', 'subscribers', 'layouts', 'settings'] as $resource) {
            foreach (['index', 'show', 'create', 'update', 'delete'] as $action) {
                $names[] = "$action $resource";
            }
        }
        foreach ($names as $name) {
            Permission::findOrCreate($name, 'sanctum');
        }
        $admin = Role::findOrCreate('admin', 'sanctum');
        $admin->givePermissionTo($names);

        // Lector registrado: solo puede comentar (la lectura pública no exige auth).
        $reader = Role::findOrCreate('reader', 'sanctum');
        $reader->givePermissionTo('create comments');

        $readerPassword = config('snk.kilkana.kilkana.demo_reader_password');
        if ((! is_string($readerPassword) || $readerPassword === '') && app()->environment(['local', 'testing'])) {
            $readerPassword = 'password';
        }
        if (! is_string($readerPassword) || $readerPassword === '') {
            return;
        }

        $demo = CoreUserModel::firstOrCreate(
            ['email' => 'reader@snk.test'],
            // Sin Hash::make: el cast 'hashed' del modelo ya lo hace (doble hash = login roto).
            ['name' => 'Demo Reader', 'password' => $readerPassword]
        );
        // La cuenta con la que se prueba el login público también se ve en la moderación:
        // sin perfil ni avatar era la única fila a medias de la tabla.
        $demo->forceFill([
            'full_name' => $demo->full_name ?: 'Demo Reader',
            'company' => $demo->company ?: 'Cuenta de demostración',
            'avatar' => $this->seedAvatar('Demo Reader', 210, 0),
        ])->save();
        $demo->assignRole($reader);
    }

    private function seedCategories(): void
    {
        $ids = [];
        $position = [];
        foreach (self::CATEGORIES as $slug => $attrs) {
            $parent = $attrs['parent'] ?? null;
            // Numeración por nivel: las raíces ordenan el nav, y las hijas se ordenan
            // entre hermanas. Un contador global dejaría huecos sin sentido en cada rama.
            $branch = $parent ?? '';
            $position[$branch] = ($position[$branch] ?? -1) + 1;

            $ids[$slug] = SnkKilkanaCategoryModel::firstOrCreate(['slug' => $slug], [
                'name' => $attrs['name'],
                'description' => $attrs['description'],
                'parent_id' => $parent ? ($ids[$parent] ?? null) : null,
                'position' => $position[$branch],
            ])->id;
        }
    }

    private function seedPosts(): void
    {
        $posts = json_decode((string) file_get_contents(__DIR__.'/data/posts.json'), true);
        foreach ($posts as $i => $post) {
            $category = SnkKilkanaCategoryModel::query()->where('slug', $post['cat'])->first();
            $author = SnkKilkanaAuthorModel::firstOrCreate(['name' => trim($post['author'])]);
            // El fixture de imagen es posicional: posts.json[N] ↔ data/media/unsplash-N.jpg.
            $media = ! empty($post['image'])
                ? $this->seedCoverMedia($post, $category, $i + 1)
                : null;

            $row = SnkKilkanaPostModel::firstOrCreate(['slug' => $post['slug']], [
                'category_id' => $category->id,
                'author_id' => $author->id,
                'cover_media_id' => $media?->id,
                'title' => $post['title'],
                'excerpt' => $post['excerpt'],
                'published_at' => $post['published_at'],
                'reads' => $post['reads'],
                'status' => $post['status'],
                'body_blocks' => $post['body_blocks'],
                'featured' => $post['featured'] ?? false,
                'scheduled_for' => $post['scheduled_for'],
            ]);

            $tagIds = collect($post['tags'] ?? [])
                ->map(fn (string $name) => SnkKilkanaTagModel::firstOrCreate(['name' => $name])->id);
            $row->tags()->syncWithoutDetaching($tagIds);
        }
    }

    /**
     * Portada como fichero propio del tenant, no como URL remota: copia el JPG del
     * fixture (data/media/unsplash-N.jpg) al disco del tenant con la misma convención
     * que la subida real (SnkKilkanaMediaController::upload) y crea la fila con los
     * metadatos completos que pide la biblioteca. La URL de Unsplash de posts.json
     * queda como `source_url` (la procedencia), no como origen de servicio.
     *
     * Dedup por `content_hash`, igual que la subida: re-sembrar no duplica.
     * Sin fixture, cae a la fila con URL remota para no romper la siembra.
     */
    private function seedCoverMedia(array $post, ?SnkKilkanaCategoryModel $category, int $n): SnkKilkanaMediaModel
    {
        $fixture = __DIR__.'/data/media/unsplash-'.$n.'.jpg';
        if (! is_file($fixture)) {
            return SnkKilkanaMediaModel::firstOrCreateByUrl($post['image']);
        }

        $name = 'unsplash-'.$n.'.jpg';
        $path = 'media/'.DB::connection()->getDatabaseName().'/'.$name;
        // putFileAs es idempotente por nombre: re-sembrar reescribe el mismo fichero.
        Storage::disk('public')->putFileAs(dirname($path), new File($fixture), $name);

        [$width, $height] = @getimagesize($fixture) ?: [null, null];
        $media = SnkKilkanaMediaModel::firstOrCreate(
            ['content_hash' => hash_file('sha256', $fixture)],
            [
                'url' => '/storage/'.$path,
                'original_name' => $name,
                'path' => $path,
                'disk' => 'public',
                'mime' => 'image/jpeg',
                'width' => $width,
                'height' => $height,
                'size_bytes' => filesize($fixture),
                'title' => $post['title'],
                'alt' => $post['title'],
                'caption' => $post['excerpt'],
                'description' => 'Editorial cover for: '.$post['title'],
                'credit' => 'Unsplash',
                'source_url' => $post['image'],
                'rights_status' => 'licensed',
                'license' => 'Unsplash License',
            ]
        );

        // ponytail: repite la regla de kilkana:enrich-media, pero aquí el post ya está
        // en la mano (sin re-consultar). Enrich sigue siendo el reparador de media
        // subida más tarde sin metadatos; no se orquesta un comando desde un seeder.
        $media->tags()->syncWithoutDetaching(
            collect($post['tags'] ?? [])->map(fn (string $tag) => SnkKilkanaMediaTagModel::firstOrCreate(['name' => $tag])->id)
        );
        if ($category) {
            $media->collections()->syncWithoutDetaching(
                [SnkKilkanaMediaCollectionModel::firstOrCreate(['name' => $category->name])->id]
            );
        }

        return $media;
    }
}
