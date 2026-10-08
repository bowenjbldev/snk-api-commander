<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Por dónde va la puesta en marcha: el paso del asistente, la clase de diario que se eligió y si
 * se acabó o se dejó para luego. Es del periódico y no de la cuenta: quien entre a mitad sigue
 * donde lo dejó el anterior. `launched_at` sigue siendo lo único que abre la calle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kilkana_settings', function (Blueprint $table) {
            $table->json('onboarding')->nullable()->after('launched_at');
        });
    }

    public function down(): void
    {
        Schema::table('kilkana_settings', function (Blueprint $table) {
            $table->dropColumn('onboarding');
        });
    }
};
