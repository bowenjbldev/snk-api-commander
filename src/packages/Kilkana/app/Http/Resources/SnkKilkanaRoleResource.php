<?php

namespace Packages\Kilkana\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Packages\Kilkana\Services\SnkKilkanaRoleCatalogService;

/**
 * Un papel de la redacción: qué permite, cuánta gente lo lleva y si ocupa asiento.
 *
 * `seat` sale del catálogo y no de la fila: hay papeles que no consumen plaza del plan, y esa
 * lista la gobierna `SnkKilkanaRoleCatalogService`. El recuento de cuentas lo cuelga quien
 * consulta, en una sola consulta para todos los papeles.
 */
class SnkKilkanaRoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->whenHas('name'),
            'permissions' => $this->whenLoaded('permissions', fn () => $this->resource->permissions->pluck('name')->sort()->values()),
            'users_count' => $this->whenHas('users_count'),
            'seat' => $this->whenHas('name', fn () => ! in_array($this->resource->name, SnkKilkanaRoleCatalogService::WITHOUT_SEAT, true)),
        ];
    }
}
