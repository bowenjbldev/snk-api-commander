<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perfil del OPERADOR sobre la `users` de Laravel.
 *
 * Core crea la tabla tal cual la trae el framework; este package añade los campos del operador
 * aceptados por `CoreUsersController`, del que cuelga `CommanderUsersController`.
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
