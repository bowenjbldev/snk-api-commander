<?php

namespace Packages\Kilkana\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Un estante de la mediateca: el id para escribir, el nombre para leer, y sus cifras si se han contado. */
class SnkKilkanaMediaCollectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->whenHas('id'),
            'name' => $this->whenHas('name'),
            'media_count' => $this->whenHas('media_count'),
            'missing_alt_count' => $this->whenHas('missing_alt_count'),
            'media_max_created_at' => $this->whenHas('media_max_created_at'),
        ];
    }
}
