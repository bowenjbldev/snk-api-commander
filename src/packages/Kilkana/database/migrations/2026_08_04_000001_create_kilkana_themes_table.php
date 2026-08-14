<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El paquete de tema: la familia estetica, que es la unidad vendible.
 *
 * Un paquete agrupa variantes que comparten lenguaje de diseno —el kicker, la tarjeta, la
 * cabecera—, y eso NO es un dato: son componentes y CSS que envia el producto. Aqui vive el
 * puntero, para que la variante que viste un periodico pueda ser una clave foranea de verdad
 * en vez de una cadena suelta que nadie valida.
 *
 * `name` nulo significa «el que envia el producto» y evita congelar una traducción concreta en
 * cada base de inquilino.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kilkana_themes', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kilkana_themes');
    }
};
