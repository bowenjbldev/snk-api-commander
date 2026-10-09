<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La plantilla de pieza de una sección: con cuál se maquetan las piezas que archiva.
 *
 * Nula, la sección no elige: sus piezas llevan la de la sección madre y, sin madre o sin que
 * esta elija, la de noticia. Una pieza puede fijar la suya en `presentation.template`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kilkana_categories', function (Blueprint $table) {
            $table->string('template', 40)->nullable()->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('kilkana_categories', function (Blueprint $table) {
            $table->dropColumn('template');
        });
    }
};
