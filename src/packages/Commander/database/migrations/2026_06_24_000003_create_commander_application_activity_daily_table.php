<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rollup diario de actividad Git por aplicación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commander_application_activity_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('commander_applications')->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('commit_count')->default(0);
            $table->unsignedInteger('additions')->default(0);
            $table->unsignedInteger('deletions')->default(0);
            $table->unsignedInteger('files_changed')->default(0);
            $table->timestamps();

            $table->unique(['application_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commander_application_activity_daily');
    }
};
