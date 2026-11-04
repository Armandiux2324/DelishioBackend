<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MenuItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'name' => $this->name,
            'sale_price' => $this->sale_price,
            'description' => $this->description,
            'image_url' => $this->image_url,
            'legal_age_required' => $this->legal_age_required,
            'preparation_station' => $this->preparation_station,
            'active' => $this->active,
            'ingredients' => $this->whenLoaded('ingredients', fn () => $this->ingredients->sortBy('inventory_item_id')->values()->map(fn ($ingredient) => [
                'inventory_item_id' => $ingredient->inventory_item_id,
                'quantity' => $ingredient->quantity,
            ])),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
