<?php

namespace Packages\Kilkana\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una cuenta lectora tal como la lee el panel.
 *
 * Aquí vive solo la forma de la fila. Las tres consultas por lotes que la alimentan están en
 * `SnkKilkanaReaderService::withActivity()`, y por eso las dos mitades se pueden probar por
 * separado.
 *
 * El `id` sale como cadena, que es contrato: el panel lo usa como clave de fila y de formulario,
 * y un entero y su cadena no son la misma clave en JavaScript.
 */
class SnkKilkanaReaderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // El orden de las claves es contrato: hay pruebas que comparan la fila
        // entera con `assertSame`, que en PHP distingue el orden de las claves.
        return [
            'id' => $this->whenHas('id', fn () => (string) $this->resource->getKey()),
            // Nombre y apellido compuestos: el mismo criterio que el hilo de comentarios. Una
            // cuenta retirada no tiene nombre y sale `null`, que el panel rotula en su idioma.
            'name' => $this->whenHas('name', fn () => $this->resource->full_name !== '' ? $this->resource->full_name : null),
            'email' => $this->whenHas('email'),
            'since' => $this->whenHas('created_at', fn () => $this->resource->created_at?->toIso8601String()),

            // Lo que cuelga `withActivity()`. Para el Resource no hay diferencia entre esto y un
            // `withCount`: es un atributo que está o no está.
            'comments' => $this->whenHas('comments_total'),
            'rejected' => $this->whenHas('comments_rejected'),
            'reads' => $this->whenHas('reads_total'),
            'newsletter' => $this->whenHas('newsletter', fn () => $this->resource->newsletter === null ? null : [
                'status' => $this->resource->newsletter->status->value,
                'consent_source' => $this->resource->newsletter->consent_source?->value,
                'consent_at' => $this->resource->newsletter->consent_at?->toIso8601String(),
            ]),
            'last_seen_at' => $this->whenHas('last_seen_at', fn () => $this->resource->last_seen_at?->toIso8601String()),
            'blocked' => $this->whenHas('suspended_at', fn () => $this->resource->suspended_at !== null),
        ];
    }
}
