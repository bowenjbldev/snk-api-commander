<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folio_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('folio_categories');
            $table->foreignId('author_id')->constrained('folio_authors');
            $table->foreignId('cover_media_id')->nullable()->constrained('folio_media')->nullOnDelete();
            $table->string('title', 500);
            $table->text('excerpt');
            $table->timestamp('published_at');
            $table->unsignedInteger('reads')->default(0);
            $table->string('status', 16)->default('draft'); // published|draft|scheduled
            $table->longText('body')->nullable();           // HTML legacy
            $table->json('body_blocks')->nullable();        // contenido del post (como post_content en WP)
            $table->string('slug')->nullable()->unique();   // URL SEO; null en borradores
            $table->boolean('featured')->default(false);
            $table->string('scheduled_for', 32)->nullable();
            $table->timestamps();

            $table->index(['status', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folio_posts');
    }
};
