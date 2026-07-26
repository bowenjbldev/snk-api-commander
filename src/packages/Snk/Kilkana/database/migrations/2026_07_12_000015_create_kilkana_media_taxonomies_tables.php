<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Taxonomías de la biblioteca de medios: etiquetas y colecciones, cada una con su pivote.
 * Son de MEDIOS, no de artículos — `kilkana_tags` es otra cosa y vive en su migración.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kilkana_media_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->timestamps();
        });

        Schema::create('kilkana_media_collections', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->timestamps();
        });

        Schema::create('kilkana_media_media_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_id')->constrained('kilkana_media')->cascadeOnDelete();
            $table->foreignId('media_tag_id')->constrained('kilkana_media_tags')->cascadeOnDelete();
            $table->unique(['media_id', 'media_tag_id'], 'media_tag_unique');
        });

        Schema::create('kilkana_media_collection_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_id')->constrained('kilkana_media')->cascadeOnDelete();
            $table->foreignId('media_collection_id')->constrained('kilkana_media_collections')->cascadeOnDelete();
            $table->unique(['media_id', 'media_collection_id'], 'media_collection_unique');
        });
    }

    public function down(): void
    {
        // Orden inverso: los pivotes antes que las tablas a las que apuntan.
        Schema::dropIfExists('kilkana_media_collection_media');
        Schema::dropIfExists('kilkana_media_media_tag');
        Schema::dropIfExists('kilkana_media_collections');
        Schema::dropIfExists('kilkana_media_tags');
    }
};
