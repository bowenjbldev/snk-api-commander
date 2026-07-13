<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Biblioteca de medios: hoy URLs externas; path/dimensiones preparan el upload futuro.
        Schema::create('kilkana_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('url', 2048);
            // sha1(url): dedup real (url no cabe en un índice UNIQUE); lo rellena el modelo.
            $table->char('url_hash', 40)->unique();
            $table->string('alt', 500)->nullable();
            $table->string('caption', 500)->nullable();
            $table->string('mime', 64)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            // Esqueleto del upload propio: hoy urls externas, mañana ficheros del tenant.
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('disk', 32)->nullable();
            $table->string('path', 1024)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kilkana_media');
    }
};
