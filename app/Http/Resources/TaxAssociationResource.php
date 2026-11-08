<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaxAssociationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $category = $this->category;
        $superCategory = $category?->superCategory;

        return [
            'menu_item_id' => $this->id,
            'menu_item_name' => $this->name,
            'active' => $this->active,
            'category' => $category?->only(['id', 'name']),
            'super_category' => $superCategory?->only(['id', 'name']),
            'tax_class' => $superCategory?->taxClass?->only(['id', 'name', 'percentage']),
        ];
    }
}
