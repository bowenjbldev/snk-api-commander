<?php

namespace Packages\Kilkana\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * La forma de una pieza, declarada aquí y **decidida por la consulta**.
 *
 * Cada campo va con `whenHas()`, que es `array_key_exists($campo, $modelo->getAttributes())`:
 * lo que la consulta no seleccionó sale **ausente**, no `null`. Esa diferencia es la que hacía
 * falta — con la lista blanca de `setVisible()` que esto sustituye, quien lee no distinguía
 * «no lo pedí» de «está vacío», y seis campos del listado llegaron a viajar como `undefined`
 * sin que fallara nada en ninguno de los dos lados.
 *
 * De aquí se sigue que **no hay una lista de campos por defecto**: la elige quien llama con
 * `?fields=`, dentro del techo que el controlador declara en `$allowedFields`. Antes la elegía
 * `FILA_DEL_LISTADO`, una constante del servidor que el frontend tenía que adivinar.
 */
class SnkKilkanaPostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->whenHas('id'),
            'title' => $this->whenHas('title'),
            'excerpt' => $this->whenHas('excerpt'),
            'slug' => $this->whenHas('slug'),
            'status' => $this->whenHas('status'),
            'content_tier' => $this->whenHas('content_tier'),
            'publish_at' => $this->whenHas('publish_at'),
            // Columna de verdad, no el objeto `cover` que apéndía el modelo: el panel solo
            // guarda el id para pedirle la imagen a la mediateca.
            'cover_media_id' => $this->whenHas('cover_media_id'),
            // El alias del `withSum` de audiencia. Solo está si la consulta lo pidió, y por eso
            // se declara igual que una columna: para el Resource no hay diferencia.
            'views_total' => $this->whenHas('views_total'),

            'category' => new SnkKilkanaCategoryResource($this->whenLoaded('category')),
            'author' => new SnkKilkanaAuthorResource($this->whenLoaded('author')),
            'tags' => SnkKilkanaTagResource::collection($this->whenLoaded('tags')),
            'comments_count' => $this->whenCounted('comments'),
        ];
    }
}
