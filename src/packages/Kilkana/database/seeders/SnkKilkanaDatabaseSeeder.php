<?php

namespace Packages\Kilkana\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Packages\Core\Models\CoreUserModel;
use Packages\Core\Support\CorePackageSelectorSupport;
use Packages\Kilkana\Enums\SnkKilkanaAuthorKindEnum;
use Packages\Kilkana\Enums\SnkKilkanaCategoryStatusEnum;
use Packages\Kilkana\Enums\SnkKilkanaCommentStatusEnum;
use Packages\Kilkana\Enums\SnkKilkanaConsentSourceEnum;
use Packages\Kilkana\Enums\SnkKilkanaPostStatusEnum;
use Packages\Kilkana\Enums\SnkKilkanaSubscriberStatusEnum;
use Packages\Kilkana\Models\SnkKilkanaAuthorModel;
use Packages\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Kilkana\Models\SnkKilkanaCategorySlugModel;
use Packages\Kilkana\Models\SnkKilkanaCommentModel;
use Packages\Kilkana\Models\SnkKilkanaMediaCollectionModel;
use Packages\Kilkana\Models\SnkKilkanaMediaModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Models\SnkKilkanaPostRevisionModel;
use Packages\Kilkana\Models\SnkKilkanaPostSlugModel;
use Packages\Kilkana\Models\SnkKilkanaPostViewModel;
use Packages\Kilkana\Models\SnkKilkanaSettingModel;
use Packages\Kilkana\Models\SnkKilkanaSubscriberModel;
use Packages\Kilkana\Models\SnkKilkanaTagModel;
use Packages\Kilkana\Models\SnkKilkanaThemeModel;
use Packages\Kilkana\Services\SnkKilkanaMediaService;
use Packages\Kilkana\Services\SnkKilkanaPostService;
use Packages\Kilkana\Services\SnkKilkanaRoleCatalogService;
use Packages\Kilkana\Services\SnkKilkanaThemeCatalogSyncService;
use Packages\Kilkana\Services\SnkKilkanaThemeWearService;
use Spatie\Permission\Models\Role;

/**
 * Permisos del CMS y snapshot local de kilkana.app: taxonomía y 522 artículos
 * (data/posts.json) resolviendo strings a autor, sección, portada y tags.
 */
class SnkKilkanaDatabaseSeeder extends Seeder
{
    /** El perfil resuelto, o `[]` si esta base no es de ningun periodico declarado. */
    private ?array $perfil = null;

    /**
     * Taxonomía del periódico. Dos niveles: las mesas de redacción y sus subsecciones.
     *
     * El orden de declaración manda: una hija tiene que ir DESPUÉS de su madre, porque
     * `seedCategories` resuelve `parent_id` con los ids ya creados. `position` se numera
     * por nivel, así que las hermanas se ordenan entre ellas y no contra el resto.
     *
     * `medio-ambiente` nace sin artículos a propósito: una sección recién abierta es un
     * estado real y ejercita la página de sección sin contenido.
     *
     * **Y el estado de cada una también manda**: el modelo rechaza una sección más visible que
     * su madre —`draft` < `hidden` < `live`— y esa regla corre igual en el alta, así que una
     * entrada con madre `draft` e hija `live` no rompe una pantalla: rompe el aprovisionamiento
     * de cada periódico nuevo. Hoy la tabla la cumple —`agenda` es `hidden` bajo `cultura`, que
     * es `live`—.
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
        // Retirada del menu con el archivo lleno, que es justo lo que `hidden` sabe hacer y
        // `draft` no: sus piezas siguen abriendose por su URL.
        'agenda' => ['name' => 'Agenda', 'parent' => 'cultura', 'status' => SnkKilkanaCategoryStatusEnum::Hidden],
        // Una sección que cambió de nombre, con la ruta que dejó atrás: el catálogo la declara
        // en vez de renombrarla al sembrar, porque el slug es su identidad aquí y renombrarla
        // dejaba a la siembra siguiente intentando recrear una URL que ya está retirada.
        'sucesos-y-tribunales' => ['name' => 'Sucesos', 'retired' => ['sucesos']],
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

        // Dos interruptores, y hacen falta los dos: el entorno dice si esta instalacion admite
        // datos de demo, y el PERFIL dice si este periodico en concreto los tiene. Con uno solo,
        // `core:seed --client=otro` con la variable puesta vuelca el archivo de un periodico
        // dentro de la base de otro.
        if (! $this->demoData()) {
            // Y se dice cuál de las dos paró: una siembra que termina con éxito y sin escribir una
            // fila es indistinguible de una correcta, y la pregunta que sigue —«¿corrió con la
            // variable?»— tiene dos respuestas posibles desde que el perfil también puede pararla.
            $cliente = $this->clientSlug() ?? 'esta base';
            $this->command?->getOutput()->writeln(match (true) {
                ! config('kilkana.kilkana.seed_demo', false) => '<comment>demo: KILKANA_SEED_DEMO no está puesta.</comment>',
                // Las dos significan lo mismo hoy y son diagnósticos distintos el día que alguien
                // escriba mal un slug en `clients`: sin perfil, el fichero no existe o no la
                // reclama; con perfil, es una decisión escrita.
                $this->profile() === [] => "<comment>demo: ningún perfil reclama {$cliente}.</comment>",
                default => "<comment>demo: el perfil de {$cliente} dice que no lleva datos de demo.</comment>",
            });

            return;
        }

        $this->seedCategories();
        $this->seedPosts();
        $this->seedViews();
        $this->seedAuthorProfiles();
        $this->seedStaff();
        // Después de las firmas: lo que decide si una cuenta de servicio sobra es que ninguna
        // firma cuelgue ya de ella, y eso lo acaba de escribir `seedAuthorProfiles`.
        $this->retireServiceAccounts();
        $this->seedPortraits();
        $this->seedEditHistory();
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
            // Dirige el periódico: manda en la portada y en la identidad, y su cargo lo decía
            // mientras sus permisos eran los de una redactora.
            'role' => 'director',
            'job_title' => 'Editora jefe',
            'bio' => 'Dirige El Diario desde 2021. Antes cubrió tribunales en la prensa provincial durante doce años.',
            'website' => 'https://martabeneyto.es',
            'socials' => ['x' => 'https://x.com/martabeneyto', 'linkedin' => 'https://www.linkedin.com/in/martabeneyto'],
        ],
        'Ramón Clavel' => [
            'email' => 'ramon@eldiario.example',
            // Mesa de edición: publica y corrige lo de cualquiera, y no compone la portada.
            // Es el rol que faltaba para poder probar el muro de `edit others posts`.
            'role' => 'editor',
            'job_title' => 'Jefe de Local · mesa de edición',
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
            'alias' => 'Redacción',
            'job_title' => self::PERIODICO,
            'bio' => 'Piezas firmadas por el equipo de redacción.',
            'public_email' => 'redaccion@eldiario.example',
            'kind' => SnkKilkanaAuthorKindEnum::Collective,
            'role' => null,
        ],
        // El teletipo. Su organización es la agencia, no el periódico, y sin rol: nadie de
        // fuera escribe en este CMS, sus piezas las vuelca la redacción.
        'Agencia EFE' => [
            'alias' => 'Agencia EFE',
            'job_title' => 'Agencia de noticias',
            'bio' => 'Teletipos de agencia editados por la redacción.',
            'kind' => SnkKilkanaAuthorKindEnum::Agency,
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
            'public_email' => 'josep.miralles@colaboradores.eldiario.example',
            'kind' => SnkKilkanaAuthorKindEnum::Contributor,
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
     * El rol sale de la ficha y `redactor` es el de por defecto: escribe y publica lo suyo, que
     * es el rol de plantilla y con el que la demo enseña el acotado por `created_by`. Los otros
     * dos que abren el panel —dirección y mesa— tienen que existir en el juego de datos o no hay
     * forma de probar lo que los separa: publicar lo ajeno, componer la portada, tocar la
     * identidad del periódico.
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
        // `syncRoles` y no `assignRole`: una demo sembrada antes tiene a la editora jefe como
        // redactora, y reaplicar el catálogo tiene que corregirlo.
        $user->syncRoles([$ficha['role'] ?? 'redactor']);

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
                // La clase la manda el catálogo —es estructura, no redacción— y el contacto
                // público solo rellena lo vacío. En un diario local se le escribe a la
                // periodista a su correo del periódico, así que en la demo coincide con el de
                // acceso; son dos columnas distintas aunque coincidan, y solo una sale a la calle.
                'kind' => SnkKilkanaAuthorKindEnum::Staff,
                'public_email' => $autor->public_email ?: ($ficha['email'] ?? null),
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
     * esquema **hasta que dejó de obligar**: una firma con `kind` colectivo o de agencia se
     * siembra ahora SIN CUENTA, que es lo que es —publica y no entra—, y el listado de firmas
     * enseña «No seat» de verdad en vez de una cuenta de servicio que nadie usa.
     *
     * Quien sí entra —un columnista con rol— conserva la suya: sin rol no es lo mismo que sin
     * cuenta, y las dos cosas hay que poder enseñarlas.
     */
    private function externalByline(string $nombre): SnkKilkanaAuthorModel
    {
        $ficha = self::FIRMAS_EXTERNAS[$nombre];

        // Sin cuenta: la firma se identifica por su alias, que es lo que sale publicado.
        if (! isset($ficha['email'])) {
            $firma = SnkKilkanaAuthorModel::firstOrCreate(
                ['slug' => Str::slug($ficha['alias'])],
                array_filter([
                    'alias' => $ficha['alias'],
                    'job_title' => $ficha['job_title'],
                    'bio' => $ficha['bio'],
                    'public_email' => $ficha['public_email'] ?? null,
                ], fn ($v) => $v !== null)
            );

            // **Y se desvincula si venía de cuando el esquema exigía cuenta.** Una base sembrada
            // antes tiene esta firma colgando de una cuenta de servicio; el catálogo dice que no
            // tiene ninguna, y eso es lo que hay que poder enseñar.
            $firma->fill(['kind' => $ficha['kind'], 'user_id' => null])->save();

            return $firma;
        }

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

        $firma = SnkKilkanaAuthorModel::firstOrCreate(
            ['user_id' => $cuenta->id],
            array_filter([
                'alias' => $ficha['alias'] ?? null,
                'job_title' => $ficha['job_title'],
                'bio' => $ficha['bio'],
                'public_email' => $ficha['public_email'] ?? null,
            ], fn ($v) => $v !== null)
        );

        $firma->fill(['kind' => $ficha['kind'] ?? SnkKilkanaAuthorKindEnum::Contributor])->save();

        return $firma;
    }

    /**
     * La cuenta de la mesa de edición: quien vuelca lo que firma alguien sin asiento.
     *
     * Se busca por rol y no por correo porque el reparto es lo que define el oficio; si un día
     * no hubiera mesa, `created_by` vuelve a ser nulo, que es lo que era antes de esto.
     */
    private function deskUserId(): ?int
    {
        return $this->mesa ??= CoreUserModel::query()
            ->whereHas('roles', fn ($rol) => $rol->where('name', 'editor'))
            ->value('id');
    }

    private ?int $mesa = null;

    /**
     * El retrato de cada firma de persona, servido desde el disco del periódico.
     *
     * Una página de autor sin foto es uno de los cuatro requisitos que la pantalla de firmas
     * existe para contar, y sin ninguna puesta esa pantalla enseña su lista de trabajo entera y
     * nunca el otro lado. **No las tienen todas a propósito**: una agencia y un colectivo no
     * tienen cara, así que el contador se queda en tres y sigue enseñando las dos cosas.
     *
     * Las fotos son de Unsplash, la misma licencia que las sesenta del archivo, y el crédito va
     * en la fila del medio porque es lo que la mediateca pide de cualquier foto publicable.
     *
     * Se emparejan por el **correo de la cuenta** y no por el slug de la firma: el slug se
     * mueve —renombrar una firma deja el viejo redirigiendo— y el correo es la identidad.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const RETRATOS = [
        'marta@eldiario.example' => ['marta-beneyto.jpg', 'Janko Ferlič'],
        'ramon@eldiario.example' => ['ramon-clavel.jpg', 'Abdullah Ali'],
        'ivan@eldiario.example' => ['ivan-sagredo.jpg', 'Aditya Sethia'],
        'nuria@eldiario.example' => ['nuria-espi.jpg', 'Circling Sea'],
        'lucia@eldiario.example' => ['lucia-ferrandiz.jpg', 'Imani Bahati'],
        'oscar@eldiario.example' => ['oscar-tomas.jpg', 'Aditya Sethia'],
        'josep.miralles@colaboradores.eldiario.example' => ['josep-miralles.jpg', 'Glen Hodson'],
        'elena.vara@estudiovara.example' => ['elena-vara.jpg', 'Vladislav Nikonov'],
    ];

    private function seedPortraits(): void
    {
        $fototeca = new SnkKilkanaDemoMediaLibrary(__DIR__.'/data/portraits');

        foreach (self::RETRATOS as $email => [$fichero, $autor]) {
            $firma = SnkKilkanaAuthorModel::query()
                ->whereHas('user', fn ($cuenta) => $cuenta->where('email', $email))
                ->first();

            if ($firma === null || $firma->avatar_media_id !== null) {
                continue;
            }

            $foto = $fototeca->forName($fichero);
            if ($foto === null) {
                continue;
            }

            // El alt y el crédito los pone quien sabe de quién es la cara: la fototeca solo
            // sabe de ficheros, y una foto de la mediateca sin alt es trabajo pendiente.
            $foto->fill([
                'alt' => $foto->alt ?: "Retrato de {$firma->name}",
                'credit' => $foto->credit ?: $autor,
            ])->save();

            $firma->update(['avatar_media_id' => $foto->id]);
        }
    }

    /**
     * El rastro de haber trabajado: revisiones, URLs retiradas y papelera.
     *
     * Sin esto hay tres funcionalidades **construidas** que el juego de datos no ejercita: el
     * historial del editor sale vacío, las tres fichas que enseñan «Route history» dicen siempre
     * «Never renamed», y la papelera de la mediateca no tiene nada que restaurar. Un periódico
     * con quinientas piezas y cero ediciones no existe.
     *
     * **Y se hace por el camino de la aplicación, no escribiendo filas.** Editar una pieza con
     * el servicio es lo que crea su revisión y mueve su URL —cinco escrituras en una
     * transacción—, así que sembrarlo a mano sería mantener una segunda versión de esa regla
     * que se separa de la primera en cuanto alguien la toque. Es lo mismo que ya hace la
     * fototeca, que entra por donde entra una subida real.
     */
    private function seedEditHistory(): void
    {
        $posts = app(SnkKilkanaPostService::class);

        // Una pieza concreta y estable —la más antigua de la sección más poblada— para que
        // resembrar no reparta el historial por otra distinta cada vez.
        $pieza = SnkKilkanaPostModel::query()
            ->published()
            ->orderBy('publish_at')
            ->orderBy('id')
            ->first();

        $sinHistorial = $pieza !== null
            && SnkKilkanaPostRevisionModel::query()->where('post_id', $pieza->id)->doesntExist();

        if ($sinHistorial) {
            // Dos ediciones: la primera cambia el titular —y con él la URL, que deja su 301— y
            // la segunda solo la entradilla. Dos revisiones y una ruta retirada.
            $posts->update($pieza, [
                'title' => $pieza->title.' (actualizado)',
                'slug' => $pieza->slug.'-actualizado',
            ], (int) $pieza->created_by, false);

            $posts->update($pieza->fresh(), [
                'excerpt' => trim((string) $pieza->excerpt).' Ampliada con la reacción del pleno.',
            ], (int) $pieza->created_by, false);
        }

        // Una firma que cambió de ruta: el trait guarda la vieja y la deja redirigiendo, que es
        // lo que la ficha enseña como «301». La sección equivalente la declara el catálogo —su
        // slug es su identidad al sembrar, así que renombrarla aquí rompía la siembra siguiente—.
        $firma = SnkKilkanaAuthorModel::query()->where('slug', 'lucia-ferrandiz')->first();
        if ($firma !== null && $firma->retiredSlugs()->count() === 0) {
            $firma->update(['slug' => 'lucia-ferrandiz-marco']);
        }

        // Y dos fotos en la papelera: `kilkana_media` es la única tabla del inquilino con
        // `deleted_at`, así que es lo único que una papelera puede tener dentro.
        //
        // Solo si está vacía: sin la guarda, cada siembra mandaba dos más y la papelera crecía
        // sola —una demo resembrada tres veces enseñaba seis—.
        if (SnkKilkanaMediaModel::onlyTrashed()->doesntExist()) {
            SnkKilkanaMediaModel::query()
                ->unused()
                ->orderByDesc('id')
                ->take(2)
                ->get()
                ->each
                ->delete();
        }
    }

    /**
     * Retira las cuentas de servicio que este seeder creaba y ya no crea.
     *
     * Mientras una firma exigió cuenta, «Redacción» y «Agencia EFE» tenían una **solo para
     * satisfacer la clave foránea**. Ya no: cuelgan de nadie, y en una base sembrada antes esas
     * dos cuentas se quedaron sin rol y sin firma — dos cuentas de nadie en el listado de
     * usuarios, que además pueden autenticarse aunque la puerta del panel las rechace.
     *
     * **Se borra solo lo que sigue siendo lo que era**: sin rol, sin firma y con el correo que
     * este seeder les puso. Si alguien le dio un rol o le colgó una firma, es una cuenta en uso y
     * no se toca — un seeder que borra cuentas de gente es peor que dos filas de más.
     *
     * Las piezas que quedan apuntando a ellas con `created_by` pasan a no tener creador, por la
     * clave foránea. Es lo correcto: **a un teletipo no lo tecleó nadie en el CMS**, y el acotado
     * por rol se comporta igual que hoy —una cuenta que nadie usa ya no era de nadie—.
     */
    private function retireServiceAccounts(): void
    {
        $correos = collect(self::FIRMAS_EXTERNAS)
            ->reject(fn (array $ficha) => isset($ficha['email']))
            ->keys()
            ->map(fn (string $nombre) => Str::slug($nombre).'@eldiario.example')
            ->push('redaccion@eldiario.example', 'efe@eldiario.example')
            ->unique();

        foreach ($correos as $email) {
            $cuenta = CoreUserModel::query()->where('email', $email)->first();

            if ($cuenta === null || $cuenta->roles()->exists()) {
                continue;
            }
            if (SnkKilkanaAuthorModel::query()->where('user_id', $cuenta->id)->exists()) {
                continue;
            }

            $cuenta->delete();
        }
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
        $perfil = $this->profile();
        $tenant = $perfil['tenant'] ?? [];

        // El fichero guarda el SLUG del tema, nunca su id: un id de una base no significa nada
        // en otra, y un perfil tiene que poder sembrarse en cualquiera.
        $catalogo = SnkKilkanaThemeModel::query()->published();
        $tema = (isset($perfil['theme'])
            ? (clone $catalogo)->where('slug', $perfil['theme'])->first()
            : null)
            ?? (clone $catalogo)->orderByDesc('featured')->orderBy('position')->first();

        $fila = SnkKilkanaSettingModel::firstOrCreate(['id' => 1], [
            'tenant_name' => $tenant['name'] ?? null,
            'tenant_tagline' => $tenant['tagline'] ?? null,
            'tenant_city' => $tenant['city'] ?? null,
            'tenant_url' => $this->tenantUrl(),
            'onboarded' => (bool) ($perfil['onboarded'] ?? false),
        ]);

        // La cabecera escrita manda —el inquilino puede renombrarse desde el panel—, pero que no
        // coincida con la del perfil también es la firma de estar sembrando el periódico
        // equivocado, y eso no puede pasar callado.
        if (isset($tenant['name']) && $fila->tenant_name !== null && $fila->tenant_name !== $tenant['name']) {
            $this->command?->getOutput()->writeln(
                "<comment>demo: la base se llama «{$fila->tenant_name}» y el perfil dice «{$tenant['name']}».</comment>"
            );
        }

        // Vestir COPIA el spec, no lo apunta: apuntar a la fila del catálogo rompería el
        // congelado, porque la siguiente sincronización le cambiaría el tema al periódico. Solo
        // si va desnudo — `firstOrCreate` no pisa lo que el inquilino ya eligió.
        if ($tema && $fila->theme_spec === null) {
            app(SnkKilkanaThemeWearService::class)->wear($tema);
        }
    }

    /**
     * El periódico que se siembra en ESTA base, declarado en `data/profiles/`.
     *
     * **El perfil no lo elige quien lanza el comando, lo elige la base.** Cada fichero declara
     * de qué clientes es, y aquí se busca el que reclame el de esta conexión. Con una variable
     * de entorno, `core:seed --client=otro` con la variable puesta vuelca la cabecera y el
     * archivo de un periódico dentro de la base de otro: pasó, y fueron 523 piezas de El Diario
     * dentro de dos bases ajenas.
     *
     * Sin perfil no hay identidad ni datos de demo, que es lo que le toca a un cliente real: se
     * nombra a sí mismo al darse de alta, y nadie le siembra un archivo encima.
     *
     * @return array<string, mixed>
     */
    /**
     * Si esta base lleva datos de demo, que son DOS condiciones y hacen falta las dos.
     *
     * El entorno dice si esta instalación los admite; el perfil, si este periódico en concreto
     * los tiene. Cuelgan de aquí las cuentas de demo igual que el archivo: una cuenta con
     * contraseña conocida dentro de la base de un cliente es un acceso ajeno a su panel.
     */
    private function demoData(): bool
    {
        return (bool) config('kilkana.kilkana.seed_demo', false) && ($this->profile()['demo'] ?? false);
    }

    private function profile(): array
    {
        if ($this->perfil !== null) {
            return $this->perfil;
        }

        $cliente = $this->clientSlug();
        $encontrados = [];

        foreach (glob(__DIR__.'/data/profiles/*.json') ?: [] as $ruta) {
            $perfil = json_decode((string) file_get_contents($ruta), true, flags: JSON_THROW_ON_ERROR);

            if ($cliente !== null && in_array($cliente, $perfil['clients'] ?? [], true)) {
                $encontrados[basename($ruta, '.json')] = $perfil;
            }
        }

        // Dos perfiles que reclaman el mismo cliente es un catálogo roto, y quedarse con el
        // primero por orden de fichero convierte el fallo en «el periódico amaneció con otro
        // nombre», que es de las cosas que nadie mira hasta que un cliente lo dice.
        if (count($encontrados) > 1) {
            throw new \RuntimeException(
                "Dos perfiles reclaman el cliente {$cliente}: ".implode(', ', array_keys($encontrados))
            );
        }

        return $this->perfil = $encontrados === [] ? [] : (array) reset($encontrados);
    }

    /**
     * El cliente sobre cuya base se está sembrando, deducido del nombre de la base.
     *
     * La base se llama `{cliente}_{paquete}` y el subdominio del cliente es su slug con guiones,
     * así que sale del mismo par con el que `subdomainFromHost` resuelve al inquilino en cada
     * petición: una sola regla, no dos que se separen.
     */
    private function clientSlug(): ?string
    {
        $bd = (string) config('database.connections.tenant.database', '');
        $sufijo = '_kilkana';

        if (! str_ends_with($bd, $sufijo)) {
            return null;
        }

        $cliente = str_replace('_', '-', substr($bd, 0, -strlen($sufijo)));

        return CorePackageSelectorSupport::isValidClientSubdomain($cliente) ? $cliente : null;
    }

    /**
     * La URL pública del periódico, deducida de la base sobre la que se está sembrando.
     *
     * **No puede venir del perfil.** Un host escrito en un fichero de datos es el de UN
     * inquilino, y el mismo fichero puede sembrar a varios: el segundo se despertaría con la
     * dirección del primero, que es lo que firma los enlaces del boletín. Aquí llegó a haber
     * `https://demo.kilkana.test` dentro de datos de producto.
     *
     * Si no se puede deducir, null — y `subscriptionLanding()` ya sabe qué hacer con un
     * periódico sin URL puesta.
     */
    private function tenantUrl(): ?string
    {
        $base = (string) config('core.tenant_base_domain', '');
        $cliente = $this->clientSlug();

        return $base !== '' && $cliente !== null ? "https://{$cliente}.{$base}" : null;
    }

    /**
     * Las cuentas de la redacción, sobre el reparto que aplica
     * {@see SnkKilkanaRoleCatalogService}.
     *
     * El reparto no se escribe aquí a propósito: `kilkana:sync-permissions` lo reaplica sobre
     * los periódicos que ya existían cuando cambia, y un despliegue no vuelve a sembrar. Dos
     * copias serían dos escritores de `role_has_permissions` que un día divergen.
     */
    private function seedPermissions(): void
    {
        app(SnkKilkanaRoleCatalogService::class)->sync();

        // Y se suelta la caché persistente del reparto, la misma que borra
        // `kilkana:sync-permissions`: la de consola es `array` y muere con el proceso, así que
        // sin esto los FPM vivos siguen repartiendo el reparto anterior hasta que caduque —con
        // el panel dejando fuera a quien acaba de recibir un permiso—.
        // La clave se compone con el nombre de la base, y `permissionCacheKey` rechaza el que no
        // sea un identificador de inquilino —`:memory:` en pruebas, un nombre con guion—. Se
        // atrapa en vez de repetir aquí su patrón: copiado, el día que Core lo endurezca este
        // seguiría con el viejo y dejaría pasar justo lo que Core ya no acepta.
        $base = (string) config('database.connections.tenant.database', '');
        if (str_ends_with($base, '_kilkana')) {
            try {
                Cache::forget(CorePackageSelectorSupport::permissionCacheKey($base));
            } catch (\RuntimeException) {
                // Sin nombre válido no hay inquilino, y sin inquilino no hay caché suya que soltar.
            }
        }

        $reader = Role::findByName('reader', 'sanctum');

        if ($this->demoData()) {
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
        if ($admin === null && $this->demoData() && app()->environment(['local', 'testing'])) {
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

    /**
     * El árbol de secciones, y **la estructura se sincroniza**.
     *
     * `firstOrCreate` a secas deja una base ya sembrada con lo que se creó el primer día: cuando
     * el catálogo cambia el estado de una sección, la base sigue con el de antes hasta que
     * alguien lo cambia a mano. Un juego de datos que solo estrena un periódico nuevo no es un
     * juego de datos: es una foto del pasado.
     *
     * Lo que se sincroniza es lo que el catálogo declara —el estado, la madre y el sitio en el
     * menú—; lo demás no se toca. Reseedear repara la estructura y no pisa lo escrito encima.
     */
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

            $categoria = SnkKilkanaCategoryModel::firstOrCreate(['slug' => $slug], [
                'name' => $attrs['name'],
                'description' => $attrs['description'] ?? null,
            ]);

            // Va en orden de catálogo, así que la madre existe y ya tiene su estado cuando le
            // toca a la hija: el modelo rechaza una sección más visible que la suya.
            $categoria->fill([
                'parent_id' => $parent ? ($ids[$parent] ?? null) : null,
                'position' => $position[$branch],
                'status' => $attrs['status'] ?? SnkKilkanaCategoryStatusEnum::Live,
            ])->save();

            foreach ($attrs['retired'] ?? [] as $vieja) {
                // La URL que dejó atrás sigue redirigiendo: es lo que la ficha enseña como 301,
                // y sin una fila así esa mitad de la pantalla no se puede ni mirar.
                SnkKilkanaCategorySlugModel::firstOrCreate([
                    'category_id' => $categoria->id,
                    'path' => $vieja,
                ]);
            }

            $ids[$slug] = $categoria->id;
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
            // Por su ruta actual **o por una que dejó atrás**: el snapshot es un archivo viejo y
            // una sección puede haber cambiado de URL desde que se escribió. Es la misma
            // resolución que hace la web pública con un enlace antiguo, y sin ella una sección
            // renombrada dejaba la siembra a medias con un `ModelNotFound`.
            $category = SnkKilkanaCategoryModel::query()
                ->where('slug', $post['cat'])
                ->orWhereHas('retiredSlugs', fn ($vieja) => $vieja->where('path', $post['cat']))
                // Ordenado aunque hoy sea único por construcción: si el muro que impide tomar
                // un slug retirado cambiara alguna vez, esto elegiría una cualquiera sin avisar.
                ->orderBy('id')
                ->firstOrFail();
            $author = $this->authorFor(trim($post['author']));
            $media = ! empty($post['image']) ? $fototeca->forUrl($post['image']) : null;
            $publishAt = $this->publishAt($post);
            $urlDate = $post['status'] === SnkKilkanaPostStatusEnum::Published->value
                ? substr($publishAt, 0, 10)
                : null;

            // La URL es fecha + slug. El snapshot contiene dos slugs repetidos en días
            // distintos y ambos son piezas legítimas, no duplicados que haya que colapsar.
            // La pieza puede haber cambiado de URL desde que se escribió el snapshot: se busca
            // primero por la ruta que dejó atrás, igual que con las secciones. Sin esto, una
            // pieza editada por `seedEditHistory` se intentaba recrear con su slug viejo —que
            // ya está retirado— y la siembra siguiente reventaba a mitad.
            $retirada = $urlDate === null ? null : SnkKilkanaPostSlugModel::query()
                ->where('path', str_replace('-', '/', $urlDate).'/'.$post['slug'])
                ->first();
            $anterior = $retirada === null
                ? null
                : SnkKilkanaPostModel::query()->whereKey($retirada->post_id)->first();

            // **Lo editorial no se reescribe sobre una pieza que ya existe.** El snapshot es la
            // versión con la que nació el archivo; lo que alguien —o el propio seeder, al sembrar
            // el historial de ediciones— haya escrito encima es trabajo posterior, y volver a
            // volcarlo lo revertía: la pieza editada se quedaba con la URL nueva, su 301 y su
            // revisión, y con el titular de antes. Un 301 que apunta a un titular que no cambió
            // es exactamente lo contrario de lo que ese fixture viene a enseñar.
            $editorial = [
                'title' => $post['title'],
                'excerpt' => $post['excerpt'],
                // El snapshot no trae cuerpo y sin cuerpo media demo no significa nada: el
                // artículo público sale en blanco y `content_tier` no corta por ningún sitio
                // porque no hay muro que colocar. Se compone aquí, determinista desde el slug.
                'body_blocks' => SnkKilkanaDemoBodyFactory::blocks($post, $media?->id),
                'seo_title' => $post['seo_title'] ?? null,
                'seo_description' => $post['seo_description'] ?? null,
                'og_title' => $post['og_title'] ?? null,
                'og_description' => $post['og_description'] ?? null,
            ];

            // Y esto sí se sincroniza: es lo que ata la pieza al resto del juego de datos —su
            // sección, su firma, su estado, su fecha— y lo que tiene que seguir cuadrando cuando
            // el catálogo cambia.
            $estructura = [
                'category_id' => $category->id,
                'author_id' => $author->id,
                // Quien la escribió, que NO es lo mismo que quien la firma: el acotado por rol
                // mira esto, no la firma. Una firma sin cuenta —una agencia, la colectiva— no
                // puede haber tecleado nada, así que su pieza la vuelca **la mesa de edición**,
                // que es lo que hace una redacción con un teletipo. Sin eso quedaban 149 piezas
                // que no son de nadie: no las abre ni quien las firma ni quien las volcó.
                'created_by' => $author->user_id ?? $this->deskUserId(),
                'cover_media_id' => $media?->id,
                'publish_at' => $publishAt,
                'status' => $post['status'],
                'content_tier' => $post['content_tier'],
                'featured' => $post['featured'] ?? false,
                'noindex' => $post['noindex'] ?? false,
            ];

            $row = SnkKilkanaPostModel::firstOrCreate([
                'url_date' => $anterior?->getRawOriginal('url_date') ?? $urlDate,
                'slug' => $anterior?->slug ?? $post['slug'],
            ], $editorial + $estructura);

            $row->fill($estructura)->save();

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
