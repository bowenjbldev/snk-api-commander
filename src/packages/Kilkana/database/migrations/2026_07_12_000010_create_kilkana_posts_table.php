<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Packages\Kilkana\Enums\SnkKilkanaContentTierEnum;
use Packages\Kilkana\Enums\SnkKilkanaPostStatusEnum;
use Packages\Kilkana\Models\SnkKilkanaPostModel;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kilkana_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')->constrained('kilkana_authors');
            $table->foreignId('category_id')->constrained('kilkana_categories');
            $table->foreignId('cover_media_id')->nullable()->constrained('kilkana_media')->nullOnDelete();
            $table->foreignId('og_image_id')->nullable()->constrained('kilkana_media')->nullOnDelete();
            $table->string('title', 500);
            $table->text('excerpt');
            $table->string('slug', SnkKilkanaPostModel::SLUG_MAX);
            $table->string('seo_title', 500)->nullable();
            $table->text('seo_description')->nullable();
            $table->string('og_title', 500)->nullable();
            $table->text('og_description')->nullable();
            $table->enum('status', SnkKilkanaPostStatusEnum::values())->default(SnkKilkanaPostStatusEnum::Draft->value);
            $table->enum('content_tier', SnkKilkanaContentTierEnum::values())->default(SnkKilkanaContentTierEnum::Free->value);
            $table->json('body_blocks')->nullable();
            $table->string('canonical_url', 2048)->nullable();
            $table->boolean('featured')->default(false);
            $table->boolean('noindex')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('url_date')->nullable();
            $table->timestamp('publish_at')->nullable();
            $table->timestamps();
            $table->unique(['url_date', 'slug']);
            $table->index(['status', 'publish_at']);
        });

        // El buscador del sitio, sin motor externo. Va aparte y con guarda de driver porque
        // sqlite no compila un índice FULLTEXT y aborta la migración entera: sin esto el
        // esquema de un inquilino no se puede levantar en pruebas, y nada de la API editorial
        // tiene arnés. Sobre MariaDB el índice es el mismo: la guarda solo hace el esquema
        // portable al driver de la suite.
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('kilkana_posts', function (Blueprint $table) {
                $table->fullText(['title', 'excerpt']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kilkana_posts');
    }
};
