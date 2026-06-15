<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local cache of each deploy's hourly usage (one row per deployment+period+bucket). Seeded for an
 * out-of-the-box dashboard; overwritten with real rollups when an operator runs `sync` on a live
 * deploy. Powers the platform charts on Dashboard/Usage without N live /control/usage calls.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('commander_usage_snapshots', function (Blueprint $table) {
            $table->string('id', 96)->primary(); // "${deployment_id}:${period}:${epoch}"
            $table->string('deployment_id', 64);
            $table->string('period', 8); // hour|day
            $table->timestamp('period_start');
            $table->unsignedBigInteger('request_count')->default(0);
            $table->unsignedBigInteger('error_count')->default(0);
            $table->timestamps();

            $table->index('deployment_id');
            $table->index(['period', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commander_usage_snapshots');
    }
};
