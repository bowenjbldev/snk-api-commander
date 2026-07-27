<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kilkana_author_slugs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')->constrained('kilkana_authors')->cascadeOnDelete();
            $table->string('path', 255)->unique();
            $table->timestamps();
        });

        Schema::create('kilkana_category_slugs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('kilkana_categories')->cascadeOnDelete();
            $table->string('path', 64)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kilkana_category_slugs');
        Schema::dropIfExists('kilkana_author_slugs');
    }
};
