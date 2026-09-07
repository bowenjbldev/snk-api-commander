<?php

namespace Packages\Kilkana\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lo que se ve de una foto: lo que se pinta, y **dónde vive no**.
 *
 * Fuera quedan `path`, `disk`, `url_hash` y las huellas del fichero. La calle no necesita saber
 * dónde está guardado y decirlo es una superficie más.
 *
 * **Esta es la excepción a que los campos los elija quien llama.** En el resto de relaciones el
 * prefijo de `?fields=` decide qué columnas salen; aquí no, porque `variants` es un objeto
 * calculado sobre cuatro columnas de fontanería —`master_path`, `master_width`, `master_height`
 * y `variants_key`— que nadie debería tener que nombrar para recibir las derivadas de una
 * imagen. Quien llama elige **si** viene la foto (`?include=coverMedia`); su forma la declara
 * este fichero.
 */
class SnkKilkanaMediaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->whenHas('id'),
            'url' => $this->whenHas('url'),
            'title' => $this->whenHas('title'),
            'alt' => $this->whenHas('alt'),
            'caption' => $this->whenHas('caption'),
            'credit' => $this->whenHas('credit'),
            'width' => $this->whenHas('width'),
            'height' => $this->whenHas('height'),
            'mime' => $this->whenHas('mime'),
            'size_bytes' => $this->whenHas('size_bytes'),
            'focal_x' => $this->whenHas('focal_x'),
            'focal_y' => $this->whenHas('focal_y'),
            'crop_x' => $this->whenHas('crop_x'),
            'crop_y' => $this->whenHas('crop_y'),
            'crop_w' => $this->whenHas('crop_w'),
            'crop_h' => $this->whenHas('crop_h'),
            // Derivado, no una columna: lo compone `SnkKilkanaImageService::payload()` a partir
            // del máster y de la clave de variantes.
            'variants' => $this->whenHas('variants_key', fn () => $this->resource->variants),
        ];
    }
}
