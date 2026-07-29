<?php

namespace Packages\Snk\Kilkana\Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Http\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Packages\Core\Models\CoreUserModel;
use Packages\Snk\Kilkana\Enums\SnkKilkanaCategoryStatusEnum;
use Packages\Snk\Kilkana\Enums\SnkKilkanaCommentStatusEnum;
use Packages\Snk\Kilkana\Enums\SnkKilkanaConsentSourceEnum;
use Packages\Snk\Kilkana\Enums\SnkKilkanaMediaRightsEnum;
use Packages\Snk\Kilkana\Enums\SnkKilkanaSubscriberStatusEnum;
use Packages\Snk\Kilkana\Models\SnkKilkanaAuthorModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaCommentModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaLayoutModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaMediaCollectionModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaMediaModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaMediaTagModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaPostViewModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaSettingModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaSubscriberModel;
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

    /**
     * Metadatos de publicación, solo donde de verdad se separan de lo editorial.
     *
     * `seo_title` y `seo_description` existen para cuando el titular que vende la pieza en
     * el periodico no es el que la encuentra en un buscador: el editorial puede permitirse
     * ser elíptico, el de búsqueda tiene que decir de qué va y caber. Estas cuatro no están
     * para llenar columnas, están para que se vea la diferencia entre las dos escrituras —el
     * resto de las 60 piezas se queda sin ellas, que es el caso normal.
     */
    private const SEO = [
        'climate-summit-reaches-a-historic-agreement-after-14-hours-of-negotiation' => [
            'seo_title' => 'Acuerdo de la cumbre del clima 2026: qué se ha firmado y a quién obliga',
            'seo_description' => 'Las 14 horas de negociación, punto por punto: los compromisos de emisiones, el fondo de adaptación y los países que se han quedado fuera.',
        ],
        'the-central-bank-cuts-rates-for-the-first-time-in-two-years' => [
            'seo_title' => 'Bajada de tipos 2026: cuánto baja la hipoteca y desde cuándo',
            'seo_description' => 'El banco central recorta por primera vez en dos años. Qué significa para una hipoteca variable, para el ahorro y para el crédito a empresas.',
            // En redes gana la consecuencia; en el buscador, la consulta que se teclea.
            'og_title' => 'Baja el euríbor: esto es lo que dejarás de pagar cada mes',
            'og_description' => 'Primera bajada en dos años. Te contamos con números qué cambia en tu hipoteca.',
        ],
        'public-debt-falls-below-100-of-gdp-for-the-first-time-since-the-pandemic' => [
            'seo_title' => 'La deuda pública baja del 100% del PIB: los datos',
            'seo_description' => 'Primera vez desde la pandemia. De dónde viene la caída y qué parte es crecimiento y qué parte es inflación.',
            // Breve de agencia: la canónica apunta FUERA. Sin esto, dos medios compiten por
            // la misma pieza y el buscador elige por su cuenta cuál es el original.
            'canonical_url' => 'https://agencia.example/economia/deuda-publica-baja-del-100-del-pib',
        ],
        'remains-of-the-ancient-roman-port-unearthed-in-seville' => [
            'seo_title' => 'Hallazgo del puerto romano de Sevilla: qué se ha encontrado',
            'seo_description' => 'Los restos aparecidos bajo la ciudad, su datación y qué cambia en lo que se sabía del trazado del Guadalquivir.',
            // Cerrada, y su cuerpo lleva el bloque `paywall` que dice por dónde corta.
            'content_tier' => 'locked',
        ],
        'festival-season-kicks-off-with-record-attendance' => [
            // Medida: se lee entera hasta agotar la cuota. Sin bloque de muro, por eso.
            'content_tier' => 'metered',
        ],
        'pets-can-now-ride-the-subway-at-any-hour' => [
            // Retirada de buscadores pero VISIBLE en el periódico: sigue publicada y
            // enlazada, solo que no se anuncia en el sitemap ni se pide que se indexe.
            'noindex' => true,
        ],
    ];

    public function run(): void
    {
        $this->seedPermissions();
        $this->seedCategories();
        $this->seedPosts();
        $this->seedViews();
        $this->seedSocialImage();
        $this->seedAuthorProfiles();
        $this->seedStaff();
        // Después de los posts: reasigna dos breves a la firma de la casa.
        $this->seedCollectiveByline();
        $this->seedComments();
        $this->seedSubscribers();
        $this->seedLayouts();
        $this->seedSettings();
    }

    /**
     * La lista del boletín, con los tres estados representados.
     *
     * No estaba sembrada: la pantalla de suscriptores salía vacía en una demo donde todo lo
     * demás tiene contenido, así que no se veía ni el listado ni el filtro por estado.
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
     * COMPLETO a propósito. Un usuario a medias —sin nombre completo, sin teléfono— hace
     * que media UI parezca rota sin estarlo: la columna de organización sale vacía y no hay
     * forma de ver si el listado aguanta nombres largos.
     *
     * `job_title` es el cargo PUBLICABLE y va a la firma, no a la cuenta: aquí llegó a estar
     * la mesa («Mesa de Deportes») metida en `users.company`, que el panel rotula
     * «Organización» y no sale al público — el dato editorial vivía en la columna del CRM.
     * Ahora `company` dice lo que promete: el periódico para la plantilla, la organización
     * propia de quien colabora desde fuera.
     *
     * Los teléfonos son extensiones ficticias de una centralita. Sin foto: la del periodista
     * se sube a la biblioteca de medios y cuelga de su FIRMA (`avatar_media_id`), que es la
     * que se publica; sin ella, la UI dibuja el monograma.
     */
    private const REDACCION = [
        'Pedro Aranda' => [
            'job_title' => 'Redactor de Clima y Energía',
            'full_name' => 'Pedro Aranda Gil',
            'phone' => '+34 985 100 201',
            'bio' => 'Cubre clima y energía desde 2014. Antes en agencia, donde aprendió que una cifra sin contexto no es una noticia.',
            'website' => 'https://pedroaranda.example',
            'socials' => ['x' => 'https://x.com/ejemplo_paranda', 'linkedin' => 'https://linkedin.com/in/ejemplo-paranda'],
        ],
        'Lucía Ferrer' => [
            'job_title' => 'Redactora de Economía',
            'full_name' => 'Lucía Ferrer Bosch',
            'phone' => '+34 985 100 202',
            'bio' => 'Economía y vivienda. Le interesan los datos que la gente nota en el recibo antes que en el titular.',
            'website' => 'https://luciaferrer.example',
            'socials' => ['x' => 'https://x.com/ejemplo_lferrer'],
        ],
        'Marta Gil' => [
            'job_title' => 'Redactora de Cultura',
            'full_name' => 'Marta Gil Otero',
            'phone' => '+34 985 100 203',
            'bio' => 'Cultura y oficios. Doce años siguiendo festivales, salas pequeñas y a quien las sostiene.',
            'website' => null,
            'socials' => ['instagram' => 'https://instagram.com/ejemplo_mgil'],
        ],
        'Iván Soto' => [
            'job_title' => 'Redactor de Tribunales',
            'full_name' => 'Iván Soto Peña',
            'phone' => '+34 985 100 204',
            'bio' => 'Tribunales y administración. Escribe despacio y comprueba dos veces.',
            'website' => null,
            'socials' => [],
        ],
        'Clara Méndez' => [
            'job_title' => 'Redactora de Sanidad y Ciencia',
            'full_name' => 'Clara Méndez Arroyo',
            'phone' => '+34 985 100 205',
            'bio' => 'Sanidad y ciencia. Traduce estudios a consecuencias, que es lo que se puede usar.',
            'website' => 'https://claramendez.example',
            'socials' => ['x' => 'https://x.com/ejemplo_cmendez', 'mastodon' => 'https://mastodon.social/@ejemplo_cmendez'],
        ],
        'Diego Cano' => [
            'job_title' => 'Redactor de Deportes',
            'full_name' => 'Diego Cano Ferrán',
            'phone' => '+34 985 100 206',
            'bio' => 'Deportes. Del vestuario al presupuesto municipal que paga el polideportivo.',
            'website' => null,
            'socials' => ['x' => 'https://x.com/ejemplo_dcano'],
        ],
        'Nora Ibáñez' => [
            'job_title' => 'Corresponsal de Internacional',
            'full_name' => 'Nora Ibáñez Cruz',
            'phone' => '+34 985 100 207',
            'bio' => 'Internacional. Ha reporteado desde ocho países y desconfía de las cronologías limpias.',
            'website' => 'https://noraibanez.example',
            'socials' => ['linkedin' => 'https://linkedin.com/in/ejemplo-nibanez'],
        ],
        'Sara Lores' => [
            'job_title' => 'Redactora de Política',
            'full_name' => 'Sara Lores Vidal',
            'phone' => '+34 985 100 208',
            // La única de las ocho con seudónimo, y está aquí para que el alias se VEA: firma
            // «S. Lores» aunque su cuenta se llame Sara Lores. Su URL sigue siendo
            // /author/sara-lores —el slug se fija al crear la firma y no persigue al nombre—,
            // que es justo el contraste que hay que poder mirar en la tabla.
            'alias' => 'S. Lores',
            'bio' => 'Política y parlamento. Cuenta el trámite porque ahí es donde se decide.',
            'website' => null,
            'socials' => ['x' => 'https://x.com/ejemplo_slores'],
        ],
    ];

    /**
     * El periódico de la demo. `users.company` es la ORGANIZACIÓN de cada cuenta: para la
     * plantilla, esta; para quien colabora desde fuera, la suya. Antes aquí iba la mesa.
     */
    private const PERIODICO = 'The Beacon';

    /**
     * Firma de demo: la CUENTA del periodista más su fila editorial.
     *
     * La firma es un usuario del CMS, así que la identidad es el correo y el fixture solo
     * trae el nombre («Pedro Aranda») — se deriva. Eso es también lo que hace idempotente
     * la siembra: buscando por nombre, dos formas del mismo periodista abrirían dos firmas,
     * dos páginas públicas y partirían su hemeroteca.
     *
     * Con rol `redactor`: escribe y publica lo suyo. Es el rol de plantilla, y con él la
     * demo enseña de verdad el acotado por `created_by` — cada periodista ve sus piezas.
     */
    private function authorFor(string $name): SnkKilkanaAuthorModel
    {
        $ficha = self::REDACCION[$name] ?? [];

        $user = CoreUserModel::firstOrCreate(
            ['email' => Str::slug($name, '.').'@thebeacon.example'],
            // Sin Hash::make: el cast 'hashed' del modelo ya lo hace.
            ['password' => $this->demoPassword(), 'name' => $name]
        );
        // Fuera del create y solo sobre lo vacío: así una siembra sobre una base que ya
        // tenía la cuenta también la completa, en vez de dejarla a medias — que es como
        // estaban las ocho, con `full_name` y teléfono a NULL.
        $user->forceFill(array_filter([
            'full_name' => $user->full_name ?: ($ficha['full_name'] ?? $name),
            'company' => $user->company ?: self::PERIODICO,
            'phone' => $user->phone ?: ($ficha['phone'] ?? null),
            // Cuenta de demo que entra al panel: verificada, o el listado enseña a media
            // redacción «sin verificar» sin que eso signifique nada.
            'email_verified_at' => $user->email_verified_at ?: now(),
        ], fn ($v) => $v !== null))->save();
        $user->assignRole('redactor');

        // Sin alias ni cargo aquí: la firma nace publicando el nombre de la cuenta y su URL
        // se deriva de él (`deriveSlug`). Lo editorial lo pone después `seedAuthorProfiles`,
        // que es lo que hace que el slug de Sara Lores NO siga a su seudónimo.
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
     * El resto de la plantilla: quien no firma los 60 artículos del fixture pero existe en
     * cualquier redacción. Sin ellos, la demo solo enseña dos de los siete roles y no se ve
     * que el panel cambia según quién entra.
     *
     * La colaboradora lleva firma (puede escribir) y la de comunidad NO (no puede): es la
     * misma regla que aplica la API al dar de alta un usuario.
     */
    private function seedStaff(): void
    {
        $staff = [
            [
                'name' => 'Elena Vara', 'email' => 'elena.vara@thebeacon.example',
                // Su organización es la SUYA, no el periódico: colabora desde fuera, y esa
                // diferencia es lo que la columna «Organización» sirve para ver.
                'full_name' => 'Elena Vara Ruiz', 'company' => 'Estudio Vara Urbanismo',
                'phone' => '+34 985 100 209', 'role' => 'colaborador',
                'job_title' => 'Colaboradora de Urbanismo',
                'bio' => 'Escribe de urbanismo y memoria de barrio desde fuera de la redacción. Entrega y espera al cierre.',
            ],
            [
                'name' => 'Rubén Nieto', 'email' => 'ruben.nieto@thebeacon.example',
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

    /** Los breves que firma la casa. Piezas cortas y factuales, como en cualquier diario. */
    private const BREVES_DE_REDACCION = [
        'public-debt-falls-below-100-of-gdp-for-the-first-time-since-the-pandemic',
        'telecoms-switch-on-the-6g-pilot-network-in-three-cities',
    ];

    /**
     * La firma colectiva: «Redacción».
     *
     * Es el caso que el modelo parecía no admitir. Una firma ES una cuenta (`user_id` es
     * obligatorio y único), y «Redacción» no es una persona — pero tampoco hace falta cambiar
     * el esquema: se le da una CUENTA DE SERVICIO, sin rol y por tanto sin nada que hacer en
     * el panel, y la firma cuelga de ella con `alias`. Un breve de agencia deja de necesitar
     * que alguien se lo atribuya.
     *
     * Y de paso enseña lo otro: estos dos breves los TECLEÓ una redactora —`created_by` sigue
     * siendo la suya, que es lo que mira el acotado por rol— pero los FIRMA la casa. Quien
     * escribe y quien firma no tienen por qué coincidir, y hasta ahora la demo no lo mostraba.
     */
    private function seedCollectiveByline(): void
    {
        // La cuenta se llama como lo que es —para quien mira el listado de Usuarios— y el
        // ALIAS es lo que sale publicado. Llamarla «Redacción» a secas habría dado el mismo
        // nombre publicado sin alias, y entonces la columna no demostraría nada.
        $cuenta = CoreUserModel::firstOrCreate(
            ['email' => 'redaccion@thebeacon.example'],
            ['password' => $this->demoPassword(), 'name' => 'Redacción (cuenta de servicio)']
        );
        $cuenta->forceFill([
            'full_name' => $cuenta->full_name ?: 'Cuenta de servicio de la redacción',
            'company' => $cuenta->company ?: self::PERIODICO,
            // Sin rol, sin teléfono y sin verificar: nadie entra con ella.
        ])->save();

        $firma = SnkKilkanaAuthorModel::firstOrCreate(
            ['user_id' => $cuenta->id],
            [
                'alias' => 'Redacción',
                'bio' => 'Piezas breves elaboradas por la redacción de '.self::PERIODICO.' con información de agencias.',
            ]
        );

        // Solo el autor: `created_by` se queda con quien la escribió.
        SnkKilkanaPostModel::query()
            ->whereIn('slug', self::BREVES_DE_REDACCION)
            ->update(['author_id' => $firma->id]);
    }

    /** Contraseña de las cuentas de demo; sin config utilizable, una aleatoria (no login). */
    private function demoPassword(): string
    {
        $password = config('snk.kilkana.kilkana.demo_reader_password');
        if ((! is_string($password) || $password === '') && app()->environment(['local', 'testing'])) {
            $password = 'password';
        }

        return is_string($password) && $password !== '' ? $password : Str::random(32);
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
                    // vez y el filtro de periodo del panel no distingue nada.
                    'created_at' => now()->subHours(($slot * 7) % 672),
                    'updated_at' => now()->subHours(($slot * 7) % 672),
                ]);

                // Una respuesta cada pocos hilos: la conversación anidada también hay que
                // poder verla (el panel la indenta y la API la borra en cascada).
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
        $password = config('snk.kilkana.kilkana.demo_reader_password');
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
                    // Sin Hash::make: el cast 'hashed' del modelo ya lo hace.
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
        $tenant = $settings['tenant'] ?? [];

        // El fichero sigue guardando la forma que ve la API —`tenant` anidado—, porque es un
        // snapshot de lo que devuelve el endpoint. Aquí se aplana a columnas.
        SnkKilkanaSettingModel::firstOrCreate(['id' => 1], [
            'theme' => $settings['theme'] ?? null,
            'theme_overrides' => $settings['theme_overrides'] ?? [],
            'tenant_name' => $tenant['name'] ?? null,
            'tenant_tagline' => $tenant['tagline'] ?? null,
            'tenant_city' => $tenant['city'] ?? null,
            'tenant_url' => $tenant['url'] ?? null,
            'onboarded' => (bool) ($settings['onboarded'] ?? false),
        ]);
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
        foreach (['posts', 'categories', 'authors', 'media', 'tags', 'comments', 'subscribers', 'layouts', 'settings'] as $resource) {
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
                ...$crud('posts', 'categories', 'authors', 'media', 'tags', 'comments', 'subscribers', 'layouts', 'settings'),
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
        // sin perfil era la única fila a medias de la tabla.
        $demo->forceFill([
            'full_name' => $demo->full_name ?: 'Demo Reader',
            'company' => $demo->company ?: 'Cuenta de demostración',
            'phone' => $demo->phone ?: '+34 900 000 000',
            'email_verified_at' => $demo->email_verified_at ?: now(),
        ])->save();
        $demo->assignRole($reader);

        // El administrador lo crea el seeder de CORE, que no sabe nada de periódicos: nace
        // con nombre y poco más. Se completa aquí —solo lo vacío— porque es la primera fila
        // del listado de usuarios y la cara de la sesión en la barra lateral.
        $admin = CoreUserModel::query()
            ->where('email', config('core.auth.admin_email', 'admin@snake.com'))
            ->first();
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
                'description' => $attrs['description'],
                'parent_id' => $parent ? ($ids[$parent] ?? null) : null,
                'position' => $position[$branch],
                // El periódico de muestra está abierto: sus mesas ya se lanzaron.
                'status' => SnkKilkanaCategoryStatusEnum::Live,
            ])->id;
        }
    }

    private function seedPosts(): void
    {
        $posts = json_decode((string) file_get_contents(__DIR__.'/data/posts.json'), true);
        foreach ($posts as $i => $post) {
            $category = SnkKilkanaCategoryModel::query()->where('slug', $post['cat'])->first();
            $author = $this->authorFor(trim($post['author']));
            // El fixture de imagen es posicional: posts.json[N] ↔ data/media/unsplash-N.jpg.
            $media = ! empty($post['image'])
                ? $this->seedCoverMedia($post, $category, $i + 1)
                : null;

            $row = SnkKilkanaPostModel::firstOrCreate(['slug' => $post['slug']], [
                'category_id' => $category->id,
                'author_id' => $author->id,
                // Quien la escribió, que NO es lo mismo que quien la firma: el acotado por
                // rol mira esto, no la firma. En la demo coinciden.
                'created_by' => $author->user_id,
                'cover_media_id' => $media?->id,
                'title' => $post['title'],
                'excerpt' => $post['excerpt'],
                ...self::SEO[$post['slug']] ?? [],
                'publish_at' => $post['publish_at'],
                'status' => $post['status'],
                'body_blocks' => $post['body_blocks'],
                'featured' => $post['featured'] ?? false,
            ]);

            $tagIds = collect($post['tags'] ?? [])
                ->map(fn (string $name) => SnkKilkanaTagModel::firstOrCreate(['name' => $name])->id);
            $row->tags()->syncWithoutDetaching($tagIds);
        }
    }

    /**
     * Una pieza con imagen social propia, distinta de su portada.
     *
     * Va aparte del resto de metadatos porque es lo único que necesita un ID: la portada se
     * recorta 16:9 para la tarjeta y las redes piden algo cercano a 1200×630, así que un
     * periódico acaba subiendo una versión aparte. Aquí se toma prestada la portada de otra
     * pieza —el fixture no trae un segundo juego de imágenes— y basta para que se vea que
     * son dos campos distintos y que el de redes manda cuando existe.
     */
    private function seedSocialImage(): void
    {
        $destino = SnkKilkanaPostModel::query()
            ->where('slug', 'the-central-bank-cuts-rates-for-the-first-time-in-two-years')
            ->first();
        $prestada = SnkKilkanaPostModel::query()
            ->where('slug', 'festival-season-kicks-off-with-record-attendance')
            ->value('cover_media_id');

        if ($destino && $prestada) {
            $destino->update(['og_image_id' => $prestada]);
        }
    }

    /**
     * La audiencia del fixture, en la tabla que la cuenta.
     *
     * `posts.reads` era una columna con un número grande dentro que nada incrementaba: el
     * panel la pintaba como si fuera audiencia y el «más leído» ordenaba por ella. El número
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

        $posts = json_decode((string) file_get_contents(__DIR__.'/data/posts.json'), true);
        $porSlug = collect($posts)->pluck('reads', 'slug');
        $hoy = now()->startOfDay();

        foreach (SnkKilkanaPostModel::query()->published()->get(['id', 'slug', 'publish_at']) as $post) {
            $total = (int) ($porSlug[$post->slug] ?? 0);
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
                'rights_status' => SnkKilkanaMediaRightsEnum::Licensed,
                'license' => 'Unsplash License',
            ]
        );

        $this->tagMedia($media, $post, $n);

        return $media;
    }

    /**
     * Vocabulario de FICHERO, que es lo que etiqueta una fototeca.
     *
     * Aquí se copiaban los tags del artículo y el nombre de su sección, así que
     * `kilkana_media_tags` acababa con las mismas 151 filas que `kilkana_tags` y las
     * colecciones eran las 18 secciones. Dos tablas con el mismo contenido hacen que la
     * taxonomía de medios parezca una duplicación inútil, y esconden para qué sirve: un tag
     * de medio responde «qué ES esta foto», no «de qué habla la noticia». La misma imagen de
     * un pleno vale para una pieza de política y para otra de vivienda, y no cambia de
     * naturaleza al reutilizarse.
     *
     * Dos de los tres grupos salen del propio fichero. El tercero NO se puede derivar: qué
     * hay dentro de un JPEG de 800×450 lo sabe quien lo mira, así que aquí se asigna de una
     * lista corta y en rotación fija por índice. Es fixture y se dice: son los valores que
     * un editor gráfico pone a mano, puestos para que la pantalla se pueda probar con
     * variedad real en vez de con una etiqueta repetida sesenta veces.
     */
    /** Meses en español. `translatedFormat` depende del locale de la app y devolvía «June». */
    private const MESES = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio',
        7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];

    private const TAGS_DE_IMAGEN = [
        'plano general', 'primer plano', 'detalle', 'interior',
        'exterior', 'nocturna', 'con personas', 'sin personas', 'archivo',
    ];

    private function tagMedia(SnkKilkanaMediaModel $media, array $post, int $n): void
    {
        $nombres = [];

        // 1 · Del fichero: proporción real, no la que se supone.
        if ($media->width && $media->height) {
            $ratio = $media->width / $media->height;
            $nombres[] = $ratio > 1.2 ? 'apaisado' : ($ratio < 0.85 ? 'vertical' : 'cuadrado');
        }

        // 2 · Del uso: esta imagen abre una pieza. Es un hecho del fichero, no un tema.
        $nombres[] = 'portada';

        // 3 · De la mirada de alguien. Dos por medio, rotando para que cubran la lista.
        $lista = self::TAGS_DE_IMAGEN;
        $nombres[] = $lista[$n % count($lista)];
        $nombres[] = $lista[($n * 3 + 4) % count($lista)];

        $media->tags()->syncWithoutDetaching(
            collect($nombres)->unique()
                // trim antes de la clave: `name` es única, pero « archivo» y «archivo» son
                // dos nombres distintos para el índice y saldrían dos etiquetas.
                ->map(fn (string $tag) => SnkKilkanaMediaTagModel::firstOrCreate(['name' => trim($tag)])->id)
        );

        // La colección es el LOTE con el que entró el material, no la sección donde se usó:
        // una foto se archiva por cuándo y para qué se encargó, y puede acabar en tres
        // secciones distintas sin dejar de ser del mismo encargo.
        //
        // Por SEMANA y no por mes: las 60 portadas del fixture caen en el mismo mes, y una
        // colección con los sesenta medios dentro no deja probar nada —ni el filtro, ni el
        // selector, ni que un medio pertenezca a varias—.
        $fecha = $post['publish_at'] ?? null;
        if ($fecha) {
            $lunes = Carbon::parse($fecha)->startOfWeek();
            $lote = 'Portadas semana del '.$lunes->day.' de '.self::MESES[$lunes->month].' '.$lunes->year;
            $media->collections()->syncWithoutDetaching(
                [SnkKilkanaMediaCollectionModel::firstOrCreate(['name' => trim($lote)])->id]
            );
        }
    }
}
