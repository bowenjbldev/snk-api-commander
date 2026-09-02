<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las lecturas medidas de cada cuenta: qué pieza con muro abrió cada lector y en qué mes.
 *
 * Es la tabla que sostiene el contador de «lecturas medidas del mes» de una cuenta, que es lo
 * que el muro consume: pasadas las del mes, una pieza medida pide suscripción. Una fila por
 * cuenta, pieza y mes, y no un contador: releer la misma pieza no gasta otra lectura, y el mes
 * que viene vuelve a contar desde cero. `period` es el primer día del mes de la lectura.
 *
 * Solo entran piezas medidas: una gratuita no consume nada y una cerrada no se abre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kilkana_reader_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('post_id')->constrained('kilkana_posts')->cascadeOnDelete();
            $table->date('period');
            $table->timestamp('read_at');
            $table->unique(['user_id', 'post_id', 'period']);
            $table->index(['user_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kilkana_reader_reads');
    }
};
