<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commander_plan_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('commander_plans')->cascadeOnDelete();
            $table->foreignId('metric_id')->constrained('commander_application_metrics')->restrictOnDelete();
            $table->unsignedBigInteger('limit_value')->nullable();
            $table->unsignedTinyInteger('warning_percent')->default(80);
            $table->string('action', 16)->default('none');
            $table->unsignedTinyInteger('consecutive_periods')->default(1);
            $table->timestamps();

            $table->unique(['plan_id', 'metric_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commander_plan_limits');
    }
};
