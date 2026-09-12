<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Singleton: una sola fila ('operator') con el perfil + preferencias del operador.
        // Solo en la BD commander. El payload es una bolsa JSON schemaless
        // (operator_name/email, default_grace_hours, notify_*) — añadir preferencias no pide migración.
        // La API la expone PLANA (el controller despliega/mergea con defaults).
        Schema::create('commander_settings', function (Blueprint $table) {
            $table->id(); // singleton: siempre la fila id=1
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commander_settings');
    }
};
