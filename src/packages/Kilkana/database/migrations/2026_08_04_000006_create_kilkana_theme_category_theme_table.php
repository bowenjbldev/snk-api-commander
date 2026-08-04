<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un tema está en varias categorías y una categoría agrupa muchos temas.
 *
 * N:M y no una columna `category_id` porque un tema real cae en más de un sitio: uno oscuro y
 * denso pensado para una web de videojuegos es a la vez «tecnología» y «revista». Obligarle a
 * elegir una sola casilla haría que no apareciera en el filtro por el que lo van a buscar.
 *
 * CASCADE por los dos lados: retirar una categoría del vocabulario SIGNIFICA desetiquetar los
 * temas que la tenían, igual que en la taxonomía de medios. Quien la borre debe ver antes
 * cuántos temas se lleva por delante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kilkana_theme_category_theme', function (Blueprint $table) {
            $table->id();
            $table->foreignId('theme_id')->constrained('kilkana_themes')->cascadeOnDelete();
            $table->foreignId('theme_category_id')->constrained('kilkana_theme_categories')->cascadeOnDelete();
            // Nombre corto explícito: el que deriva Laravel de estas dos columnas se pasa del
            // límite de 64 caracteres de MariaDB.
            $table->unique(['theme_id', 'theme_category_id'], 'theme_category_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kilkana_theme_category_theme');
    }
};
