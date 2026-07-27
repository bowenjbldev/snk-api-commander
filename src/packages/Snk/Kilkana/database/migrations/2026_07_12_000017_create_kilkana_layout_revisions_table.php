<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kilkana_layout_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('layout_id')->constrained('kilkana_layouts')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('schema');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['layout_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kilkana_layout_revisions');
    }
};
