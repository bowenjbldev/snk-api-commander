<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kilkana_post_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('kilkana_posts')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('opens')->default(0);
            $table->unsignedInteger('engaged')->default(0);
            $table->unsignedInteger('readers')->default(0);
            $table->unsignedInteger('ref_search')->default(0);
            $table->unsignedInteger('ref_social')->default(0);
            $table->unsignedInteger('ref_direct')->default(0);
            $table->unsignedInteger('ref_internal')->default(0);
            $table->unsignedInteger('ref_other')->default(0);
            $table->unique(['post_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kilkana_post_views');
    }
};
