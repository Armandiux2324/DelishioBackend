<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'super_category_id' => $this->super_category_id,
            'name' => $this->name,
            'description' => $this->description,
        ];
    }
}
