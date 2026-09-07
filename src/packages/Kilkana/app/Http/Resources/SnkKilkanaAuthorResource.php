<?php

namespace Packages\Kilkana\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * La firma tal y como se publica.
 *
 * `name` **no es una columna**: es el alias si lo hay y el nombre de la cuenta si no, y esa
 * regla vive en el modelo (`getNameAttribute()`) porque decide qué se puede decir de quién.
 * Aquí sale cuando su columna está —no se puede pedir por su nombre, porque no existe—, que
 * es el mismo trato que recibe cualquier campo derivado.
 */
class SnkKilkanaAuthorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->whenHas('id'),
            'slug' => $this->whenHas('slug'),
            'name' => $this->whenHas('alias', fn () => $this->resource->name),
        ];
    }
}
