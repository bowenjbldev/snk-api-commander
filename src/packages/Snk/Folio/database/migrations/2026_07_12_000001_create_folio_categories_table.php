<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folio_categories', function (Blueprint $table) {
            $table->id();
            // Secciones jerárquicas (Sports → Football); borrar el padre promociona hijas a raíz.
            $table->foreignId('parent_id')->nullable()->constrained('folio_categories')->nullOnDelete();
            $table->string('slug', 64)->unique(); // 'politics', 'sports'... la URL de la sección
            $table->string('name');
            $table->smallInteger('hue')->default(220); // gradiente de PostThumb sin foto
            $table->text('description')->nullable(); // la página pública de sección la usa (SEO)
            $table->unsignedSmallInteger('position')->default(0); // orden del nav
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folio_categories');
    }
};
