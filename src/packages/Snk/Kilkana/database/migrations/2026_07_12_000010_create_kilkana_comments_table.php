<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kilkana_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('kilkana_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('kilkana_comments')->cascadeOnDelete();
            $table->string('author_name', 80)->nullable();
            $table->string('author_email', 190)->nullable();
            $table->text('body');
            $table->string('status', 16)->default('pending');
            $table->timestamps();
            $table->index(['post_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kilkana_comments');
    }
};
