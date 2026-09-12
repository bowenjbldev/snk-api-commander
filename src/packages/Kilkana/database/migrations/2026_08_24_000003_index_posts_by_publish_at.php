<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La vista por defecto del listado editorial deja de recorrer la tabla entera.
 *
 * `/admin/posts` sin filtro ordena por `publish_at desc, id desc`, y el único índice previo
 * empieza por `status`: sin filtro de estado no sirve de nada. Medido con `EXPLAIN`
 * sobre 522 filas: `type: ALL, key: NULL, Using filesort`. Con quinientas da igual —dos
 * milisegundos—, pero es la pantalla más visitada del CMS y la consulta ordena el archivo
 * COMPLETO para devolver veinticinco filas: a cien mil piezas, cada carga es un filesort de
 * cien mil.
 *
 * Las dos columnas, y en este orden, porque el desempate es parte del orden: `id` sola no
 * ayuda y `publish_at` sola deja el desempate al filesort. Es también el orden que hace
 * estable la paginación, así que el índice y la corrección de paginar son el mismo hecho.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kilkana_posts', function (Blueprint $table) {
            $table->index(['publish_at', 'id'], 'kilkana_posts_publish_at_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('kilkana_posts', function (Blueprint $table) {
            $table->dropIndex('kilkana_posts_publish_at_id_index');
        });
    }
};
