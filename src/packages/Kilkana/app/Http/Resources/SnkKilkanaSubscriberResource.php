<?php

namespace Packages\Kilkana\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una dirección de la lista, con de dónde salió y cuándo lo consintió.
 *
 * El sello del consentimiento no es adorno: es lo que permite demostrar el origen de cada
 * dirección, y por eso viaja con ella en vez de vivir solo en el CSV del export.
 */
class SnkKilkanaSubscriberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->whenHas('id'),
            'email' => $this->whenHas('email'),
            'status' => $this->whenHas('status'),
            'consent_source' => $this->whenHas('consent_source'),
            'consent_at' => $this->whenHas('consent_at'),
            'confirmed_at' => $this->whenHas('confirmed_at'),
            'unsubscribed_at' => $this->whenHas('unsubscribed_at'),
            'created_at' => $this->whenHas('created_at'),
        ];
    }
}
