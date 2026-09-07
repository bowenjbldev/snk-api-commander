<?php

namespace Packages\Kilkana\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una foto: lo que se pinta de ella, y **dónde vive no**.
 *
 * Fuera quedan `path`, `disk` y `url_hash`, que son exactamente los dos campos con los que se
 * borra un fichero del disco más el índice de dedup. Leerlos no borra nada; publicarlos es
 * contarle a cualquiera con sesión cómo está montado el almacenamiento del inquilino, y no hay
 * una sola pantalla que los pinte. Antes lo garantizaba `FILA_DE_LA_BIBLIOTECA` con un
 * `setVisible()`; ahora es que no están declarados.
 *
 * **Sirve a dos puertas con techos distintos y no hay un `if` que las distinga.** La mediateca
 * carga la fila entera y ve las veintinueve; la calle selecciona las suyas y ve menos. Lo caro
 * va condicionado a haberse cargado, no a la puerta: `usage_count` son tres `COUNT(*)` por foto
 * —en una portada de cien piezas, trescientos— y solo sale donde alguien hizo `loadCount()`.
 *
 * `variants` es la excepción a que los campos los elija quien llama: se calcula sobre cuatro
 * columnas de fontanería —`master_path`, `master_width`, `master_height` y `variants_key`— que
 * nadie debería tener que nombrar para recibir las derivadas de una imagen.
 */
class SnkKilkanaMediaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->whenHas('id'),
            'url' => $this->whenHas('url'),
            'original_name' => $this->whenHas('original_name'),
            'title' => $this->whenHas('title'),
            'mime' => $this->whenHas('mime'),
            'size_bytes' => $this->whenHas('size_bytes'),
            'width' => $this->whenHas('width'),
            'height' => $this->whenHas('height'),
            'duration' => $this->whenHas('duration'),
            'alt' => $this->whenHas('alt'),
            'caption' => $this->whenHas('caption'),
            'credit' => $this->whenHas('credit'),
            'license' => $this->whenHas('license'),
            'rights_status' => $this->whenHas('rights_status'),
            'content_hash' => $this->whenHas('content_hash'),

            'focal_x' => $this->whenHas('focal_x'),
            'focal_y' => $this->whenHas('focal_y'),
            'crop_x' => $this->whenHas('crop_x'),
            'crop_y' => $this->whenHas('crop_y'),
            'crop_w' => $this->whenHas('crop_w'),
            'crop_h' => $this->whenHas('crop_h'),
            'frames' => $this->whenHas('frames'),
            'variants' => $this->whenHas('variants_key', fn () => $this->resource->variants),

            'poster_at' => $this->whenHas('poster_at'),
            'poster_media_id' => $this->whenHas('poster_media_id'),
            'created_at' => $this->whenHas('created_at'),
            'deleted_at' => $this->whenHas('deleted_at'),

            // Tres `COUNT(*)` por fila: solo donde se hayan cargado. El accesor los suma.
            'usage_count' => $this->whenHas('covered_posts_count', fn () => $this->resource->usage_count),
            // De las dos taxonomías basta el par que dibuja un chip: el id para escribir y el
            // nombre para leer. Los pivotes no pintan nada.
            'tags' => SnkKilkanaTagResource::collection($this->whenLoaded('tags')),
            'collections' => SnkKilkanaMediaCollectionResource::collection($this->whenLoaded('collections')),
        ];
    }
}
