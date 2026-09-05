<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kilkana_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('tenant_name')->nullable();
            $table->string('tenant_tagline')->nullable();
            $table->string('tenant_city')->nullable();
            $table->string('tenant_url')->nullable();
            $table->boolean('onboarded')->default(false);
            $table->timestamps();
        });

        // La fila única la garantiza MariaDB; el SQLite de la suite no valida DDL y, según su
        // versión, ni siquiera admite añadir la restricción a una tabla ya creada.
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE kilkana_settings ADD CONSTRAINT kilkana_settings_singleton CHECK (id = 1)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kilkana_settings');
    }
};
