<?php

namespace Packages\Kilkana\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Packages\Core\Models\CoreUserModel;
use Packages\Core\Support\CorePackageSelectorSupport;
use Packages\Kilkana\Enums\SnkKilkanaCategoryStatusEnum;
use Packages\Kilkana\Enums\SnkKilkanaCommentStatusEnum;
use Packages\Kilkana\Enums\SnkKilkanaConsentSourceEnum;
use Packages\Kilkana\Enums\SnkKilkanaPostStatusEnum;
use Packages\Kilkana\Enums\SnkKilkanaSubscriberStatusEnum;
use Packages\Kilkana\Models\SnkKilkanaAuthorModel;
use Packages\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Kilkana\Models\SnkKilkanaCommentModel;
use Packages\Kilkana\Models\SnkKilkanaMediaCollectionModel;
use Packages\Kilkana\Models\SnkKilkanaMediaModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Models\SnkKilkanaPostViewModel;
use Packages\Kilkana\Models\SnkKilkanaSettingModel;
use Packages\Kilkana\Models\SnkKilkanaSubscriberModel;
use Packages\Kilkana\Models\SnkKilkanaTagModel;
use Packages\Kilkana\Models\SnkKilkanaThemeModel;
use Packages\Kilkana\Services\SnkKilkanaMediaService;
use Packages\Kilkana\Services\SnkKilkanaThemeCatalogSyncService;
use Packages\Kilkana\Services\SnkKilkanaThemeWearService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Permisos del CMS y snapshot local de kilkana.app: taxonomía y 522 artículos
 * (data/posts.json) resolviendo strings a autor, sección, portada y tags.
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
     * `medio-ambiente` nace sin artículos a propósito: una sección recién abierta es un
     * estado real y ejercita la página de sección sin contenido.
     */
    private const CATEGORIES = [
        'local' => ['name' => 'Local'],
        'comarca' => ['name' => 'Comarca', 'parent' => 'local'],
        'municipios' => ['name' => 'Municipios', 'parent' => 'local'],
        'barrios' => ['name' => 'Barrios', 'parent' => 'local'],
        'politica' => ['name' => 'Política'],
        'economia' => ['name' => 'Economía'],
        'agricultura' => ['name' => 'Agricultura', 'parent' => 'economia'],
        'empleo' => ['name' => 'Empleo', 'parent' => 'economia'],
        'deportes' => ['name' => 'Deportes'],
        'futbol' => ['name' => 'Fútbol', 'parent' => 'deportes'],
        'polideportivo' => ['name' => 'Polideportivo', 'parent' => 'deportes'],
        'cultura' => ['name' => 'Cultura'],
        'patrimonio' => ['name' => 'Patrimonio', 'parent' => 'cultura'],
        // ponytail: Kilkana tiene `hidden`; el backend solo draft/live. Añadir el tercer
        // estado al migrar la fixture de categorías. Por ahora debe ser live porque tiene posts.
        'agenda' => ['name' => 'Agenda', 'parent' => 'cultura'],
        'sucesos' => ['name' => 'Sucesos'],
        'opinion' => ['name' => 'Opinión'],
        'medio-ambiente' => ['name' => 'Medio ambiente', 'status' => SnkKilkanaCategoryStatusEnum::Draft],
    ];

    public function run(): void
    {
        $this->seedPermissions();
        // Antes que los ajustes: la fila de settings apunta a una variante con RESTRICT, así
        // que la variante tiene que existir para que haya a qué apuntar.
        $this->seedThemes();
        $this->seedSettings();

        if (! config('kilkana.kilkana.seed_demo', false)) {
            return;
        }

        $this->seedCategories();
        $this->seedPosts();
        $this->seedViews();
        $this->seedAuthorProfiles();
        $this->seedStaff();
        $this->seedComments();
        $this->seedSubscribers();
    }

    /**
     * La lista del boletín, con los tres estados representados.
     *
     * No estaba sembrada: la API de suscriptores devolvía una demo vacía mientras el resto tenía
     * contenido, así que no se probaban el listado ni el filtro por estado.
     *
     * Los tres orígenes también, y esa es la mitad que importa: la lista IMPORTADA es la que
     * trae el riesgo legal, y si todas las filas parecen iguales no se puede ni acreditar ni
     * purgar por separado. Aquí se ve un periódico que ya venía con su lista del papel.
     */
    private function seedSubscribers(): void
    {
        $ayer = now()->subMonths(3);

        $lista = [
            'marta.ruiz@snk.test' => [SnkKilkanaSubscriberStatusEnum::Confirmed, SnkKilkanaConsentSourceEnum::PublicForm],
            'aitor.etxeberria@snk.test' => [SnkKilkanaSubscriberStatusEnum::Confirmed, SnkKilkanaConsentSourceEnum::PublicForm],
            'nuria.sanchis@snk.test' => [SnkKilkanaSubscriberStatusEnum::Confirmed, SnkKilkanaConsentSourceEnum::PublicForm],
            'diego.ferreiro@snk.test' => [SnkKilkanaSubscriberStatusEnum::Pending, SnkKilkanaConsentSourceEnum::PublicForm],
            'lucia.ibarra@snk.test' => [SnkKilkanaSubscriberStatusEnum::Pending, SnkKilkanaConsentSourceEnum::PublicForm],
            'pau.comas@snk.test' => [SnkKilkanaSubscriberStatusEnum::Pending, SnkKilkanaConsentSourceEnum::PublicForm],
            // Los dos de la lista que el periódico traía en papel: el consentimiento se dio
            // antes y fuera, y por eso su fecha es vieja.
            'boletin@bibliotecagijon.test' => [SnkKilkanaSubscriberStatusEnum::Pending, SnkKilkanaConsentSourceEnum::Import],
            'prensa@puertogijon.test' => [SnkKilkanaSubscriberStatusEnum::Confirmed, SnkKilkanaConsentSourceEnum::Import],
            // Se dio de baja: la fila SE QUEDA, que es la prueba de que pidió no recibir más.
            'helene.dubois@snk.test' => [SnkKilkanaSubscriberStatusEnum::Unsubscribed, SnkKilkanaConsentSourceEnum::PublicForm],
        ];

        foreach ($lista as $email => [$estado, $origen]) {
            SnkKilkanaSubscriberModel::firstOrCreate(['email' => $email], [
                'status' => $estado,
                'consent_source' => $origen,
                'consent_at' => $origen === SnkKilkanaConsentSourceEnum::Import ? $ayer : now(),
            ]);
        }
    }

    /**
     * La redacción, en un solo sitio: lo que va a `users` (cuenta) y lo que va a
     * `kilkana_authors` (firma) salen del mismo registro, porque son la misma persona.
     *
     * `job_title` es el cargo PUBLICABLE y va a la firma, no a la cuenta: aquí llegó a estar
     * la mesa («Mesa de Deportes») metida en `users.company`, que significa «Organización» y no
     * sale al público — el dato editorial vivía en la columna del CRM.
     * Ahora `company` dice lo que promete: el periódico para la plantilla, la organización
     * propia de quien colabora desde fuera.
     *
     * Sin foto: la del periodista se sube a la biblioteca de medios y cuelga de su FIRMA
     * (`avatar_media_id`); sin ella, la UI dibuja el monograma.
     */
    private const REDACCION = [
        'Marta Beneyto' => [
            'email' => 'marta@eldiario.example',
            'job_title' => 'Editora jefe',
            'bio' => 'Dirige El Diario desde 2021. Antes cubrió tribunales en la prensa provincial durante doce años.',
            'website' => 'https://martabeneyto.es',
            'socials' => ['x' => 'https://x.com/martabeneyto', 'linkedin' => 'https://www.linkedin.com/in/martabeneyto'],
        ],
        'Ramón Clavel' => [
            'email' => 'ramon@eldiario.example',
            'job_title' => 'Gobierno local',
            'bio' => 'Cubre el pleno y el urbanismo de la comarca. Premio de periodismo local 2019.',
            'website' => 'https://ramonclavel.info',
            'socials' => ['x' => 'https://x.com/rclavel', 'bluesky' => 'https://bsky.app/profile/rclavel.bsky.social'],
        ],
        'Iván Sagredo' => [
            'email' => 'ivan@eldiario.example',
            'job_title' => 'Economía y agricultura',
            'bio' => 'Escribe sobre agricultura, agua y empleo en la comarca.',
            'website' => null,
            'socials' => [],
        ],
        'Nuria Espí' => [
            'email' => 'nuria@eldiario.example',
            'job_title' => 'Sanidad y sociedad',
            'bio' => 'Reportajes de sanidad y servicios sociales. Colabora en el podcast semanal.',
            'website' => 'https://nuriaespi.com',
            'socials' => ['x' => 'https://x.com/nuriaespi', 'linkedin' => 'https://www.linkedin.com/in/nuriaespi'],
        ],
        'Lucía Ferrándiz' => [
            'email' => 'lucia@eldiario.example',
            'job_title' => 'Cultura y patrimonio',
            'bio' => 'Patrimonio, arqueología y oficios. Licenciada en Historia del Arte.',
            'website' => null,
            'socials' => ['linkedin' => 'https://www.linkedin.com/in/luciaferrandiz'],
        ],
        'Óscar Tomás' => [
            'email' => 'oscar@eldiario.example',
            'job_title' => 'Deportes y fotografía',
            'bio' => null,
            'website' => null,
            'socials' => [],
        ],
    ];

    /**
     * El periódico de la demo. `users.company` es la ORGANIZACIÓN de cada cuenta: para la
     * plantilla, esta; para quien colabora desde fuera, la suya. Antes aquí iba la mesa.
     */
    private const PERIODICO = 'El Diario';

    /**
     * Quien firma sin estar en plantilla.
     *
     * Las firmas del snapshot que no salen en `REDACCION` caían todas en el mismo molde:
     * `redactor`, organización «El Diario» y correo `@eldiario.example`. Eso convierte a una
     * agencia de noticias en una redactora del periódico con permiso para publicar, que es
     * exactamente lo que la demo no tiene que enseñar — y borra la diferencia entre la
     * plantilla, quien colabora desde fuera y lo que llega por teletipo.
     *
     * `role` a null es una CUENTA DE SERVICIO: existe para satisfacer la FK de la firma y no
     * entra en ningún sitio. Es el mismo mecanismo que ya usa «Redacción», y por eso las tres
     * salen de aquí.
     *
     * @var array<string, array{email: string, name: string, alias?: string, company: ?string, job_title: ?string, bio: ?string, role: ?string, full_name?: string}>
     */
    private const FIRMAS_EXTERNAS = [
        // Firma colectiva de la casa. La cuenta se llama como lo que es —para quien mira el
        // listado de Usuarios— y el ALIAS es lo que sale publicado: llamarla «Redacción» a
        // secas habría dado el mismo nombre publicado sin alias, y la columna no demostraría nada.
        'Redacción' => [
            'email' => 'redaccion@eldiario.example',
            'name' => 'Redacción (cuenta de servicio)',
            'full_name' => 'Cuenta de servicio de la redacción',
            'alias' => 'Redacción',
            'company' => self::PERIODICO,
            'job_title' => self::PERIODICO,
            'bio' => 'Piezas firmadas por el equipo de redacción.',
            'role' => null,
        ],
        // El teletipo. Su organización es la agencia, no el periódico, y sin rol: nadie de
        // fuera escribe en este CMS, sus piezas las vuelca la redacción.
        'Agencia EFE' => [
            'email' => 'efe@eldiario.example',
            'name' => 'Agencia EFE (cuenta de servicio)',
            'full_name' => 'Cuenta de servicio de agencia',
            'alias' => 'Agencia EFE',
            'company' => 'Agencia EFE',
            'job_title' => 'Agencia de noticias',
            'bio' => 'Teletipos de agencia editados por la redacción.',
            'role' => null,
        ],
        // Columnista: escribe de verdad y entra al CMS, pero no es plantilla. Sin organización
        // —la suya es ninguna— y con `colaborador`, que no puede publicar lo que firma.
        'Josep Miralles' => [
            'email' => 'josep.miralles@colaboradores.eldiario.example',
            'name' => 'Josep Miralles',
            'company' => null,
            'job_title' => 'Columnista',
            'bio' => 'Escribe la columna de los jueves. Fue jefe de Local entre 1998 y 2014.',
            'role' => 'colaborador',
        ],
    ];

    /**
     * Firma de demo: la CUENTA del periodista más su fila editorial.
     *
     * La firma es un usuario del CMS, así que la identidad es el correo. Las seis firmas de
     * plantilla conservan el correo del fixture; las firmas externas reciben uno local y
     * determinista para poder satisfacer la FK sin inventar una identidad pública.
     *
     * Con rol `redactor`: escribe y publica lo suyo. Es el rol de plantilla, y con él la
     * demo enseña de verdad el acotado por `created_by` — cada periodista ve sus piezas.
     */
    private function authorFor(string $name): SnkKilkanaAuthorModel
    {
        if (isset(self::FIRMAS_EXTERNAS[$name])) {
            return $this->externalByline($name);
        }

        $ficha = self::REDACCION[$name] ?? [];

        $user = CoreUserModel::firstOrCreate(
            ['email' => $ficha['email'] ?? Str::slug($name, '.').'@eldiario.example'],
            // Sin Hash::make: `CoreUserModel::setPasswordAttribute` ya la hashea al asignarla.
            ['password' => $this->demoPassword(), 'name' => $name]
        );
        // Fuera del create y solo sobre lo vacío: así una siembra sobre una base que ya
        // tenía la cuenta también la completa.
        $user->forceFill(array_filter([
            'full_name' => $user->full_name ?: ($ficha['full_name'] ?? $name),
            'company' => $user->company ?: self::PERIODICO,
            'phone' => $user->phone ?: ($ficha['phone'] ?? null),
            // Cuenta operativa de demo: verificada, o el listado devuelve a media
            // redacción «sin verificar» sin que eso signifique nada.
            'email_verified_at' => $user->email_verified_at ?: now(),
        ], fn ($v) => $v !== null))->save();
        $user->assignRole('redactor');

        // Sin alias ni cargo aquí: la firma nace publicando el nombre de la cuenta y su URL
        // se deriva de él. Lo editorial lo pone después `seedAuthorProfiles`.
        return SnkKilkanaAuthorModel::firstOrCreate(['user_id' => $user->id]);
    }

    /**
     * Ficha de cada firma: lo editorial (bio, web, redes) sobre la fila de `kilkana_authors`.
     * Los datos de la CUENTA los pone `authorFor` desde el mismo mapa.
     *
     * Una página pública de autor con solo el nombre es exactamente la ficha delgada que
     * Google penaliza. Solo rellena lo vacío: reseedear no pisa una biografía escrita a mano.
     */
    private function seedAuthorProfiles(): void
    {
        foreach (self::REDACCION as $nombre => $ficha) {
            $autor = $this->authorFor($nombre);
            $autor->fill(array_filter([
                'alias' => $autor->alias ?: ($ficha['alias'] ?? null),
                'job_title' => $autor->job_title ?: ($ficha['job_title'] ?? null),
                'bio' => $autor->bio ?: ($ficha['bio'] ?? null),
                'website' => $autor->website ?: ($ficha['website'] ?? null),
                'socials' => $autor->socials ?: ($ficha['socials'] ?: null),
            ], fn ($v) => $v !== null))->save();
        }
    }

    /**
     * El resto de la plantilla: quien no firma los artículos del fixture pero existe en
     * cualquier redacción. Sin ellos, la demo solo cubre dos de los siete roles y no prueba
     * sus permisos distintos.
     *
     * La colaboradora lleva firma (puede escribir) y la de comunidad NO (no puede): es la
     * misma regla que aplica la API al dar de alta un usuario.
     */
    private function seedStaff(): void
    {
        $staff = [
            [
                'name' => 'Elena Vara', 'email' => 'elena.vara@estudiovara.example',
                // Su organización es la SUYA, no el periódico: colabora desde fuera, y esa
                // diferencia es lo que la columna «Organización» sirve para ver.
                'full_name' => 'Elena Vara Ruiz', 'company' => 'Estudio Vara Urbanismo',
                'phone' => '+34 985 100 209', 'role' => 'colaborador',
                'job_title' => 'Colaboradora de Urbanismo',
                'bio' => 'Escribe de urbanismo y memoria de barrio desde fuera de la redacción. Entrega y espera al cierre.',
            ],
            [
                'name' => 'Rubén Nieto', 'email' => 'ruben.nieto@eldiario.example',
                'full_name' => 'Rubén Nieto Salas', 'company' => self::PERIODICO,
                'phone' => '+34 985 100 210', 'role' => 'moderador',
                'job_title' => null,
                'bio' => null,
            ],
        ];

        foreach ($staff as $person) {
            $user = CoreUserModel::firstOrCreate(
                ['email' => $person['email']],
                ['password' => $this->demoPassword(), 'name' => $person['name']]
            );
            $user->forceFill([
                'full_name' => $user->full_name ?: $person['full_name'],
                'company' => $user->company ?: $person['company'],
                'phone' => $user->phone ?: $person['phone'],
                'email_verified_at' => $user->email_verified_at ?: now(),
            ])->save();
            $user->syncRoles([$person['role']]);

            // Firma solo si el rol escribe — la de comunidad no la tiene, y ese contraste
            // es justo lo que la demo tiene que enseñar.
            if ($person['bio'] !== null) {
                SnkKilkanaAuthorModel::firstOrCreate(['user_id' => $user->id])
                    ->fill(['bio' => $person['bio'], 'job_title' => $person['job_title']])
                    ->save();
            }
        }
    }

    /**
     * Una firma que no es plantilla: la colectiva, la de agencia y la del columnista.
     *
     * Es el caso que el modelo parecía no admitir. Una firma ES una cuenta (`user_id` es
     * obligatorio y único), y «Redacción» no es una persona — pero tampoco hace falta cambiar el
     * esquema: se le da una CUENTA DE SERVICIO, sin rol y por tanto sin acceso operativo, y la
     * firma cuelga de ella con `alias`. Un breve de agencia deja de necesitar que alguien se lo
     * atribuya.
     *
     * Sin rol no es lo mismo que sin cuenta: la fila existe, sale en el listado de Usuarios y se
     * ve que no puede entrar. Es lo que hay que poder enseñar.
     */
    private function externalByline(string $nombre): SnkKilkanaAuthorModel
    {
        $ficha = self::FIRMAS_EXTERNAS[$nombre];

        $cuenta = CoreUserModel::firstOrCreate(
            ['email' => $ficha['email']],
            ['password' => $this->demoPassword(), 'name' => $ficha['name']]
        );
        $cuenta->forceFill(array_filter([
            'full_name' => $cuenta->full_name ?: ($ficha['full_name'] ?? $ficha['name']),
            'company' => $cuenta->company ?: $ficha['company'],
            // Verificada solo si puede entrar: una cuenta de servicio sin rol no verifica
            // nada porque nadie va a usarla para acceder.
            'email_verified_at' => $ficha['role'] === null
                ? null
                : ($cuenta->email_verified_at ?: now()),
        ], fn ($v) => $v !== null))->save();

        if ($ficha['role'] !== null) {
            $cuenta->assignRole($ficha['role']);
        }

        return SnkKilkanaAuthorModel::firstOrCreate(
            ['user_id' => $cuenta->id],
            array_filter([
                'alias' => $ficha['alias'] ?? null,
                'job_title' => $ficha['job_title'],
                'bio' => $ficha['bio'],
            ], fn ($v) => $v !== null)
        );
    }

    /** Contraseña de las cuentas de demo; sin config utilizable, una aleatoria (no login). */
    private function demoPassword(): string
    {
        $password = config('kilkana.kilkana.demo_reader_password');
        if ((! is_string($password) || $password === '') && app()->environment(['local', 'testing'])) {
            $password = 'password';
        }

        return is_string($password) && $password !== '' ? $password : Str::random(32);
    }

    /**
     * Comentarios de demo. Sin ellos el contador siempre es 0 y no hay nada que moderar, así que
     * la funcionalidad no se puede probar.
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
        $posts = SnkKilkanaPostModel::query()->published()->orderBy('id')->get();
        foreach ($posts as $i => $post) {
            // 0, 1, 2 o 3 comentarios según la posición: la tabla enseña la columna con
            // variedad real en vez de todo a uno.
            for ($n = 0; $n < $i % 4; $n++) {
                $slot = $i + $n;
                $reader = $readers[$slot % count($readers)];
                $esSpam = $slot % 11 === 5;
                // Todo comentario viene de una cuenta: comentar exige registrarse. El spam
                // también, y eso es justo el filtro — colar publicidad cuesta darse de alta,
                // y quien lo haga se queda sin cuenta a la primera.
                $comment = SnkKilkanaCommentModel::firstOrCreate([
                    'user_id' => $reader->id,
                    'post_id' => $post->id,
                    'body' => $esSpam ? $spam[$slot % count($spam)] : $bodies[$slot % count($bodies)],
                ], [
                    // Uno de cada tres queda pendiente: la moderación tiene cola que enseñar.
                    'status' => $esSpam
                        ? SnkKilkanaCommentStatusEnum::Spam
                        : ($slot % 3 === 0 ? SnkKilkanaCommentStatusEnum::Pending : SnkKilkanaCommentStatusEnum::Approved),
                    // Repartidos por las últimas cuatro semanas: sin esto entran todos a la
                    // vez y el filtro de periodo no distingue nada.
                    'created_at' => now()->subHours(($slot * 7) % 672),
                    'updated_at' => now()->subHours(($slot * 7) % 672),
                ]);

                // Una respuesta cada pocos hilos: la conversación anidada también hay que
                // poder probarla; la API la borra en cascada.
                if ($n === 0 && $i % 5 === 3) {
                    // Otra persona, para que el hilo enseñe una conversación y no un monólogo.
                    $otro = $readers[($slot + 1) % count($readers)];
                    SnkKilkanaCommentModel::firstOrCreate([
                        'post_id' => $post->id,
                        'user_id' => $otro->id,
                        'parent_id' => $comment->id,
                        'body' => 'Justo lo que iba a comentar. Añado que la última parte contradice lo que publicasteis en mayo.',
                    ], [
                        'status' => SnkKilkanaCommentStatusEnum::Approved,
                        'created_at' => now()->subHours(max(0, (($slot * 7) % 672) - 3)),
                        'updated_at' => now()->subHours(max(0, (($slot * 7) % 672) - 3)),
                    ]);
                }
            }
        }
    }

    /**
     * Lectores registrados de demo con perfil completo (nombre, nombre legal, empresa,
     * teléfono). Sin esto la moderación es una columna de «Demo Reader» repetido: no se ve
     * si la tabla aguanta nombres largos ni correos distintos.
     *
     * @return list<CoreUserModel>
     */
    private function seedReaders(): array
    {
        $password = config('kilkana.kilkana.demo_reader_password');
        if ((! is_string($password) || $password === '') && app()->environment(['local', 'testing'])) {
            $password = 'password';
        }
        if (! is_string($password) || $password === '') {
            return [];
        }

        $people = [
            ['name' => 'Marta Ruiz', 'email' => 'marta.ruiz@snk.test', 'full_name' => 'Marta Ruiz Alonso', 'company' => 'Ayuntamiento de Gijón', 'phone' => '+34 985 112 233'],
            ['name' => 'Aitor Etxeberria', 'email' => 'aitor.etxeberria@snk.test', 'full_name' => 'Aitor Etxeberria Lasa', 'company' => 'Cooperativa Elkar', 'phone' => '+34 943 445 566'],
            ['name' => 'Nuria Sanchís', 'email' => 'nuria.sanchis@snk.test', 'full_name' => 'Nuria Sanchís Ferrer', 'company' => 'Universitat de València', 'phone' => '+34 963 778 899'],
            ['name' => 'Diego Ferreiro', 'email' => 'diego.ferreiro@snk.test', 'full_name' => 'Diego Ferreiro Nogueira', 'company' => 'Portos de Galicia', 'phone' => '+34 981 334 455'],
            ['name' => 'Lucía Ibarra', 'email' => 'lucia.ibarra@snk.test', 'full_name' => 'Lucía Ibarra Montes', 'company' => 'Fundación Lectura Viva', 'phone' => '+34 917 223 344'],
            ['name' => 'Samuel Okonkwo', 'email' => 'samuel.okonkwo@snk.test', 'full_name' => 'Samuel Okonkwo Ebele', 'company' => 'Clínica San Rafael', 'phone' => '+34 954 667 788'],
            ['name' => 'Hélène Dubois', 'email' => 'helene.dubois@snk.test', 'full_name' => 'Hélène Dubois Renard', 'company' => 'Institut Français', 'phone' => '+33 1 44 55 66 77'],
            ['name' => 'Pau Comas', 'email' => 'pau.comas@snk.test', 'full_name' => 'Pau Comas Vidal', 'company' => 'Mercat de Sant Antoni', 'phone' => '+34 934 556 677'],
        ];

        $role = Role::findOrCreate('reader', 'sanctum');
        $readers = [];
        foreach ($people as $i => $person) {
            $user = CoreUserModel::firstOrCreate(
                ['email' => $person['email']],
                [
                    // Sin Hash::make: `CoreUserModel::setPasswordAttribute` ya la hashea al asignarla.
                    'password' => $password,
                    'name' => $person['name'],
                    'full_name' => $person['full_name'],
                    'company' => $person['company'],
                    'phone' => $person['phone'],
                ]
            );
            // Fuera del create: así una siembra sobre una base que ya tenía al usuario
            // también le completa la ficha, en vez de dejarla a medias.
            $user->forceFill([
                'email_verified_at' => $user->email_verified_at ?: now(),
            ])->save();
            $user->assignRole($role);
            $readers[] = $user;
        }

        return $readers;
    }

    /** El catálogo que envía el producto, publicado desde los ficheros maestros. */
    private function seedThemes(): void
    {
        // El catálogo no se siembra aquí: lo publica el mismo servicio que usa
        // `kilkana:sync-themes`, desde los ficheros maestros. Duplicar la siembra habría dejado
        // dos sitios que escriben las mismas filas y que se separan en cuanto uno se toque.
        $resumen = app(SnkKilkanaThemeCatalogSyncService::class)->sync();

        foreach ($resumen['errors'] as $error) {
            $this->command?->getOutput()->writeln("<error>themes: {$error}</error>");
        }
    }

    /** Identidad/tema de arranque; firstOrCreate: nunca pisa lo que el tenant ya editó. */
    private function seedSettings(): void
    {
        // El snapshot solo se usa con datos demo; sin ellos basta con vestir el primer tema
        // que envía el producto.
        $settings = config('kilkana.kilkana.seed_demo', false)
            ? json_decode((string) file_get_contents(__DIR__.'/data/settings.json'), true)
            : [];
        $tenant = $settings['tenant'] ?? [];

        // El fichero guarda el SLUG del tema, nunca su id: un id de una base no significa nada
        // en otra, y este snapshot tiene que poder sembrarse en cualquiera.
        $catalogo = SnkKilkanaThemeModel::query()->published();
        $tema = (isset($settings['theme'])
            ? (clone $catalogo)->where('slug', $settings['theme'])->first()
            : null)
            ?? (clone $catalogo)->orderByDesc('featured')->orderBy('position')->first();

        $fila = SnkKilkanaSettingModel::firstOrCreate(['id' => 1], [
            'tenant_name' => $tenant['name'] ?? null,
            'tenant_tagline' => $tenant['tagline'] ?? null,
            'tenant_city' => $tenant['city'] ?? null,
            'tenant_url' => $this->tenantUrl(),
            'onboarded' => (bool) ($settings['onboarded'] ?? false),
        ]);

        // Vestir COPIA el spec, no lo apunta: apuntar a la fila del catálogo rompería el
        // congelado, porque la siguiente sincronización le cambiaría el tema al periódico. Solo
        // si va desnudo — `firstOrCreate` no pisa lo que el inquilino ya eligió.
        if ($tema && $fila->theme_spec === null) {
            app(SnkKilkanaThemeWearService::class)->wear($tema);
        }
    }

    /**
     * La URL pública del periódico, deducida de la base sobre la que se está sembrando.
     *
     * **No puede venir del snapshot.** Un host escrito en `settings.json` es el de UN inquilino,
     * y el mismo fichero siembra a los cuatro: el segundo se despertaría con la dirección del
     * primero, que es lo que firma los enlaces del boletín. Aquí llegó a haber
     * `https://demo.kilkana.test` dentro de datos de producto.
     *
     * La base se llama `{cliente}_{paquete}` y el subdominio del cliente es su slug con guiones,
     * así que el host sale del mismo par (cliente, dominio base) con el que
     * `subdomainFromHost` resuelve al inquilino en cada petición: una sola regla, no dos que se
     * separen. Si no se puede deducir, null — y `subscriptionLanding()` ya sabe qué hacer con
     * un periódico sin URL puesta.
     */
    private function tenantUrl(): ?string
    {
        $base = (string) config('core.tenant_base_domain', '');
        $bd = (string) config('database.connections.tenant.database', '');
        $sufijo = '_kilkana';

        if ($base === '' || ! str_ends_with($bd, $sufijo)) {
            return null;
        }

        $cliente = str_replace('_', '-', substr($bd, 0, -strlen($sufijo)));

        return CorePackageSelectorSupport::isValidClientSubdomain($cliente)
            ? "https://{$cliente}.{$base}"
            : null;
    }

    /**
     * Permisos y roles de una redacción.
     *
     * El CRUD por recurso no describe un periódico: funde en un solo `update posts` tres
     * poderes que allí están repartidos —escribir, publicar y decidir la portada—. Por eso
     * hay dos permisos que no salen del CRUD:
     *
     * - `publish posts`: pasar una pieza a publicada o programada. Quien la firma no decide
     *   cuándo sale; sin este permiso solo puede dejarla en borrador o «en revisión».
     * - `edit others posts`: tocar lo que no has escrito. Sin él, listado, editor y borrado
     *   se acotan a `created_by` — el muro entre plantilla y colaboración externa.
     *
     * La portada NO necesita permiso nuevo: componerla ya es `update layouts`, y separarla
     * de `update posts` es justo lo que distingue a un editor de mesa de la dirección.
     */
    private function seedPermissions(): void
    {
        $names = [];
        foreach (['posts', 'categories', 'authors', 'media', 'tags', 'comments', 'subscribers', 'layouts', 'settings', 'themes'] as $resource) {
            foreach (['index', 'show', 'create', 'update', 'delete'] as $action) {
                $names[] = "$action $resource";
            }
        }
        $names[] = 'publish posts';
        $names[] = 'edit others posts';
        foreach ($names as $name) {
            Permission::findOrCreate($name, 'sanctum');
        }
        // Aditivo, no sync: los permisos de `users` se los da el seeder de Core y un sync
        // con esta lista se los quitaría.
        $admin = Role::findOrCreate('admin', 'sanctum');
        $admin->givePermissionTo($names);

        $crud = fn (string ...$resources) => collect($resources)
            ->crossJoin(['index', 'show', 'create', 'update', 'delete'])
            ->map(fn ($pair) => "$pair[1] $pair[0]")
            ->all();
        $read = fn (string ...$resources) => collect($resources)
            ->crossJoin(['index', 'show'])
            ->map(fn ($pair) => "$pair[1] $pair[0]")
            ->all();

        // Cada rol es un reparto de poder, no una lista de casillas. `syncPermissions`
        // (no `give`) para que reseedear REPARE un rol tocado a mano.
        $roles = [
            // Dirección editorial: manda en el periódico entero, incluida la portada y la
            // identidad. No administra la instalación (usuarios) — eso es otro oficio.
            'director' => [
                ...$crud('posts', 'categories', 'authors', 'media', 'tags', 'comments', 'subscribers', 'layouts', 'settings', 'themes'),
                'publish posts', 'edit others posts',
            ],
            // Mesa de edición: publica y corrige lo de cualquiera, modera y gestiona el
            // material. NO compone la portada ni cambia la identidad del periódico.
            'editor' => [
                ...$crud('posts', 'media', 'tags', 'comments'),
                ...$read('categories', 'authors'),
                'publish posts', 'edit others posts',
            ],
            // Plantilla: escribe y publica LO SUYO. Es la diferencia real con un externo.
            'redactor' => [
                ...$crud('posts'),
                ...$crud('media'),
                'index tags', 'create tags',
                ...$read('categories', 'authors', 'comments'),
                'publish posts',
            ],
            // Colaborador externo: escribe lo suyo y lo deja «en revisión». No publica y no
            // borra: una pieza entregada no la retira quien la escribió.
            'colaborador' => [
                'index posts', 'show posts', 'create posts', 'update posts',
                'index media', 'show media', 'create media',
                'index tags',
                ...$read('categories', 'authors'),
            ],
            // Comunidad: comentarios y boletín. Ni un permiso sobre artículos, que es
            // justo por lo que este rol existe.
            'moderador' => [
                ...$crud('comments', 'subscribers'),
                ...$read('posts'),
            ],
        ];
        foreach ($roles as $name => $permissions) {
            Role::findOrCreate($name, 'sanctum')->syncPermissions($permissions);
        }

        // Lector registrado: solo puede comentar (la lectura pública no exige auth).
        $reader = Role::findOrCreate('reader', 'sanctum');
        $reader->givePermissionTo('create comments');

        if (config('kilkana.kilkana.seed_demo', false)) {
            $readerPassword = config('kilkana.kilkana.demo_reader_password');
            if ((! is_string($readerPassword) || $readerPassword === '') && app()->environment(['local', 'testing'])) {
                $readerPassword = 'password';
            }
            if (is_string($readerPassword) && $readerPassword !== '') {
                $demo = CoreUserModel::firstOrCreate(
                    ['email' => 'reader@snk.test'],
                    ['name' => 'Demo Reader', 'password' => $readerPassword]
                );
                $demo->forceFill([
                    'full_name' => $demo->full_name ?: 'Demo Reader',
                    'company' => $demo->company ?: 'Cuenta de demostración',
                    'phone' => $demo->phone ?: '+34 900 000 000',
                    'email_verified_at' => $demo->email_verified_at ?: now(),
                ])->save();
                $demo->assignRole($reader);
            }
        }

        // El administrador lo crea el provisioning, que no sabe nada de periódicos: nace
        // con nombre y poco más. Se completa aquí —solo lo vacío— porque es la primera fila
        // del listado de usuarios y la cara de la sesión en la barra lateral.
        $adminEmail = config('core.auth.admin_email', 'admin@snake.com');
        $admin = CoreUserModel::query()->where('email', $adminEmail)->first();

        // Con datos demo se crea si falta, y solo entonces. El administrador de un periódico de
        // verdad lo crea el provisioning con las credenciales que se eligen en el alta; aquí lo
        // que se cubre es el agujero de recrear la base a mano: `--fresh` vacía también `users`,
        // así que el juego demo volvía entero pero sin ninguna cuenta con la que entrar.
        if ($admin === null && config('kilkana.kilkana.seed_demo', false) && app()->environment(['local', 'testing'])) {
            $admin = CoreUserModel::create([
                'name' => 'Admin',
                'email' => $adminEmail,
                // En claro: `CoreUserModel::setPasswordAttribute` la hashea al asignarla, y pasarla por
                // `Hash::make` aquí la hashearía dos veces.
                'password' => 'secret1234',
            ]);
            $admin->assignRole(Role::findOrCreate('admin', 'sanctum'));
        }

        if ($admin === null) {
            return;
        }
        $admin->forceFill(array_filter([
            'full_name' => $admin->full_name ?: 'Admin User',
            'company' => $admin->company ?: 'Dirección del periódico',
            'phone' => $admin->phone ?: '+34 985 100 200',
            'email_verified_at' => $admin->email_verified_at ?: now(),
        ], fn ($v) => $v !== null))->save();
        // Misma regla que la API (`SnkKilkanaUsersController::ensureByline`): cuenta que
        // puede escribir, cuenta con firma. Sin esto, el administrador abre el editor y no
        // tiene con qué firmar — tendría que atribuirse a un periodista.
        SnkKilkanaAuthorModel::firstOrCreate(['user_id' => $admin->id]);
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
                'description' => $attrs['description'] ?? null,
                'parent_id' => $parent ? ($ids[$parent] ?? null) : null,
                'position' => $position[$branch],
                'status' => $attrs['status'] ?? SnkKilkanaCategoryStatusEnum::Live,
            ])->id;
        }
    }

    private function seedPosts(): void
    {
        $posts = json_decode(
            (string) file_get_contents(__DIR__.'/data/posts.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        // La dirección que trae el snapshot es de un servicio de fotos de relleno; lo que
        // acaba en la base es el JPEG de `data/media/` que le corresponde, copiado al disco
        // del periódico. El porqué, en la propia clase.
        $fototeca = new SnkKilkanaDemoMediaLibrary;
        $fototeca->seedAll();

        foreach ($posts as $post) {
            $category = SnkKilkanaCategoryModel::query()->where('slug', $post['cat'])->firstOrFail();
            $author = $this->authorFor(trim($post['author']));
            $media = ! empty($post['image']) ? $fototeca->forUrl($post['image']) : null;
            $publishAt = $this->publishAt($post);
            $urlDate = $post['status'] === SnkKilkanaPostStatusEnum::Published->value
                ? substr($publishAt, 0, 10)
                : null;

            // La URL es fecha + slug. El snapshot contiene dos slugs repetidos en días
            // distintos y ambos son piezas legítimas, no duplicados que haya que colapsar.
            $row = SnkKilkanaPostModel::updateOrCreate([
                'url_date' => $urlDate,
                'slug' => $post['slug'],
            ], [
                'category_id' => $category->id,
                'author_id' => $author->id,
                // Quien la escribió, que NO es lo mismo que quien la firma: el acotado por
                // rol mira esto, no la firma. En la demo coinciden.
                'created_by' => $author->user_id,
                'cover_media_id' => $media?->id,
                'title' => $post['title'],
                'excerpt' => $post['excerpt'],
                'publish_at' => $publishAt,
                'status' => $post['status'],
                'content_tier' => $post['content_tier'],
                // El snapshot no trae cuerpo y sin cuerpo media demo no significa nada: el
                // artículo público sale en blanco y `content_tier` no corta por ningún sitio
                // porque no hay muro que colocar. Se compone aquí, determinista desde el slug.
                'body_blocks' => SnkKilkanaDemoBodyFactory::blocks($post, $media?->id),
                'featured' => $post['featured'] ?? false,
                'seo_title' => $post['seo_title'] ?? null,
                'seo_description' => $post['seo_description'] ?? null,
                'og_title' => $post['og_title'] ?? null,
                'og_description' => $post['og_description'] ?? null,
                'noindex' => $post['noindex'] ?? false,
            ]);

            // `syncWithoutDetaching` y no `sync`: re-sembrar repone lo que trae el snapshot y
            // **no quita** lo que alguien etiquetó a mano encima. Un seeder que borra trabajo
            // ajeno se convierte en un comando que da miedo ejecutar, y entonces no se ejecuta.
            $row->tags()->syncWithoutDetaching(
                collect($post['tags'] ?? [])
                    ->map(fn (string $name) => SnkKilkanaTagModel::firstOrCreateByName($name)->id)
                    ->all()
            );
        }

        // La fototeca sabe del fichero —medidas, peso, tipo, derechos— pero no de lo que la
        // foto CUENTA: eso solo lo sabe la pieza que la estrena. Sin esto la mediateca de la
        // demo son sesenta filas tituladas `unsplash-7.jpg`, sin alt y sin pie, y una
        // biblioteca así no se puede ni buscar ni auditar. El mismo relleno que aplica
        // `kilkana:enrich-media` en producción, aquí desde el servicio porque el comando
        // recorre TODOS los inquilinos y el seeder está sembrando uno.
        app(SnkKilkanaMediaService::class)->enrichFromPosts();
        $this->seedMediaDesks();
    }

    /**
     * La firma de la foto y el estante donde la archiva la mesa que la usa.
     *
     * `enrichFromPosts()` rellena lo que la pieza sabe de su portada —título, alt, pie— y ahí se
     * paraba: la mediateca quedaba con las columnas «Credit» y «Collection» vacías en las
     * sesenta filas. Dos columnas muertas en una tabla se leen como que la tabla no ha cargado,
     * y las dos son datos que una redacción sí tiene.
     *
     * **El crédito sale de quien la hizo**, no del titular: en la plantilla hay un fotógrafo
     * —«Deportes y fotografía»— y dos agencias, que es exactamente el reparto de un diario
     * comarcal. Determinista por el nombre del fichero, como todo lo demás del seeder.
     *
     * **Y el estante es la mesa que la estrena.** Una colección es un estante de la redacción y
     * no una categoría del periódico —no sale nunca a la calle—, así que archivar por la mesa
     * que usa el material es lo que hace una redacción de verdad. No es copiar la taxonomía del
     * artículo: es de qué mesa es esta foto, que es una propiedad del FICHERO. Una foto que no
     * usa nadie no entra en ningún estante, y así «sin clasificar» significa algo.
     */
    private function seedMediaDesks(): void
    {
        $creditos = ['Óscar Tomás', 'Agencia EFE', 'Redacción'];
        $estantes = [];

        foreach (SnkKilkanaMediaModel::query()->with('coveredPosts.category')->get() as $media) {
            $cambios = [];

            if (! $media->credit) {
                $cambios['credit'] = $creditos[crc32((string) $media->original_name) % count($creditos)];
            }
            if ($cambios) {
                $media->update($cambios);
            }

            $mesa = $media->coveredPosts->first()?->category;
            if (! $mesa) {
                continue;
            }

            // La mesa de una subsección es su madre: un diario comarcal archiva «Deportes», no
            // «Polideportivo». Sin `parent`, la propia sección.
            $nombre = $mesa->parent?->name ?? $mesa->name;
            $estantes[$nombre] ??= SnkKilkanaMediaCollectionModel::firstOrCreate(['name' => $nombre]);

            $media->collections()->syncWithoutDetaching([$estantes[$nombre]->id]);
        }
    }

    /**
     * Cuándo sale la pieza — y para una programada, cuándo sale **a partir de hoy**.
     *
     * El snapshot es estático y su calendario envejece. Las tres piezas `scheduled` se
     * escribieron con fecha de julio, así que unos meses después están programadas para el
     * pasado: `kilkana:publish-scheduled` corre cada minuto y las publica antes de que nadie
     * abra el CMS. La demo se queda sin el único estado que hay que ver para creerse el
     * programador, y re-sembrar además DUPLICA la fila —su `url_date` ya no es nulo, así que
     * `updateOrCreate` no la reconoce y crea otra—.
     *
     * Se mueve solo la fecha y se conserva la hora del snapshot: un cierre a las 6:30 sigue
     * siendo un cierre a las 6:30, que es lo que hace creíble la parrilla. El desplazamiento
     * sale del slug, así que las tres caen en días distintos y dos siembras del mismo día dan
     * lo mismo.
     */
    private function publishAt(array $post): string
    {
        $cuando = $post['publish_at'];

        if ($post['status'] !== SnkKilkanaPostStatusEnum::Scheduled->value
            || Carbon::parse($cuando)->isFuture()) {
            return $cuando;
        }

        return now()
            ->addDays(crc32($post['slug']) % 5 + 1)
            ->setTimeFromTimeString(substr($cuando, 11))
            ->toDateTimeString();
    }

    /**
     * La audiencia del fixture, en la tabla que la cuenta.
     *
     * `posts.reads` era una columna con un número grande dentro que nada incrementaba: el
     * se trataba como si fuera audiencia y el «más leído» ordenaba por ella. El número
     * del fixture no se pierde —es lo que hace la demo creíble—, cambia de sitio: se reparte
     * en `kilkana_post_views`, que es donde aterriza una lectura de verdad.
     *
     * El reparto va por la EDAD de cada pieza, no por el día del calendario. Una noticia se
     * lee sobre todo el día que sale y luego cae; con una curva igual para todas, «hoy»,
     * «esta semana» y «total» devolvían el mismo orden —solo era el total reescalado— y las
     * tres ventanas no probaban nada. Desfasadas por su fecha de publicación, lo de hoy lo
     * dominan las piezas recientes y el total las que más se leyeron en su momento.
     */
    private function seedViews(): void
    {
        // Decaimiento exponencial sobre 30 días: el primer día se lleva ~22% de las lecturas
        // y a las dos semanas queda cola. Enteros y sin azar: dos siembras dan lo mismo.
        $dias = 30;
        $pesos = [];
        for ($edad = 0; $edad < $dias; $edad++) {
            $pesos[$edad] = (int) round(1000 * exp(-$edad / 4));
        }
        $suma = array_sum($pesos);

        $posts = json_decode(
            (string) file_get_contents(__DIR__.'/data/posts.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $porPost = collect($posts)->mapWithKeys(fn (array $post) => [
            substr($post['publish_at'], 0, 10).'|'.$post['slug'] => $post['reads'],
        ]);
        $hoy = now()->startOfDay();

        foreach (SnkKilkanaPostModel::query()->published()->get(['id', 'slug', 'publish_at']) as $post) {
            $key = $post->publish_at?->toDateString().'|'.$post->slug;
            $total = (int) ($porPost[$key] ?? 0);
            if ($total <= 0 || $post->publish_at === null) {
                continue; // una pieza sin lecturas es un estado real; no se inventa
            }
            for ($edad = 0; $edad < $dias; $edad++) {
                $fecha = $post->publish_at->copy()->startOfDay()->addDays($edad);
                if ($fecha->greaterThan($hoy)) {
                    break; // el futuro no tiene lecturas
                }
                $lecturas = intdiv($total * $pesos[$edad], $suma);
                if ($lecturas <= 0) {
                    continue;
                }
                // Proporciones fijas y creíbles, sin azar: dos siembras dan lo mismo. 62% de
                // los que abren se quedan a leer, y de cada cinco lecturas cuatro son de
                // gente distinta. El reparto de procedencia es el típico de un digital: el
                // buscador manda, las redes pican, y la casa se lee sola.
                $aperturas = (int) round($lecturas / 0.62);
                $reparto = ['ref_search' => 45, 'ref_social' => 25, 'ref_internal' => 20, 'ref_direct' => 7, 'ref_other' => 3];

                SnkKilkanaPostViewModel::updateOrCreate(
                    ['post_id' => $post->id, 'date' => $fecha->toDateString()],
                    [
                        'opens' => $aperturas,
                        'engaged' => $lecturas,
                        'readers' => (int) round($lecturas * 0.78),
                        ...collect($reparto)->map(fn (int $pct) => intdiv($aperturas * $pct, 100))->all(),
                    ],
                );
            }
        }
    }
}
