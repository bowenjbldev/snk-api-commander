<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Log RAW de commits por package: la fuente de verdad de los insights. Lo poblara `commander:git:sync`
 * leyendo el git del package (path-scoped en monorepo via `git log --numstat -- <path_prefix>`, o la
 * API remota del proveedor git). additions/deletions/files_changed van ACOTADOS al path del package. El rollup
 * diario (commander_package_activity_daily) lo agrega para el heatmap. Mismo patron raw->rollup que
 * commander_request_logs -> commander_usage_rollups.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('commander_package_commits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained('commander_packages')->cascadeOnDelete();
            $table->string('sha', 40);
            $table->timestamp('committed_at')->index(); // sello del commit; series temporales del heatmap
            $table->string('author_name')->nullable();
            $table->string('author_email')->nullable();
            $table->text('message')->nullable();
            $table->unsignedInteger('additions')->default(0);     // lineas + (scoped al path del package)
            $table->unsignedInteger('deletions')->default(0);     // lineas -
            $table->unsignedInteger('files_changed')->default(0);
            $table->timestamps();

            // Un commit por package; uno que toca varios packages = una fila por cada uno (lineas
            // ya separadas por path). Idempotente para el upsert del sync.
            $table->unique(['package_id', 'sha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commander_package_commits');
    }
};
