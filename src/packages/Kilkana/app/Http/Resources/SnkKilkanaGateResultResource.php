<?php

namespace Packages\Kilkana\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * El resultado de la puerta de salida para una URL.
 *
 * `passing` va nulo mientras no se haya mirado: la lista de comprobaciones existe siempre, y
 * mandarla vacía se leería como «todo falla» en vez de «todavía no se ha pasado».
 */
class SnkKilkanaGateResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'url' => $this->whenHas('url'),
            'kind' => $this->whenHas('kind'),
            'passing' => $this->whenHas('checked_at', fn () => $this->resource->checked_at === null ? null : $this->resource->passing),
            'checked_at' => $this->whenHas('checked_at', fn () => $this->resource->checked_at?->toIso8601String()),
        ];
    }
}
