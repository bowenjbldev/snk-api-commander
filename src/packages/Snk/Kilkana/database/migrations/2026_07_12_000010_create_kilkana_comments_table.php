<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Comentarios de lectores registrados; parent_id anida hilos (borrar el
        // padre elimina el hilo). Moderación por status: pending|approved|spam.
        Schema::create('kilkana_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('kilkana_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('kilkana_comments')->cascadeOnDelete();
            $table->text('body');
            $table->string('status', 16)->default('pending');
            $table->timestamps();
            // La query caliente: comentarios aprobados de un artículo.
            $table->index(['post_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kilkana_comments');
    }
};
