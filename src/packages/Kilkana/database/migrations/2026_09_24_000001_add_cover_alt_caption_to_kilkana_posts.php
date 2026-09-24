<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El alt y el pie de la portada **en esta pieza**.
 *
 * Nulos heredan los de la ficha de la mediateca, y quien pinta resuelve la herencia: la API
 * devuelve lo guardado, igual que el `config.caption` de un bloque de imagen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kilkana_posts', function (Blueprint $table) {
            $table->string('cover_alt', 500)->nullable()->after('cover_media_id');
            $table->string('cover_caption', 500)->nullable()->after('cover_alt');
        });
    }

    public function down(): void
    {
        Schema::table('kilkana_posts', function (Blueprint $table) {
            $table->dropColumn(['cover_alt', 'cover_caption']);
        });
    }
};
