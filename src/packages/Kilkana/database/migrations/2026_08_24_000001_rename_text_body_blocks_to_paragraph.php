<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El bloque de cuerpo pasa a llamarse `paragraph`, que es como lo llama el editor.
 *
 * Los dos lados tenian vocabularios distintos —siete tipos aqui, diez alli, cuatro en comun—
 * y el editor no podia guardar: cualquier pieza con un intertitulo, una lista o un recuadro
 * de datos rebotaba con un 422. La lista la manda el editor, porque el `type` es lo que este
 * backend razona (`publicBodyBlocks()` corta en `paywall`) y lo que un renderizador publico
 * conmuta: un `type` que miente para tres de cada diez bloques es una averia diferida.
 *
 * Va como migracion hacia delante y no reescribiendo la que creo la tabla, aunque hoy no haya
 * una sola fila con cuerpo: el esquema desplegado no se reescribe, y el dia que si las haya
 * esta es la unica forma de que la reconciliacion llegue tambien alli.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rename('text', 'paragraph');
    }

    public function down(): void
    {
        $this->rename('paragraph', 'text');
    }

    /**
     * Se hace con una sustitucion de cadena sobre el JSON y no leyendo fila a fila.
     *
     * `"type":"text"` no puede aparecer dentro de otra cosa: el unico sitio donde el contrato
     * pone la clave `type` es la raiz de un bloque, y su valor es una lista cerrada. Recorrer
     * y reescribir quinientos documentos en PHP costaria quinientas escrituras para el mismo
     * resultado.
     */
    private function rename(string $from, string $to): void
    {
        if (! Schema::hasTable('kilkana_posts')) {
            return;
        }

        DB::table('kilkana_posts')
            ->whereNotNull('body_blocks')
            ->where('body_blocks', 'like', '%"type":"'.$from.'"%')
            ->update([
                'body_blocks' => DB::raw(
                    "REPLACE(body_blocks, '\"type\":\"{$from}\"', '\"type\":\"{$to}\"')"
                ),
            ]);
    }
};
