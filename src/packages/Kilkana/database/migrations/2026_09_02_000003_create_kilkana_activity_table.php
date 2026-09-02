<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El registro de lo que ha pasado en la redacción: quién hizo qué, sobre qué y cuándo.
 *
 * Quién se guarda dos veces a propósito: `user_id` para filtrar y `actor_name` con su
 * `actor_role` tal como eran ese día, porque una cuenta que se retira o cambia de papel no
 * puede reescribir lo que firmó. Sin `user_id` es la máquina: el programador que publica a su
 * hora. `before` y `after` solo cuando el movimiento fue sustituir un valor por otro.
 *
 * Es una línea de tiempo y se lee por fecha: el índice es `created_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kilkana_activity', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name', 120);
            $table->string('actor_role', 60)->nullable();
            $table->string('kind', 24);
            $table->string('verb', 120);
            $table->string('what', 500);
            $table->string('before', 255)->nullable();
            $table->string('after', 255)->nullable();
            $table->string('href', 160);
            $table->timestamp('created_at');
            $table->index('created_at');
            $table->index(['kind', 'created_at']);
            // La persona se filtra y se lista por nombre, y la tabla solo crece.
            $table->index('actor_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kilkana_activity');
    }
};
