<?php

namespace Packages\Kilkana\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Packages\Kilkana\Models\SnkKilkanaPostModel;

/**
 * Un número del boletín: su cabecera, las piezas que lleva y cómo le fue.
 *
 * Las piezas y las tres cifras de apertura las cuelga quien consulta, **en una tanda para todos
 * los números**: pedirlas número a número eran seis consultas por fila —una por las cifras y
 * cinco por las piezas, que arrastraban las relaciones del modelo— y este listado no tiene cota.
 *
 * `post_ids` sale como cadenas porque el panel las usa de clave, y un entero y su cadena no son
 * la misma clave en JavaScript.
 */
class SnkKilkanaNewsletterIssueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->whenHas('id', fn () => (string) $this->resource->getKey()),
            'subject' => $this->whenHas('subject'),
            'preheader' => $this->whenHas('preheader'),
            'status' => $this->whenHas('status'),
            'sent_at' => $this->whenHas('sent_at', fn () => $this->resource->sent_at?->toIso8601String()),
            'scheduled_at' => $this->whenHas('scheduled_at', fn () => $this->resource->scheduled_at?->toIso8601String()),
            'post_ids' => $this->whenHas('post_ids', fn () => array_map(
                fn ($id) => (string) $id,
                is_array($this->resource->post_ids) ? $this->resource->post_ids : [],
            )),
            'recipients' => $this->whenHas('recipients_count', fn () => (int) $this->resource->recipients_count),
            'test_sent_to' => $this->whenHas('test_sent_to'),
            'test_sent_at' => $this->whenHas('test_sent_at', fn () => $this->resource->test_sent_at?->toIso8601String()),

            'pieces' => $this->whenHas('pieces', fn () => $this->resource->pieces
                ->map(fn (SnkKilkanaPostModel $p) => ['id' => (string) $p->getKey(), 'title' => $p->title])
                ->values()->all()),
            ...$this->resource->getAttributes()['stats'] ?? [],
        ];
    }
}
