<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El resultado de la última pasada del output gate, por URL pública.
 *
 * El gate lo corre la salida pública —es quien sirve el HTML y quien lo lee—; aquí solo se
 * guarda el veredicto: qué invariantes pasa cada ruta y cuándo se miró. `checked_at` nulo es
 * una ruta descubierta pero nunca construida, que no es lo mismo que fallar. Cada pasada
 * sustituye la tabla entera: las rutas se descubren de nuevo cada vez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kilkana_gate_results', function (Blueprint $table) {
            $table->id();
            $table->string('url', 500);
            $table->string('kind', 32);
            $table->json('passing')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->unique('url');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kilkana_gate_results');
    }
};
