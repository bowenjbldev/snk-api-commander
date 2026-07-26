<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perfil del OPERADOR sobre la `users` de Laravel.
 *
 * Core crea la tabla tal cual la trae el framework; lo que necesita el panel de operador lo
 * anade este package. Son las tres que aceptan las reglas de `CoreUsersController`, del que
 * cuelga `CommanderUsersController`. Sin avatar: el operador no publica nada y su ficha se
 * dibuja con el monograma de iniciales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('full_name', 255)->nullable()->after('name');
            $table->string('company', 255)->nullable()->after('full_name');
            $table->string('phone', 50)->nullable()->after('company');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['full_name', 'company', 'phone']);
        });
    }
};
