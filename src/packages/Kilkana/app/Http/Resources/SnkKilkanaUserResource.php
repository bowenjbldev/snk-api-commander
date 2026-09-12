<?php

namespace Packages\Kilkana\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una cuenta de la redacción, con su papel plano y su estado.
 *
 * Ninguno de los dos es una columna. El papel es el primero de la relación —aquí una cuenta
 * lleva uno— y el estado es la lectura de dos sellos: suspendida gana a invitada, e invitada a
 * activa. Se dan hechos porque los pinta el listado y cada acción, y calcularlo en el frontend
 * sería repetir esa precedencia en el otro lado.
 */
class SnkKilkanaUserResource extends JsonResource
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
            'last_active_at' => $this->whenHas('last_active_at'),
            'posts_count' => $this->whenCounted('posts'),

            'role' => $this->whenLoaded('roles', fn () => $this->resource->roles->first()?->name),
            /*
             * Atado a la clave y **no a los dos sellos que lee**: son columnas anulables, y una
             * cuenta recién creada no las lleva en sus atributos —`create()` solo guarda lo que
             * se le pasó—, así que condicionarlo a ellas dejaría el alta sin estado. Se leen del
             * array crudo por lo mismo: pedirlas por su nombre reventaría el día que se encienda
             * `preventAccessingMissingAttributes()`.
             */
            'state' => $this->whenHas('id', fn () => match (true) {
                ($this->resource->getAttributes()['suspended_at'] ?? null) !== null => 'suspended',
                ($this->resource->getAttributes()['invited_at'] ?? null) !== null => 'invited',
                default => 'active',
            }),
        ];
    }
}
