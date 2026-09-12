<?php

namespace Packages\Kilkana\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un comentario tal como lo lee la moderación: con la cuenta, la pieza y el padre resueltos.
 *
 * La forma se declara aquí y **no se muta el modelo**: meterle arrays con `setAttribute()` y
 * quitarle relaciones con `unsetRelation()` lo dejaría con atributos que no son columnas y sin
 * relaciones que sí estaban cargadas, y eso lo heredaría cualquiera que lo tocara después.
 *
 * Los tres bloques salen **si su relación se ha incluido**, que es lo que decide quien llama.
 */
class SnkKilkanaCommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->whenHas('id'),
            'body' => $this->whenHas('body'),
            'status' => $this->whenHas('status'),
            'created_at' => $this->whenHas('created_at'),
            'parent_id' => $this->whenHas('parent_id'),
            'post_id' => $this->whenHas('post_id'),
            'user_id' => $this->whenHas('user_id'),

            // Quién lo escribió, con su historial. Los dos recuentos los cuelga
            // `SnkKilkanaCommentService::withAuthorHistory()` sobre la cuenta, en una consulta
            // para toda la página: preguntarlo por fila serían cincuenta.
            'author' => $this->whenLoaded('user', fn () => $this->resource->user === null ? null : [
                'id' => (string) $this->resource->user->getKey(),
                'name' => $this->resource->user->full_name,
                'email' => $this->resource->user->email,
                'since' => $this->resource->user->created_at?->toIso8601String(),
                'total' => (int) ($this->resource->user->comments_total ?? 0),
                'rejected' => (int) ($this->resource->user->comments_rejected ?? 0),
                'blocked' => $this->resource->user->suspended_at !== null,
            ]),

            'on' => $this->whenLoaded('post', fn () => $this->resource->post === null ? null : [
                'id' => (string) $this->resource->post->getKey(),
                'title' => $this->resource->post->title,
            ]),

            // A quién responde: el nombre de quien escribió el padre y su texto, que es lo que
            // el panel enseña encima para dar contexto sin abrir el hilo.
            'reply_to' => $this->whenLoaded('parent', fn () => $this->resource->parent === null ? null : [
                'author' => $this->resource->parent->user?->full_name,
                'body' => $this->resource->parent->body,
            ]),
        ];
    }
}
