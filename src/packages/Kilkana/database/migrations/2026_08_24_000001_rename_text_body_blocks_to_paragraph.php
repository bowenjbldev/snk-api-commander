<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El bloque de cuerpo pasa a llamarse `paragraph`, que es como lo llama el editor.
 *
 * Los dos lados tenían vocabularios distintos —siete tipos aquí, diez allí, cuatro en común—
 * y el editor no podía guardar: cualquier pieza con un intertítulo, una lista o un recuadro
 * de datos rebotaba con un 422. La lista la manda el editor, porque el `type` es lo que este
 * backend razona (`publicBodyBlocks()` corta en `paywall`) y lo que un renderizador público
 * conmuta: un `type` que miente para tres de cada diez bloques es una avería diferida.
 *
 * Va como migración hacia delante y no reescribiendo la que creó la tabla: el esquema
 * desplegado no se reescribe, y una base que ya guarda cuerpos solo recibe la reconciliación
 * por esta vía.
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
     * Se hace con una sustitución de cadena sobre el JSON y no leyendo fila a fila.
     *
     * `"type":"text"` no puede aparecer dentro de otra cosa: el único sitio donde el contrato
     * pone la clave `type` es la raíz de un bloque, y su valor es una lista cerrada. Recorrer
     * y reescribir quinientos documentos en PHP costaría quinientas escrituras para el mismo
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
