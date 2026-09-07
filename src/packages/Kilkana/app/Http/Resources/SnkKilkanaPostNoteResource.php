<?php

namespace Packages\Kilkana\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Una nota de redacción sobre un bloque de la pieza, con quién la escribió. */
class SnkKilkanaPostNoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->whenHas('id'),
            'block' => $this->whenHas('block'),
            'body' => $this->whenHas('body'),
            'done' => $this->whenHas('done'),
            'created_at' => $this->whenHas('created_at'),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->resource->user?->getKey(),
                'name' => $this->resource->user?->name,
            ]),
        ];
    }
}
