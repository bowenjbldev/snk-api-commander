<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las notas de mesa de una pieza: lo que la redacción se dice sobre un artículo mientras se
 * escribe. Nunca llegan a un lector; se van con la pieza cuando se purga.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kilkana_post_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('kilkana_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // El bloque al que se pegó, por su id de cuerpo; nulo = de la pieza entera.
            $table->string('block', 128)->nullable();
            $table->text('body');
            $table->boolean('done')->default(false);
            $table->timestamps();
            $table->index(['post_id', 'done']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kilkana_post_notes');
    }
};
