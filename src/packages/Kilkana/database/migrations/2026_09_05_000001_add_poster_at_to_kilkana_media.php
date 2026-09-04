<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** El segundo del vídeo que es su portada: elegido por la redacción, o el uno por defecto. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kilkana_media', function (Blueprint $table) {
            $table->unsignedInteger('poster_at')->nullable()->after('master_height');
        });
    }

    public function down(): void
    {
        Schema::table('kilkana_media', function (Blueprint $table) {
            $table->dropColumn('poster_at');
        });
    }
};
