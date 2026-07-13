<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kilkana_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('kilkana_categories');
            $table->foreignId('author_id')->constrained('kilkana_authors');
            $table->foreignId('cover_media_id')->nullable()->constrained('kilkana_media')->nullOnDelete();
            // Auditoría editorial: quién creó y quién tocó por última vez.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 500);
            $table->text('excerpt');
            $table->timestamp('published_at')->nullable(); // null = borrador sin fecha
            $table->unsignedInteger('reads')->default(0);
            $table->string('status', 16)->default('draft'); // published|draft|scheduled
            $table->longText('body')->nullable();           // HTML legacy
            $table->json('body_blocks')->nullable();        // contenido del post (como post_content en WP)
            $table->string('slug')->nullable()->unique();   // URL SEO; null en borradores
            $table->boolean('featured')->default(false);
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamps();

            $table->index(['status', 'published_at']);
            // La query del scheduler: scheduled con fecha vencida → published.
            $table->index(['status', 'scheduled_for']);
            // Buscador del sitio (public/posts?q=) sin motor externo.
            $table->fullText(['title', 'excerpt']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kilkana_posts');
    }
};
