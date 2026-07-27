<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Packages\Snk\Kilkana\Enums\SnkKilkanaCommentStatusEnum;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kilkana_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('kilkana_comments')->cascadeOnDelete();
            $table->foreignId('post_id')->constrained('kilkana_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', SnkKilkanaCommentStatusEnum::values())->default(SnkKilkanaCommentStatusEnum::Pending->value);
            $table->text('body');
            $table->timestamps();
            $table->index(['post_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kilkana_comments');
    }
};
