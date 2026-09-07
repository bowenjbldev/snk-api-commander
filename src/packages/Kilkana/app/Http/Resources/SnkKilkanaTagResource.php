<?php

namespace Packages\Kilkana\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SnkKilkanaTagResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->whenHas('id'),
            'name' => $this->whenHas('name'),
            'slug' => $this->whenHas('slug'),
            'posts_count' => $this->whenCounted('posts'),
            'last_used_at' => $this->whenHas('last_used_at'),
        ];
    }
}
