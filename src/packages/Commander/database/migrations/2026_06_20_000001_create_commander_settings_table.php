<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Singleton: una sola fila ('operator') con el perfil + preferencias del operador.
        // Solo en la BD commander (el panel corre ahi). default_grace_hours alimenta el grace real.
        Schema::create('commander_settings', function (Blueprint $table) {
            $table->id(); // singleton: siempre la fila id=1
            $table->unsignedSmallInteger('default_grace_hours')->default(72);
            $table->string('operator_name')->nullable();
            $table->string('operator_email')->nullable();
            $table->boolean('notify_on_suspend')->default(true);
            $table->boolean('notify_on_overdue')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commander_settings');
    }
};
