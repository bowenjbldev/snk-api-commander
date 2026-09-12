<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Packages\Kilkana\Services\SnkKilkanaActivityService;

/**
 * El registro guarda claves y no frases.
 *
 * El verbo era la frase en el inglés del panel —«renamed the category», «approved 3 comments
 * on»— y el «sobre qué» a veces también: «3 of 40 routes fail», «a file». Un panel en español
 * tenía que adivinar al leer. Ahora `verb` es una clave, las cifras y los nombres que la frase
 * necesita van en `params`, y `what` guarda solo el nombre de lo tocado, o nada.
 *
 * Reescribe las filas que ya hay con la tabla de `SnkKilkanaActivityService::fromLegacy()`, que es
 * la que se prueba. Idempotente: una fila cuyo verbo ya es una clave no casa con ninguna frase y
 * se queda como está, y la columna solo se añade si falta.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kilkana_activity')) {
            return;
        }
        if (! Schema::hasColumn('kilkana_activity', 'params')) {
            Schema::table('kilkana_activity', function (Blueprint $table) {
                $table->json('params')->nullable()->after('what');
            });
        }

        DB::table('kilkana_activity')
            ->where(fn ($q) => $q->whereIn('verb', array_keys(SnkKilkanaActivityService::LEGACY))->orWhere('verb', 'like', '% on'))
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $key = SnkKilkanaActivityService::fromLegacy($row->verb, $row->what, $row->after);
                    if ($key === null) {
                        continue;
                    }
                    DB::table('kilkana_activity')->where('id', $row->id)->update([
                        'verb' => $key['verb'],
                        'what' => $key['what'],
                        'params' => $key['params'] === null ? null : json_encode($key['params']),
                    ]);
                }
            });
    }

    /** Solo el esquema: las frases no vuelven, y un verbo clave lo sigue leyendo el panel. */
    public function down(): void
    {
        if (Schema::hasColumn('kilkana_activity', 'params')) {
            Schema::table('kilkana_activity', fn (Blueprint $table) => $table->dropColumn('params'));
        }
    }
};
