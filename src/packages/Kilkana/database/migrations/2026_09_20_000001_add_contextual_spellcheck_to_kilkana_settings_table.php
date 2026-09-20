<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kilkana_settings', function (Blueprint $table) {
            // Apagado de serie: enciende el envío de párrafos con erratas a un proveedor externo,
            // y eso lo decide cada periódico, no la instalación.
            $table->boolean('contextual_spellcheck')->default(false)->after('search_visible');
        });
    }

    public function down(): void
    {
        Schema::table('kilkana_settings', function (Blueprint $table) {
            $table->dropColumn('contextual_spellcheck');
        });
    }
};
