<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Origen Git y cursor incremental del package que implementa una aplicación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commander_application_repos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->unique()->constrained('commander_applications')->cascadeOnDelete();
            $table->string('provider', 32)->default('bitbucket'); // bitbucket|github|gitlab
            $table->string('workspace')->nullable();   // workspace/owner del repo en el provider
            $table->string('repo_slug')->nullable();    // slug del repo en el provider
            // Monorepo: subcarpeta del package compartido. null = repo dedicado.
            $table->string('path_prefix')->nullable();
            $table->string('default_branch', 100)->default('main');
            // Cursor del sync incremental: hasta dónde se leyó la última vez.
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_synced_sha', 40)->nullable();
            $table->timestamps();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commander_application_repos');
    }
};
