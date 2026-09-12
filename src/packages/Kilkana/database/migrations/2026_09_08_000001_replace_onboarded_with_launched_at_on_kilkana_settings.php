<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lanzar un periódico deja de ser un `boolean` y pasa a ser **cuándo**.
 *
 * `onboarded` decía que la puesta en marcha había pasado; `launched_at` dice qué día abrió al
 * público, que es lo que la pantalla de ajustes enseña —«Public since»— y lo que la calle mira
 * para servir la página de «pronto» mientras esté nulo. Un hecho que ocurre una vez y no se
 * deshace se guarda con su fecha: del flag no se puede sacar la fecha, y de la fecha sí se saca
 * el flag.
 *
 * **El sello de lo ya lanzado es `created_at` de la propia fila, no `now()`.** Es la fecha más
 * cercana a la verdad que hay en la tabla —la fila de ajustes nace con el periódico— y `now()`
 * diría que un periódico con dos años de hemeroteca abrió el día de la migración, que es
 * justo el dato que esa pantalla publica.
 *
 * Va como migración aparte y no editando la que creó la tabla, que es la regla del repo: la
 * `000018` ya está aplicada en las bases vivas, y reescribirla solo cambiaría las que nacen de
 * cero, como las de la suite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kilkana_settings', function (Blueprint $table) {
            $table->timestamp('launched_at')->nullable()->after('social');
        });

        // Los dos pasos, en este orden: primero el dato y después la columna vieja, para que un
        // fallo a mitad deje la tabla con las dos y no sin ninguna.
        DB::table('kilkana_settings')
            ->where('onboarded', true)
            ->update(['launched_at' => DB::raw('created_at')]);

        Schema::table('kilkana_settings', function (Blueprint $table) {
            $table->dropColumn('onboarded');
        });
    }

    public function down(): void
    {
        Schema::table('kilkana_settings', function (Blueprint $table) {
            $table->boolean('onboarded')->default(false);
        });

        // Al revés se pierde el día y queda el hecho: el flag, sin fecha.
        DB::table('kilkana_settings')->whereNotNull('launched_at')->update(['onboarded' => true]);

        Schema::table('kilkana_settings', function (Blueprint $table) {
            $table->dropColumn('launched_at');
        });
    }
};
