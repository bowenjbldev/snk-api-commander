<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folio_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('folio_post_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('folio_posts')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('folio_tags')->cascadeOnDelete();
            $table->unique(['post_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folio_post_tag');
        Schema::dropIfExists('folio_tags');
    }
};
