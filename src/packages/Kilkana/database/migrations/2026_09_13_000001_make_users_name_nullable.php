<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una cuenta retirada se queda sin nombre.
 *
 * Retirar a un lector borra lo que lo identifica y deja sus comentarios publicados. El nombre
 * queda vacío en vez de llevar un rótulo guardado, porque quién firma un comentario sin dueño lo
 * dice cada lado en su idioma: la calle en el del periódico y el panel en el de quien lo mira.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable(false)->change();
        });
    }
};
