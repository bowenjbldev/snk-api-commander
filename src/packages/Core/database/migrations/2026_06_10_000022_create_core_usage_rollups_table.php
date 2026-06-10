<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('core_usage_rollups', function (Blueprint $table) {
            $table->id();
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

            $table->unique(
                ['period', 'period_start', 'route', 'method', 'status_class'],
                'core_usage_rollups_bucket_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_usage_rollups');
    }
};
