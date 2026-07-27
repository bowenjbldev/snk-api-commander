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
    }

    public function down(): void
    {
        Schema::dropIfExists('kilkana_author_slugs');
    }
};
