<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('commander_request_logs', function (Blueprint $table) {
            $table->id();
            // Package que sirvio el request (resuelto por subdominio). Index, sin FK:
            // tabla write-heavy, integridad referencial menos critica que el coste por insert.
            $table->foreignId('package_id')->nullable()->index();
            // user_id/token_id viven en la BD del tenant (sin FK cross-BD): identidad del autor.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('token_id')->nullable();
            $table->string('method', 8);
            $table->string('route', 191)->nullable();
            $table->string('path', 255)->nullable();
            $table->unsignedSmallInteger('status');
            $table->unsignedInteger('duration_ms');
            $table->string('ip', 45)->nullable();
            $table->unsignedBigInteger('request_bytes')->nullable();
            $table->unsignedBigInteger('response_bytes')->nullable();
            $table->unsignedInteger('memory_kb')->nullable();
            // created_at = sello del request; indexado para el barrido del rollup y la poda por retencion.
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commander_request_logs');
    }
};
