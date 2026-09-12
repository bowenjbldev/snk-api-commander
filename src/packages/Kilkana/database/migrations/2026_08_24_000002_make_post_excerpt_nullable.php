<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La entradilla pasa a admitir nulo: un borrador nace sin ella.
 *
 * El titular llega antes que el texto —esa es la forma real de dar de alta una pieza— y una
 * columna obligatoria fuerza a inventarse una entradilla en el peor momento. La regla se
 * aplica donde importa: `required_if` la exige al pasar a `scheduled` o `published`, que
 * es cuando publicar sin ella sería publicar media pieza.
 *
 * Nulo aquí significa «todavía no escrita», que es distinto de la cadena vacía —«escrita y
 * en blanco»—, y es la misma lectura que tiene `url_date` en esta tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kilkana_posts', function (Blueprint $table) {
            $table->text('excerpt')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Volver atrás exige que no quede ninguna nula, o la columna no puede recuperar el
        // NOT NULL y la migración revienta con la primera fila. Se rellenan en blanco: es la
        // única lectura reversible sin inventar texto, y va ANTES del `change()`.
        DB::table('kilkana_posts')->whereNull('excerpt')->update(['excerpt' => '']);

        Schema::table('kilkana_posts', function (Blueprint $table) {
            $table->text('excerpt')->nullable(false)->change();
        });
    }
};
