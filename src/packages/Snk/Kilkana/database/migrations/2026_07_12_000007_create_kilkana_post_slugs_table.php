<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kilkana_post_slugs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('kilkana_posts')->cascadeOnDelete();
            $table->string('path')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kilkana_post_slugs');
    }
};
