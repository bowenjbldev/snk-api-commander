<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commander_quotas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instance_id')->constrained('commander_instances')->cascadeOnDelete();
            $table->foreignId('metric_id')->constrained('commander_application_metrics')->restrictOnDelete();
            $table->unsignedBigInteger('current_value')->default(0);
            $table->unsignedBigInteger('limit_value')->nullable();
            $table->unsignedTinyInteger('warning_percent')->default(80);
            $table->string('action', 16)->default('none');
            $table->unsignedTinyInteger('consecutive_periods')->default(1);
            $table->unsignedTinyInteger('consecutive_exceeded')->default(0);
            $table->timestamp('threshold_reached_at')->nullable();
            $table->timestamp('exceeded_at')->nullable();
            $table->timestamp('upgrade_recommended_at')->nullable();
            $table->timestamp('measured_at')->nullable();
            $table->timestamps();

            $table->unique(['instance_id', 'metric_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commander_quotas');
    }
};
