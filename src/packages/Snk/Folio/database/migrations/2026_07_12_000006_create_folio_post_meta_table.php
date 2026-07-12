<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Clave-valor por post (estilo wp_postmeta): seo_title/seo_description y extensiones.
        Schema::create('folio_post_meta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('folio_posts')->cascadeOnDelete();
            $table->string('meta_key', 128);
            $table->text('meta_value')->nullable();
            $table->timestamps();

            $table->unique(['post_id', 'meta_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folio_post_meta');
    }
};
