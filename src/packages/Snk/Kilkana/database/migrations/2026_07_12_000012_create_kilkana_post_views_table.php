<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lecturas agregadas por día: «más leído hoy/esta semana» real, sin
        // castigar la fila del post con un UPDATE por visita.
        Schema::create('kilkana_post_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('kilkana_posts')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('views')->default(0);
            $table->unique(['post_id', 'date']);
            $table->index('date'); // ranking del día/semana entre todos los posts
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kilkana_post_views');
    }
};
