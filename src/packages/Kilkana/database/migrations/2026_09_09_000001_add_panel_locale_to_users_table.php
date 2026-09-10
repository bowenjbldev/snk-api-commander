<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * En que idioma ve el panel cada persona.
 *
 * Va en la cuenta y no en los ajustes de la cabecera porque el panel lo lee una persona y el
 * periodico lo lee el publico: una redaccion mixta tiene a quien edita en castellano y a quien
 * edita en ingles sobre el mismo diario. `kilkana_settings.lang` sigue siendo otra cosa, y es el
 * idioma de lo que se publica.
 *
 * Nulo significa «lo que diga el navegador»: es el valor con el que nace toda cuenta, y la unica
 * forma de distinguir a quien nunca eligio de quien eligio el idioma por defecto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('panel_locale', 5)->nullable()->after('activity_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('panel_locale');
        });
    }
};
