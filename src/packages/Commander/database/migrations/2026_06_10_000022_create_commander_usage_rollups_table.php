<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('commander_usage_rollups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->nullable()->constrained('commander_packages')->cascadeOnDelete();
            $table->string('period', 8); // hour|day
            $table->timestamp('period_start');
            // '' como centinela de "sin ruta" (404): NULL rompe la unique en MySQL
            $table->string('route', 191)->default('');
            $table->string('method', 8);
            $table->string('status_class', 3); // 2xx|3xx|4xx|5xx
            $table->unsignedBigInteger('request_count');
            $table->unsignedBigInteger('error_count');
            $table->decimal('avg_duration_ms', 10, 2);
            $table->unsignedInteger('p95_duration_ms');
            $table->unsignedBigInteger('total_request_bytes')->default(0);
            $table->unsignedBigInteger('total_response_bytes')->default(0);
            $table->unsignedInteger('unique_users')->default(0);
            $table->timestamps();

            // package_id en la unique: dos packages con rutas identicas no deben colisionar en el upsert.
            $table->unique(
                ['package_id', 'period', 'period_start', 'route', 'method', 'status_class'],
                'commander_usage_rollups_bucket_unique'
            );

            // Sirve el chart de plataforma (GET /usage/platform): GROUP BY package_id, period_start
            // filtrado por period + period_start (la unique empieza por package_id, no cubre este filtro).
            $table->index(['period', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commander_usage_rollups');
    }
};
