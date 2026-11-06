<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerRestaurantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource->only(['id', 'name', 'description', 'address', 'phone', 'logo_url', 'schedule', 'currency',
            'loyalty_enabled', 'loyalty_factor', 'loyalty_redeem_value', 'dual_pricing_enabled', 'dual_pricing_percentage']);
    }
}
