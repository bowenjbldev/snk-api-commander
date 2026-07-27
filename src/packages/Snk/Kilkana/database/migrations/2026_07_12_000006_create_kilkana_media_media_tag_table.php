<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kilkana_media_media_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_id')->constrained('kilkana_media')->cascadeOnDelete();
            $table->foreignId('media_tag_id')->constrained('kilkana_media_tags')->cascadeOnDelete();
            $table->unique(['media_id', 'media_tag_id'], 'media_tag_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kilkana_media_media_tag');
    }
};
