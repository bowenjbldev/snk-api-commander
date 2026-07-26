<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kilkana_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('url', 2048);
            $table->char('url_hash', 40)->unique();
            $table->char('content_hash', 64)->nullable()->unique();

            $table->string('original_name', 500)->nullable();
            $table->string('title', 500)->nullable();
            $table->string('disk', 32)->nullable();
            $table->string('path', 1024)->nullable();
            $table->string('mime', 64)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration')->nullable();

            $table->string('alt', 500)->nullable();
            $table->string('caption', 500)->nullable();
            $table->text('description')->nullable();

            $table->string('credit', 500)->nullable();
            $table->string('source_url', 2048)->nullable();
            $table->string('rights_status', 32)->default('unknown');
            $table->string('license', 500)->nullable();
            $table->date('rights_expires_at')->nullable();

            $table->decimal('focal_x', 5, 4)->default(0.5);
            $table->decimal('focal_y', 5, 4)->default(0.5);

            $table->softDeletes();
            $table->timestamps();

            // La consulta que SIEMPRE se hace: `SoftDeletes` mete `deleted_at IS NULL` en
            // cada lectura de la biblioteca, y el listado ordena por fecha de alta. Sin este
            // indice el plan era `type: ALL` mas `filesort` — irrelevante con 60 filas y caro
            // en una fototeca de decenas de miles, que es lo que tiene un periodico.
            $table->index(['deleted_at', 'created_at']);
            // Solo la fecha. Estaba como `(rights_status, rights_expires_at)`, y la unica
            // consulta que mira la caducidad filtra por fecha sin tocar el estado: con el
            // estado delante, el indice no le servia. El estado si se filtra solo, pero tiene
            // cuatro valores contados y ahi un indice no aporta.
            $table->index('rights_expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kilkana_media');
    }
};
