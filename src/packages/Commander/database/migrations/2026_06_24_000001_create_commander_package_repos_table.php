<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Origen git de cada package (1:1) + cursor de sync incremental. Alimenta la feature FUTURA de
 * Git Insights (historial de commits, code frequency, heatmap por package). `path_prefix` mantiene
 * el esquema agnostico a la estrategia de repo: monorepo (subcarpeta `src/packages/<Client>/<Package>`)
 * o repo dedicado (`null`). El sync (LocalGit `git log` o Bitbucket API) lo lee y puebla
 * commander_package_commits; aqui no se construye nada mas todavia.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('commander_package_repos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained('commander_packages')->cascadeOnDelete();
            $table->string('provider', 32)->default('bitbucket'); // bitbucket|github|gitlab
            $table->string('workspace')->nullable();   // workspace/owner del repo en el provider
            $table->string('repo_slug')->nullable();    // slug del repo en el provider
            // Monorepo: subcarpeta del package (src/packages/<Client>/<Package>). null = repo dedicado.
            $table->string('path_prefix')->nullable();
            $table->string('default_branch', 100)->default('main');
            // Cursor del sync incremental: hasta donde se leyo la ultima vez.
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_synced_sha', 40)->nullable();
            $table->timestamps();

            // 1:1 con el package.
            $table->unique('package_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commander_package_repos');
    }
};
