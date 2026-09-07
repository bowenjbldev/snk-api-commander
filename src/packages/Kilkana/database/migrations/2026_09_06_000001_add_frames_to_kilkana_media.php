<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El encuadre propio de una proporción: `{ "portrait": {x, y, w, h}, … }` en fracciones del
 * máster. Sin entrada, la proporción sigue al marco y al punto compartidos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kilkana_media', function (Blueprint $table) {
            $table->json('frames')->nullable()->after('crop_h');
        });
    }

    public function down(): void
    {
        Schema::table('kilkana_media', function (Blueprint $table) {
            $table->dropColumn('frames');
        });
    }
};
