<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PromotionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'restaurant_id' => $this->restaurant_id, 'name' => $this->name,
            'condition_type' => $this->condition_type, 'condition_value' => $this->condition_value,
            'discount_type' => $this->discount_type, 'discount_value' => $this->discount_value,
            'days_of_week' => $this->days_of_week, 'time_start' => $this->time_start, 'time_end' => $this->time_end,
            'valid_from' => $this->valid_from, 'valid_to' => $this->valid_to, 'active' => $this->active,
            'menu_item_ids' => $this->whenLoaded('menuItems', fn () => $this->menuItems->pluck('id')->sort()->values()),
            'category_ids' => $this->whenLoaded('categories', fn () => $this->categories->pluck('id')->sort()->values()),
        ];
    }
}
