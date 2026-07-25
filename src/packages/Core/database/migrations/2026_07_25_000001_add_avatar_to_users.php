<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Foto de perfil del usuario. Se guarda la URL, no el fichero: puede venir del disco
        // del tenant (/storage/avatars/…) o de un proveedor externo. Nullable porque la UI
        // siempre tiene que poder caer al monograma de iniciales.
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar', 2048)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar');
        });
    }
};
