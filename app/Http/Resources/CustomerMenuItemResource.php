<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerMenuItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'category_id' => $this->category_id, 'name' => $this->name, 'sale_price' => $this->sale_price,
            'description' => $this->description, 'image_url' => $this->image_url, 'legal_age_required' => $this->legal_age_required,
            'category' => $this->whenLoaded('category', fn () => $this->category?->only(['id', 'name', 'super_category_id'])),
            'tax_percentage' => $this->whenLoaded('category', fn () => $this->category?->superCategory?->taxClass?->percentage)];
    }
}
