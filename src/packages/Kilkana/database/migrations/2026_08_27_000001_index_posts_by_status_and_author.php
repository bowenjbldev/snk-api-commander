<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las cifras del tablero vuelven a salir de un indice cubridor.
 *
 * El reparto entre estados cuenta ahora dos escalas —lo de quien mira y lo del periodico— con
 * un `count(*)` y un `sum(created_by = ?)` sobre el mismo `group by status`. El unico indice
 * que servia era `(status, publish_at)`, y `created_by` no esta en el: la suma saca la
 * consulta del indice y la manda a barrer la tabla. Medido con `EXPLAIN` sobre 522 filas:
 *
 *     sin la suma:  type: index   key: (status, publish_at)   Using index
 *     con la suma:  type: ALL     key: NULL                   Using temporary; Using filesort
 *     con este:     type: index   key: (status, created_by)   Using index
 *
 * Con quinientas piezas la diferencia no se nota. Pero esta consulta corre en **cada** carga
 * del tablero y en **cada** carga del listado editorial, que son las dos pantallas mas
 * visitadas del CMS, y a cien mil piezas cada una de esas cargas es un barrido completo con
 * tabla temporal.
 *
 * Las dos columnas y en este orden porque asi lo pide la consulta: agrupa por `status` y suma
 * sobre `created_by`. Al reves no cubre el `group by`.
 *
 * El servicio ademas **no pide la suma** cuando el rol puede editar ajenas, porque entonces
 * las dos escalas son la misma: eso ya devolvia el indice cubridor a tres de los seis roles
 * del panel sin tocar el esquema. Este indice cubre a los otros tres.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kilkana_posts', function (Blueprint $table) {
            $table->index(['status', 'created_by'], 'kilkana_posts_status_created_by_index');
        });
    }

    public function down(): void
    {
        Schema::table('kilkana_posts', function (Blueprint $table) {
            $table->dropIndex('kilkana_posts_status_created_by_index');
        });
    }
};
