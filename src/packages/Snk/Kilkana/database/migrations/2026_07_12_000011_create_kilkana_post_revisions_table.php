<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Historial de versiones (como wp_posts revisions pero fuera de la tabla
        // caliente): snapshot inmutable del post ANTES de cada update. Sin
        // updated_at: una revisión nunca se edita.
        Schema::create('kilkana_post_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('kilkana_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('payload'); // columnas del post en ese momento
            $table->timestamp('created_at')->useCurrent();
            $table->index(['post_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kilkana_post_revisions');
    }
};
