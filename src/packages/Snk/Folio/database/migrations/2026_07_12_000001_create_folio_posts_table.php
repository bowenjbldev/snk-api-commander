<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folio_posts', function (Blueprint $table) {
            $table->id(); // PK entera (convención: toda tabla folio_* lleva PK autoincremental); es EL id del post
            $table->string('cat', 32);
            $table->string('title', 500);
            $table->text('excerpt');
            $table->string('author');
            $table->timestamp('published_at');
            $table->unsignedInteger('reads')->default(0);
            $table->string('image', 2048)->nullable();
            $table->string('status', 16)->default('draft'); // published|draft|scheduled
            $table->longText('body')->nullable();           // HTML legacy; el cuerpo nuevo es body_blocks
            $table->json('body_blocks')->nullable();        // ArticleBodyBlock[]
            $table->json('tags')->nullable();               // string[]
            $table->string('slug')->nullable();
            $table->boolean('featured')->default(false);
            $table->string('scheduled_for', 32)->nullable(); // 'YYYY-MM-DDTHH:mm' tal como lo envía el editor
            $table->string('seo_title', 500)->nullable();
            $table->text('seo_description')->nullable();
            $table->timestamps();

            $table->index(['status', 'published_at']);
            $table->index('cat');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folio_posts');
    }
};
