<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rollup DIARIO de actividad git por package: una fila por (package, dia). Alimenta directo el
 * heatmap tipo ContributionCalendar (intensidad por dia) y el chart de code frequency. Single-dev:
 * sin dimension de contributor. Lo escribe `commander:git:sync` agregando commander_package_commits,
 * igual que commander_usage_rollups agrega commander_request_logs (upsert idempotente por bucket).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('commander_package_activity_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained('commander_packages')->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('commit_count')->default(0);
            $table->unsignedInteger('additions')->default(0);
            $table->unsignedInteger('deletions')->default(0);
            $table->unsignedInteger('files_changed')->default(0);
            $table->timestamps();

            // Una fila por (package, dia): clave del upsert idempotente del rollup.
            $table->unique(['package_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commander_package_activity_daily');
    }
};
