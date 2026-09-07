<?php

namespace Packages\Kilkana\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * La firma tal y como se publica.
 *
 * `name` **no es una columna**: es el alias si firma con seudónimo y el de su cuenta si no, y esa
 * regla vive en el modelo porque decide qué se puede decir de quién. Sale con `alias`, que es la
 * columna de la que arranca — no se puede pedir por su nombre, porque no existe.
 *
 * Lo que ya no puede pasar: `user_id` **decía quién tiene cuenta**. La portada pública anida la
 * firma de cada pieza, así que cualquiera podía separar las firmas con asiento de las que no
 * —la distinción que el panel reserva a quien administra cuentas— y de paso contar cuántas
 * cuentas hay y en qué orden entraron. Antes lo tapaba un `$hidden` del modelo, global y por
 * tanto igual para las tres puertas; ahora simplemente no está declarado aquí.
 */
class SnkKilkanaAuthorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->whenHas('id'),
            'slug' => $this->whenHas('slug'),
            'name' => $this->whenHas('alias', fn () => $this->resource->name),
            'kind' => $this->whenHas('kind'),
            'job_title' => $this->whenHas('job_title'),
            'bio' => $this->whenHas('bio'),
            'website' => $this->whenHas('website'),
            'public_email' => $this->whenHas('public_email'),
            'socials' => $this->whenHas('socials'),
            'noindex' => $this->whenHas('noindex'),
            'redirect_to' => $this->whenHas('redirect_to'),

            // El asiento con el que cruza, que es una subconsulta de `asCatalogue()`: el correo
            // de acceso si esta firma tiene cuenta en la redacción.
            'seat' => $this->whenHas('seat'),
            'posts_count' => $this->whenCounted('posts'),
            'last_published_at' => $this->whenHas('last_published_at'),
            'retired_slugs' => $this->whenLoaded('retiredSlugs'),
            'avatar_media' => new SnkKilkanaMediaResource($this->whenLoaded('avatarMedia')),
        ];
    }
}
