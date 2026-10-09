<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cómo sale la pieza **donde se aparta de su tema**: la forma y el tamaño de su foto de apertura.
 *
 * Un objeto y no una columna por dato: lo que una pieza puede decidir de su página crecerá, y cada
 * clave nueva es una regla en el controlador y no otra migración. Nulo, o sin una clave, la pieza
 * sigue lo que ponga el tema, y quien pinta resuelve esa herencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kilkana_posts', function (Blueprint $table) {
            $table->json('presentation')->nullable()->after('cover_caption');
        });
    }

    public function down(): void
    {
        Schema::table('kilkana_posts', function (Blueprint $table) {
            $table->dropColumn('presentation');
        });
    }
};
