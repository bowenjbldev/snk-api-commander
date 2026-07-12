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
            $table->string('slug', 64)->unique(); // 'politica', 'deportes'... la URL de la sección
            $table->string('name');
            $table->smallInteger('hue')->default(220); // gradiente de PostThumb sin foto
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folio_categories');
    }
};
