<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El vocabulario con el que se navega el catálogo: tecnología, anime, deportes, revista…
 *
 * Es CERRADO y curado a propósito, y esa es la diferencia con `kilkana_tags`. Allí la etiqueta
 * nace tecleando, y el juego de datos acabó con 151 etiquetas para 59 artículos —el 85 % con
 * una sola pieza dentro—. Un filtro construido sobre un vocabulario así no filtra: ofrece
 * ciento y pico casillas, la mitad sinónimos y erratas de quien las escribió.
 *
 * Aquí las categorías las decide quien publica el catálogo, llegan en el fichero maestro y son
 * pocas. Eso es lo que hace que el filtro sirva para elegir tema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kilkana_theme_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kilkana_theme_categories');
    }
};
