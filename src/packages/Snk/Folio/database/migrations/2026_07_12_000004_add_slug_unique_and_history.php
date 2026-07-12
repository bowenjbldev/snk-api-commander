<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SEO: el slug es la URL pública del artículo. Único cuando existe (NULL permitido
// en borradores) + historial de slugs antiguos para responder 301 al renombrar.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folio_posts', function (Blueprint $table) {
            $table->unique('slug');
        });

        Schema::create('folio_post_slugs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('folio_posts')->cascadeOnDelete();
            $table->string('slug')->unique(); // un slug viejo apunta a UN post
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folio_post_slugs');
        Schema::table('folio_posts', function (Blueprint $table) {
            $table->dropUnique(['slug']);
        });
    }
};
