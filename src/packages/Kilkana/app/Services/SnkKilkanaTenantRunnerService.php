<?php

namespace Packages\Kilkana\Services;

use Illuminate\Support\Facades\DB;
use Packages\Commander\Models\CommanderInstanceModel;
use Packages\Core\Support\CorePackageSelectorSupport;

/**
 * Corre un trabajo dentro de CADA Kilkana aprovisionado, y devuelve la conexión como estaba.
 *
 * Es el mismo patrón de conmutación que `CoreResolveInstanceFromHostMiddleware`, pero
 * recorriendo el registro del Commander en vez de resolver por host: un comando de consola
 * no tiene petición de la que sacar el subdominio.
 *
 * Vive en un solo sitio y no copiado en cada comando porque es estado global restaurado en un
 * `finally`: guarda y devuelve el array entero de la conexión, también lo que el trabajo no
 * toca, y una copia que guardara solo unas claves sueltas dejaría al proceso hablando con la
 * base equivocada.
 *
 * Lo que va DENTRO del `$work` es la regla editorial y no vive aquí: esto solo sabe de
 * conexiones.
 */
class SnkKilkanaTenantRunnerService
{
    /**
     * @param  callable(string $database, string $client): mixed  $work  Se ejecuta con la conexión ya conmutada.
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
            ->with('client:id,slug')
            ->get(['database', 'database_username', 'database_password', 'client_id']);

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

                    // El trabajo recibe la base y el subdominio del cliente: lo segundo lo necesita
                    // quien construye URLs o encola para volver a este periódico.
                    $results[$database] = $work($database, (string) ($instance->client?->slug ?? ''));
                } catch (\Throwable $e) {
                    // Un tenant roto no puede parar a los demás: se anota y se sigue.
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
