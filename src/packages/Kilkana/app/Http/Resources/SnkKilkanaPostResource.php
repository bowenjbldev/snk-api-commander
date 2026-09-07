<?php

namespace Packages\Kilkana\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * La forma de una pieza, declarada aquí y **decidida por la consulta**.
 *
 * Cada campo va con `whenHas()`, que es `array_key_exists($campo, $modelo->getAttributes())`:
 * lo que la consulta no seleccionó sale **ausente**, no `null`. Esa diferencia es la que hacía
 * falta — con la lista blanca de `setVisible()` que esto sustituye, quien lee no distinguía
 * «no lo pedí» de «está vacío», y seis campos del listado llegaron a viajar como `undefined`
 * sin que fallara nada en ninguno de los dos lados.
 *
 * De aquí se sigue que **no hay una lista de campos por defecto**: la elige quien llama con
 * `?fields=`, dentro del techo que el controlador declara en `$allowedFields`. Antes la elegía
 * `FILA_DEL_LISTADO`, una constante del servidor que el frontend tenía que adivinar.
 */
class SnkKilkanaPostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->whenHas('id'),
            'title' => $this->whenHas('title'),
            'excerpt' => $this->whenHas('excerpt'),
            'slug' => $this->whenHas('slug'),
            'status' => $this->whenHas('status'),
            'content_tier' => $this->whenHas('content_tier'),
            'publish_at' => $this->whenHas('publish_at'),
            // Columna de verdad, no el objeto `cover` que apéndía el modelo: el panel solo
            // guarda el id para pedirle la imagen a la mediateca.
            'cover_media_id' => $this->whenHas('cover_media_id'),
            // El alias del `withSum` de audiencia. Solo está si la consulta lo pidió, y por eso
            // se declara igual que una columna: para el Resource no hay diferencia.
            'views_total' => $this->whenHas('views_total'),

            'unpublish_at' => $this->whenHas('unpublish_at'),
            'updated_at' => $this->whenHas('updated_at'),
            'noindex' => $this->whenHas('noindex'),
            'seo_title' => $this->whenHas('seo_title'),
            'seo_description' => $this->whenHas('seo_description'),
            'og_title' => $this->whenHas('og_title'),
            'og_description' => $this->whenHas('og_description'),
            'og_image_id' => $this->whenHas('og_image_id'),
            'body_blocks' => $this->whenHas('body_blocks'),

            /*
             * Derivados: no se piden por su nombre porque no son columnas, y salen cuando la
             * columna de la que dependen está. Con `?fields=` a secas se pedirían como
             * `kilkana_posts.url_path` y la consulta moriría con un `no such column`.
             *
             * `url_path` lo compone el modelo y no cada consumidor: es la ÚNICA definición de
             * cómo se direcciona un artículo, y con la fecha repartida en dos columnas
             * cualquier otro sitio que la recomponga acabaría discrepando.
             */
            'url_path' => $this->whenHas('url_date', fn () => $this->resource->url_path),

            'category' => new SnkKilkanaCategoryResource($this->whenLoaded('category')),
            'author' => new SnkKilkanaAuthorResource($this->whenLoaded('author')),
            'tags' => SnkKilkanaTagResource::collection($this->whenLoaded('tags')),
            'cover' => new SnkKilkanaMediaResource($this->whenLoaded('coverMedia')),
            'og_image' => new SnkKilkanaMediaResource($this->whenLoaded('ogImage')),
            'comments_count' => $this->whenCounted('comments'),

            // Las fotos del cuerpo, que resuelve el servicio público: un bloque de imagen lleva
            // solo `media_id` y la calle no tiene con qué pedirlas una a una.
            'media' => $this->whenHas('media'),
        ];
    }
}
