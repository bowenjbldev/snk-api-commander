<?php

namespace Packages\Snk\Kilkana\Services;

use Illuminate\Support\Facades\DB;
use Packages\Commander\Models\CommanderPackageModel;

/**
 * Corre un trabajo dentro de CADA Kilkana aprovisionado, y devuelve la conexion como estaba.
 *
 * Es el mismo patron de conmutacion que `CoreResolvePackageFromHostMiddleware`, pero
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
        $instances = CommanderPackageModel::query()
            ->provisioned()
            ->where('slug', 'kilkana')
            ->whereNotNull('database')
            ->get(['database', 'database_username', 'database_password']);

        $default = DB::getDefaultConnection();
        $tenant = config('database.connections.tenant');
        $results = [];
        $errors = [];

        try {
            foreach ($instances as $instance) {
                try {
                    config([
                        'database.connections.tenant.database' => $instance->database,
                        'database.connections.tenant.username' => $instance->database_username,
                        'database.connections.tenant.password' => $instance->database_password,
                    ]);
                    DB::purge('tenant');
                    DB::setDefaultConnection('tenant');

                    $results[$instance->database] = $work($instance->database);
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
}
