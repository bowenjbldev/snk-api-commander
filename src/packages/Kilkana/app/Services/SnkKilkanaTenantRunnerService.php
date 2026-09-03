<?php

namespace Packages\Kilkana\Services;

use Illuminate\Support\Facades\DB;
use Packages\Commander\Models\CommanderInstanceModel;
use Packages\Core\Support\CorePackageSelectorSupport;

/**
 * Corre un trabajo dentro de CADA Kilkana aprovisionado, y devuelve la conexion como estaba.
 *
 * Es el mismo patron de conmutacion que `CoreResolveInstanceFromHostMiddleware`, pero
 * recorriendo el registro del Commander en vez de resolver por host: un comando de consola
 * no tiene peticion de la que sacar el subdominio.
 *
 * Estaba copiado dentro de los dos comandos, y las dos copias ya habian DIVERGIDO: una
 * guardaba tres claves sueltas de config y la otra el array entero, que es la unica que
 * devuelve tambien lo que no se toco. Es estado global restaurado en un `finally`, o sea el
 * sitio exacto donde una tercera copia habria dejado al proceso hablando con la base
 * equivocada.
 *
 * Lo que va DENTRO del `$work` es la regla editorial y no vive aqui: esto solo sabe de
 * conexiones.
 */
class SnkKilkanaTenantRunnerService
{
    /**
     * @param  callable(string $database): mixed  $work  Se ejecuta con la conexion ya conmutada.
     * @return array{tenants: int, results: array<string, mixed>, errors: array<string, string>}
     */
    public function each(callable $work): array
    {
        $instances = CommanderInstanceModel::query()
            ->ready()
            ->whereHas('application', fn ($query) => $query->where('slug', 'kilkana'))
            ->whereNotNull('database')
            ->whereHas('client', fn ($query) => $query->where('status', 'active'))
            ->where(function ($query) {
                $query->where('service_status', 'active')
                    ->orWhere(function ($query) {
                        $query->where('service_status', 'grace')
                            ->where(fn ($query) => $query
                                ->whereNull('grace_until')
                                ->orWhere('grace_until', '>', now()));
                    });
            })
            ->get(['database', 'database_username', 'database_password']);

        $default = DB::getDefaultConnection();
        $tenant = config('database.connections.tenant');
        $results = [];
        $errors = [];

        try {
            foreach ($instances as $instance) {
                try {
                    $database = CorePackageSelectorSupport::validatedDatabaseIdentifier($instance->database);
                    $username = CorePackageSelectorSupport::validatedDatabaseIdentifier($instance->database_username);

                    if (empty($instance->database_password)) {
                        throw new \RuntimeException('Tenant database password is unavailable.');
                    }

                    config([
                        'database.connections.tenant.database' => $database,
                        'database.connections.tenant.username' => $username,
                        'database.connections.tenant.password' => $instance->database_password,
                    ]);
                    DB::purge('tenant');
                    DB::setDefaultConnection('tenant');

                    $results[$database] = $work($database);
                } catch (\Throwable $e) {
                    // Un tenant roto no puede parar a los demas: se anota y se sigue.
                    report($e);
                    $errors[$instance->database] = $e->getMessage();
                }
            }
        } finally {
            DB::purge('tenant');
            config(['database.connections.tenant' => $tenant]);
            DB::setDefaultConnection($default);
        }

        return ['tenants' => $instances->count(), 'results' => $results, 'errors' => $errors];
    }

    /**
     * Un solo inquilino, por su base: lo que necesita un trabajo de cola, que nace en una
     * petición de un periódico y corre después en un proceso que no sabe de ninguno. Deja la
     * conexión como estaba al salir, pase lo que pase.
     */
    public function on(string $database, callable $work): mixed
    {
        $instance = CommanderInstanceModel::query()
            ->where('database', $database)
            ->whereNotNull('database')
            ->first(['database', 'database_username', 'database_password']);
        if ($instance === null) {
            throw new \RuntimeException("Tenant [{$database}] is not provisioned.");
        }

        $default = DB::getDefaultConnection();
        $tenant = config('database.connections.tenant');
        try {
            $name = CorePackageSelectorSupport::validatedDatabaseIdentifier($instance->database);
            $username = CorePackageSelectorSupport::validatedDatabaseIdentifier($instance->database_username);
            if (empty($instance->database_password)) {
                throw new \RuntimeException('Tenant database password is unavailable.');
            }
            config([
                'database.connections.tenant.database' => $name,
                'database.connections.tenant.username' => $username,
                'database.connections.tenant.password' => $instance->database_password,
            ]);
            DB::purge('tenant');
            DB::setDefaultConnection('tenant');

            return $work($name);
        } finally {
            DB::purge('tenant');
            config(['database.connections.tenant' => $tenant]);
            DB::setDefaultConnection($default);
        }
    }
}
