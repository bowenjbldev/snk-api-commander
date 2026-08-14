<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commits crudos acotados al package de una aplicación. El sync los agrega después por día.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commander_application_commits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('commander_applications')->cascadeOnDelete();
            $table->string('sha', 40);
            $table->timestamp('committed_at')->index(); // sello del commit; series temporales del heatmap
            $table->string('author_name')->nullable();
            $table->string('author_email')->nullable();
            $table->text('message')->nullable();
            $table->unsignedInteger('additions')->default(0);     // lineas + (scoped al path del package)
            $table->unsignedInteger('deletions')->default(0);     // lineas -
            $table->unsignedInteger('files_changed')->default(0);
            $table->timestamps();

            $table->unique(['application_id', 'sha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commander_application_commits');
    }
};
