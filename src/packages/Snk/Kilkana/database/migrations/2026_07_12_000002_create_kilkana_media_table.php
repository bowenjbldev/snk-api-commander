<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Biblioteca de medios del tenant: el fichero es propio (disk + path), no una URL
        // remota. `url` es la ruta servida; la procedencia externa vive en `source_url`.
        Schema::create('kilkana_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('url', 2048);
            // sha1(url): dedup por origen (url no cabe en un índice UNIQUE); lo rellena el modelo.
            $table->char('url_hash', 40)->unique();
            // Dedup REAL del fichero: dos subidas del mismo contenido son una sola fila.
            $table->char('content_hash', 64)->nullable()->unique();

            // Identidad del fichero
            $table->string('original_name', 500)->nullable();
            $table->string('title', 500)->nullable();
            $table->string('disk', 32)->nullable();
            $table->string('path', 1024)->nullable();
            $table->string('mime', 64)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration')->nullable(); // vídeo y audio, en segundos

            // Texto editorial
            $table->string('alt', 500)->nullable();
            $table->string('caption', 500)->nullable();
            $table->text('description')->nullable();

            // Derechos: lo que decide si un medio se puede publicar y hasta cuándo.
            $table->string('credit', 500)->nullable();
            $table->string('source_url', 2048)->nullable();
            $table->string('rights_status', 32)->default('unknown');
            $table->string('license', 500)->nullable();
            $table->date('rights_expires_at')->nullable();

            // Punto focal 0-1: qué parte de la foto sobrevive al recorte de cada tarjeta.
            $table->decimal('focal_x', 5, 4)->default(0.5);
            $table->decimal('focal_y', 5, 4)->default(0.5);

            $table->softDeletes(); // papelera de la biblioteca
            $table->timestamps();

            // La vista «caduca pronto» filtra por los dos campos a la vez.
            $table->index(['rights_status', 'rights_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kilkana_media');
    }
};
