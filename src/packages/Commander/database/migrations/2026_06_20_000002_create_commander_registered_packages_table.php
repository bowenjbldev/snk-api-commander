<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Registro persistente de packages de negocio (tipos). Se sincroniza desde el disco
        // (packages/*, que viaja por git) pero guarda metadatos editables del operador. Permite
        // que un package exista y tenga planes ANTES de desplegarse. Solo en la BD commander.
        Schema::create('commander_registered_packages', function (Blueprint $table) {
            $table->string('name', 100)->primary(); // StudlyCase, = directorio en packages/
            $table->string('label')->nullable();
            $table->text('description')->nullable();
            $table->string('status', 16)->default('active'); // active|inactive (visible en catalogo)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commander_registered_packages');
    }
};
