<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que la pantalla de ajustes edita y la calle lee, además del nombre: el idioma y la zona
 * horaria del periódico, si se deja indexar, a quién contestan los lectores del boletín, y
 * dos grupos abiertos —`legal` y `social`— como JSON normalizado al escribir, que es la regla
 * de crecimiento de esta tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kilkana_settings', function (Blueprint $table) {
            $table->string('lang', 8)->default('es-ES')->after('tenant_url');
            $table->string('tz', 64)->default('Europe/Madrid')->after('lang');
            $table->boolean('search_visible')->default(true)->after('tz');
            $table->string('newsletter_reply_to')->nullable()->after('search_visible');
            $table->json('legal')->nullable()->after('newsletter_reply_to');
            $table->json('social')->nullable()->after('legal');
        });
    }

    public function down(): void
    {
        Schema::table('kilkana_settings', function (Blueprint $table) {
            $table->dropColumn(['lang', 'tz', 'search_visible', 'newsletter_reply_to', 'legal', 'social']);
        });
    }
};
