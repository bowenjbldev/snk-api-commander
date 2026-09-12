<?php

namespace Packages\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una cuenta del control plane.
 *
 * `password` y `remember_token` siguen además en el `$hidden` del modelo, y eso **no sobra**: un
 * Resource se declara puerta a puerta, y quedan caminos que serializan el modelo directo —el
 * historial de una pieza y el de una composición llevan dentro quién la escribió—. El `$hidden`
 * es la red de esos; esto es la forma de las puertas que sí la declaran.
 *
 * `all_permissions` y `role_names` los cuelga `auth/me`, que es la única puerta que los
 * necesita: son la matriz con la que el panel decide qué pinta y qué no.
 */
class CoreUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->whenHas('id'),
            'name' => $this->whenHas('name'),
            'lastname' => $this->whenHas('lastname'),
            'full_name' => $this->resource->full_name,
            'email' => $this->whenHas('email'),
            'company' => $this->whenHas('company'),
            'phone' => $this->whenHas('phone'),
            'invited_at' => $this->whenHas('invited_at'),
            'suspended_at' => $this->whenHas('suspended_at'),

            'roles' => $this->whenLoaded('roles', fn () => $this->resource->roles->pluck('name')->values()),
            'all_permissions' => $this->whenHas('all_permissions'),
            'role_names' => $this->whenHas('role_names'),
        ];
    }
}
