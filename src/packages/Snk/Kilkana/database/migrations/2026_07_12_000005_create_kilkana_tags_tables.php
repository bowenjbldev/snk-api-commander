<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kilkana_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('kilkana_post_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('kilkana_posts')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('kilkana_tags')->cascadeOnDelete();
            $table->unique(['post_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kilkana_post_tag');
        Schema::dropIfExists('kilkana_tags');
    }
};
