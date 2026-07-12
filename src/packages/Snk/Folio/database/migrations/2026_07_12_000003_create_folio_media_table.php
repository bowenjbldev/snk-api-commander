<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Biblioteca de medios: hoy URLs externas; path/dimensiones preparan el upload futuro.
        Schema::create('folio_media', function (Blueprint $table) {
            $table->id();
            $table->string('url', 2048);
            $table->string('alt', 500)->nullable();
            $table->string('caption', 500)->nullable();
            $table->string('mime', 64)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folio_media');
    }
};
