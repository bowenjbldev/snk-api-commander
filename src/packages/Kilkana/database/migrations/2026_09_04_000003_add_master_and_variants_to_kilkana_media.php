<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El máster y la huella de las derivadas. El máster es el fichero del que salen todas las
 * versiones publicadas —orientado, sin metadatos y acotado—; la huella cambia con el contenido
 * y con el encuadre, y es lo que aparece en la URL de cada derivada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kilkana_media', function (Blueprint $table) {
            $table->string('master_path', 1024)->nullable()->after('path');
            $table->unsignedInteger('master_width')->nullable()->after('master_path');
            $table->unsignedInteger('master_height')->nullable()->after('master_width');
            $table->char('variants_key', 8)->nullable()->after('crop_h');
        });
    }

    public function down(): void
    {
        Schema::table('kilkana_media', function (Blueprint $table) {
            $table->dropColumn(['master_path', 'master_width', 'master_height', 'variants_key']);
        });
    }
};
