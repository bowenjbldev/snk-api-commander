<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commander_metric_measurements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quota_id')->constrained('commander_quotas')->cascadeOnDelete();
            $table->date('bucket_date');
            $table->unsignedBigInteger('value');
            $table->timestamp('measured_at');
            $table->timestamps();

            $table->unique(['quota_id', 'bucket_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commander_metric_measurements');
    }
};
