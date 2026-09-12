<?php

namespace Packages\Kilkana\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una sección, con la forma que le toque según cómo se haya consultado.
 *
 * Sirve a tres sitios y no hace falta una clase por cada uno: anidada dentro de una pieza llega
 * con dos columnas, en el catálogo del panel con sus recuentos, y en la calle sin `status` ni
 * `position`. Lo que sale lo decide **qué se cargó**, no un `if` aquí dentro.
 */
class SnkKilkanaCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->whenHas('id'),
            'name' => $this->whenHas('name'),
            'slug' => $this->whenHas('slug'),
            'status' => $this->whenHas('status'),
            'position' => $this->whenHas('position'),
            'parent_id' => $this->whenHas('parent_id'),
            'noindex' => $this->whenHas('noindex'),
            'updated_at' => $this->whenHas('updated_at'),

            // Los tres del catálogo: dos agregados de `asCatalogue()` y las rutas retiradas, que
            // son las que permiten redirigir un enlace viejo en vez de devolver un 404.
            'posts_count' => $this->whenCounted('posts'),
            'last_published_at' => $this->whenHas('last_published_at'),
            'retired_slugs' => $this->whenLoaded('retiredSlugs'),

            // La madre, por lo que se pinta de ella: sale solo si la relación se ha incluido.
            'parent_slug' => $this->whenLoaded('parent', fn () => $this->resource->parent?->slug),
            'parent_name' => $this->whenLoaded('parent', fn () => $this->resource->parent?->name),
        ];
    }
}
