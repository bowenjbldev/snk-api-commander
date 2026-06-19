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
            // occurred_at indexado: sirve tanto al barrido del rollup como a la poda por retencion.
            $table->timestamp('occurred_at')->index();
            $table->string('method', 8);
            $table->string('route', 191)->nullable();
            $table->string('path', 255)->nullable();
            $table->unsignedSmallInteger('status');
            $table->unsignedInteger('duration_ms');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('token_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->unsignedBigInteger('request_bytes')->nullable();
            $table->unsignedBigInteger('response_bytes')->nullable();
            $table->unsignedInteger('memory_kb')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commander_request_logs');
    }
};
