<?php

namespace Packages\Kilkana\Services;

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * El reparto de poder de una redacción: qué permisos existen y qué lleva cada rol.
 *
 * Vive aquí y no dentro del seeder porque tiene **dos llamadores y una sola verdad**: el
 * seeder lo aplica al nacer un periódico, y `kilkana:sync-permissions` lo reaplica sobre los
 * que ya existen cuando el reparto cambia. Escrito dos veces serían dos escritores de
 * `role_has_permissions` que un día divergen, y el que perdiera sería el que no se ejecutó.
 *
 * El CRUD por recurso no describe un periódico: funde en un solo `update posts` tres poderes
 * que allí están repartidos —escribir, publicar y decidir la portada—. Por eso hay tres
 * permisos que no salen del CRUD:
 *
 * - `publish posts`: pasar una pieza a publicada o programada. Quien la firma no decide
 *   cuándo sale; sin este permiso solo puede dejarla en borrador o «en revisión».
 * - `edit others posts`: tocar lo que no has escrito. Sin él, listado, editor y borrado se
 *   acotan a `created_by` — el muro entre plantilla y colaboración externa.
 * - `view others posts`: **saber que existe** lo que no has escrito, que es otra pregunta.
 *   Abre el sumario de la redacción —cuánto hay sin publicar y en qué fase— sin dar acceso a
 *   una sola letra ajena. Lo tienen los cinco de dentro de la sala; quien entrega desde fuera
 *   ve lo suyo y ya.
 *
 * **`edit others posts` implica ver, y no hace falta declararlo.** Quien edita lo ajeno lo
 * tiene delante en el listado, así que `SnkKilkanaPostService::countsByStatus()` le da las dos
 * escalas iguales sin mirar el segundo permiso. Se le concede igual a los tres roles que lo
 * tienen para que el reparto se lea entero en una columna: un `director` sin
 * `view others posts` funcionaría, y el día que alguien le quite el de editar perdería el
 * sumario sin que nadie lo hubiera decidido.
 *
 * `readers` es un recurso aparte de `comments` porque bloquear a una persona no es moderar
 * una frase: la mesa de edición modera y no bloquea, y esa es la diferencia entre `editor` y
 * `moderador` que la matriz del panel enseña. Y aparte de `subscribers`, porque una cuenta
 * lectora y un correo del boletín son dos listas que se cruzan y no se contienen.
 *
 * La portada NO necesita permiso propio: componerla ya es `update layouts`, y separarla de
 * `update posts` es justo lo que distingue a un editor de mesa de la dirección.
 */
class SnkKilkanaRoleCatalogService
{
    /** Los que abren el panel y ocupan asiento, en el orden en que la matriz los pinta. */
    public const PANEL_ROLES = ['admin', 'director', 'editor', 'redactor', 'colaborador', 'moderador'];

    /** Los que tienen cuenta y no asiento: un lector comenta, y una cuenta de servicio no es nadie. */
    public const WITHOUT_SEAT = ['reader', 'service'];

    /**
     * Aplica el reparto sobre la conexión activa. Idempotente: repetirlo no cambia nada.
     *
     * @return array{permissions: int, roles: int}
     */
    public function sync(): array
    {
        $names = [];
        foreach (['posts', 'categories', 'authors', 'media', 'tags', 'comments', 'readers', 'subscribers', 'layouts', 'settings', 'themes'] as $resource) {
            foreach (['index', 'show', 'create', 'update', 'delete'] as $action) {
                $names[] = "$action $resource";
            }
        }
        $names[] = 'publish posts';
        $names[] = 'edit others posts';
        $names[] = 'view others posts';
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
        // (no `give`) para que reaplicarlo REPARE un rol tocado a mano.
        $roles = [
            // Dirección editorial: manda en el periódico entero, incluida la portada y la
            // identidad. No administra la instalación (usuarios) — eso es otro oficio.
            'director' => [
                ...$crud('posts', 'categories', 'authors', 'media', 'tags', 'comments', 'readers', 'subscribers', 'layouts', 'settings', 'themes'),
                'publish posts', 'edit others posts', 'view others posts',
            ],
            // Mesa de edición: publica y corrige lo de cualquiera, modera y gestiona el
            // material. NO compone la portada ni cambia la identidad del periódico.
            'editor' => [
                ...$crud('posts', 'media', 'tags', 'comments'),
                ...$read('categories', 'authors'),
                'publish posts', 'edit others posts', 'view others posts',
            ],
            // Plantilla: escribe lo suyo y lo manda a revisión. NO publica: la diferencia
            // real con un externo es que borra lo suyo, ve el sumario y clasifica.
            'redactor' => [
                ...$crud('posts'),
                ...$crud('media'),
                'index tags', 'create tags',
                ...$read('categories', 'authors', 'comments'),
                // Escribe lo suyo, pero está en la reunión de sumario: ve en qué anda el resto
                // sin poder tocarlo. Aquí el permiso SÍ decide algo, porque no hay implicación
                // que lo cubra.
                'view others posts',
            ],
            // Colaborador externo: escribe lo suyo y lo deja «en revisión». No publica y no
            // borra: una pieza entregada no la retira quien la escribió. Y no ve el sumario:
            // es el único rol de fuera de la redacción.
            'colaborador' => [
                'index posts', 'show posts', 'create posts', 'update posts',
                'index media', 'show media', 'create media',
                'index tags',
                ...$read('categories', 'authors'),
            ],
            // Comunidad: comentarios, lectores y boletín. Ni un permiso sobre artículos, que
            // es justo por lo que este rol existe.
            'moderador' => [
                ...$crud('comments', 'readers', 'subscribers'),
                ...$read('posts'),
                // Está en la casa, así que ve el sumario. Por necesidad no le hace falta
                // —modera comentarios de piezas ya publicadas—, pero dejarlo fuera
                // reintroduce la excepción por rol que este permiso viene a quitar.
                'view others posts',
            ],
        ];
        foreach ($roles as $name => $permissions) {
            Role::findOrCreate($name, 'sanctum')->syncPermissions($permissions);
        }

        // Lector registrado: solo puede comentar (la lectura pública no exige auth).
        Role::findOrCreate('reader', 'sanctum')->givePermissionTo('create comments');

        return ['permissions' => count($names), 'roles' => count($roles) + 2];
    }
}
