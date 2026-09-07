<?php

namespace Tests\Unit;

use Packages\Kilkana\Services\SnkKilkanaMediaService;
use PHPUnit\Framework\TestCase;

/**
 * Los campos que una ficha de la mediateca publica al panel, escritos a mano.
 *
 * Gemela de `media.server.test.ts` en `kilkana.app`, que escribe la misma lista y comprueba
 * con un Proxy que el adaptador del BFF lee exactamente esas claves. Misma regla que la fila
 * del listado editorial: si esto se pone rojo, se mira el otro lado y se decide a la vez.
 */
class SnkKilkanaMediaRowTest extends TestCase
{
    public function test_la_ficha_de_la_biblioteca_publica_veintinueve_campos(): void
    {
        $this->assertSame([
            'id', 'url', 'original_name', 'title', 'mime', 'size_bytes', 'width', 'height',
            'duration', 'alt', 'credit', 'license', 'rights_status', 'content_hash',
            'focal_x', 'focal_y', 'crop_x', 'crop_y', 'crop_w', 'crop_h', 'frames', 'variants', 'poster_at', 'poster_media_id',
            'created_at', 'deleted_at', 'usage_count', 'tags', 'collections',
        ], SnkKilkanaMediaService::FILA_DE_LA_BIBLIOTECA);
    }

    /**
     * Y los que NO salen, por su nombre y con su motivo.
     *
     * `disk` y `path` son los dos con los que se borra un fichero del disco, y no hay una sola
     * pantalla que los pinte: publicarlos era contar como esta montado el almacenamiento del
     * inquilino a cambio de nada. Los otros cuatro son editoriales que el panel todavia no
     * ensena — entran anadiendolos arriba, que es el momento de decidirlo.
     */
    public function test_el_almacenamiento_del_inquilino_no_viaja_al_panel(): void
    {
        foreach (['disk', 'path', 'url_hash', 'uploaded_by', 'caption', 'description', 'source_url', 'rights_expires_at'] as $campo) {
            $this->assertNotContains(
                $campo,
                SnkKilkanaMediaService::FILA_DE_LA_BIBLIOTECA,
                "«{$campo}» no lo pinta ninguna pantalla del panel",
            );
        }
    }
}
