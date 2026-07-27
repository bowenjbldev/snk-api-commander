<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kilkana_authors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('avatar_media_id')->nullable()->constrained('kilkana_media')->nullOnDelete();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('alias')->nullable();
            $table->string('slug')->unique();
            $table->string('seo_title', 500)->nullable();
            $table->text('seo_description')->nullable();
            $table->string('job_title')->nullable();
            $table->text('bio')->nullable();
            $table->string('website', 2048)->nullable();
            $table->json('socials')->nullable();
            $table->boolean('noindex')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kilkana_authors');
    }
};
