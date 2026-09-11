<?php

namespace Packages\Kilkana\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Packages\Kilkana\Models\SnkKilkanaActivityModel;

/**
 * Una línea del registro.
 *
 * `system` no es una columna: es que no haya cuenta detrás y el actor sea el nombre reservado.
 * Se calcula aquí porque es una pregunta sobre la fila, no un dato suyo — y el panel la pinta
 * con otro icono, así que dársela hecha evita que el frontend repita la regla.
 */
class SnkKilkanaActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->whenHas('id', fn () => (string) $this->resource->id),
            'who' => $this->whenHas('actor_name'),
            'role' => $this->whenHas('actor_role'),
            'system' => $this->whenHas('user_id', fn () => $this->resource->user_id === null
                && $this->resource->actor_name === SnkKilkanaActivityModel::SYSTEM),
            'kind' => $this->whenHas('kind'),
            'verb' => $this->whenHas('verb'),
            'what' => $this->whenHas('what'),
            'params' => $this->whenHas('params'),
            'before' => $this->whenHas('before'),
            'after' => $this->whenHas('after'),
            'href' => $this->whenHas('href'),
            'at' => $this->whenHas('created_at', fn () => $this->resource->created_at->toIso8601String()),
        ];
    }
}
