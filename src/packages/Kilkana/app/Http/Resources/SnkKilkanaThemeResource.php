<?php

namespace Packages\Kilkana\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un tema del catálogo: su ficha y el spec con el que se viste el periódico.
 *
 * `spec` sale como objeto **también cuando está vacío**. En PHP una lista vacía y un mapa vacío
 * son el mismo `[]`, y `json_encode` lo escribe como array: el frontend recibe `[]` donde espera
 * `{}` y el tema no se aplica. Es la misma trampa que deja sin abrir una pieza con un `config`
 * vacío en un bloque.
 */
class SnkKilkanaThemeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->whenHas('id'),
            'slug' => $this->whenHas('slug'),
            'name' => $this->whenHas('name'),
            'tagline' => $this->whenHas('tagline'),
            'description' => $this->whenHas('description'),
            'status' => $this->whenHas('status', fn () => $this->resource->status?->value),
            'featured' => $this->whenHas('featured', fn () => (bool) $this->resource->featured),
            'position' => $this->whenHas('position'),
            'popularity' => $this->whenHas('popularity'),
            'version' => $this->whenHas('version'),
            'thumbnail_path' => $this->whenHas('thumbnail_path'),
            'screenshots' => $this->whenHas('screenshots', fn () => $this->resource->screenshots ?? []),
            'demo_url' => $this->whenHas('demo_url'),
            'spec' => $this->whenHas('spec', fn () => (object) ($this->resource->spec ?? [])),
            'published_at' => $this->whenHas('published_at', fn () => $this->resource->published_at?->toIso8601String()),
            'categories' => $this->whenLoaded('categories', fn () => $this->resource->categories
                ->map(fn ($c) => ['slug' => $c->slug, 'name' => $c->name])->values()),
        ];
    }
}
