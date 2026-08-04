<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La variante aplicable: la piel concreta que viste el periodico.
 *
 * Una fila guarda PUNTERO + DIFERENCIA, no el tema entero. `base_slug` dice de que variante
 * enviada por el producto deriva, y `tokens`/`style` solo lo que esta redaccion cambio encima.
 * Los dos nulos significan «tal como la envia el producto»: actualizar el producto actualiza a
 * todos los inquilinos y lo que cada redaccion toco sobrevive.
 *
 * Copiar el tema entero en cada base era la alternativa, y es la que se queda vieja: un
 * catalogo replicado en N bases hay que resembrarlo en N bases cada vez que cambia un color.
 *
 * Lo que NO baja aqui, porque no son datos: las plantillas de `layouts` (referencian tipos de
 * bloque del editor), `masthead` (elige un componente), y el lenguaje kicker/tarjeta del
 * paquete. Esa frontera ya estaba escrita en `kilkana.app/src/lib/themes/overrides.ts`: es
 * exactamente lo que una redaccion podia tocar, que es lo unico que tiene sentido guardar.
 *
 * `is_builtin` distingue las sembradas de las que crea la redaccion. Una builtin no se borra:
 * es la base de la que cuelgan las demas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kilkana_theme_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('theme_id')->constrained('kilkana_themes')->cascadeOnDelete();
            $table->string('base_slug', 64);
            $table->string('slug', 64)->unique();
            $table->string('name')->nullable();
            $table->json('tokens')->nullable();
            $table->json('style')->nullable();
            $table->boolean('is_builtin')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kilkana_theme_variants');
    }
};
