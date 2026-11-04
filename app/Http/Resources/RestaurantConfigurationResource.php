<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class RestaurantConfigurationResource extends RestaurantResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'loyalty_enabled' => $this->loyalty_enabled,
            'loyalty_factor' => $this->loyalty_factor,
            'loyalty_redeem_value' => $this->loyalty_redeem_value,
            'discount_limit_per_shift' => $this->discount_limit_per_shift,
            'courtesy_percentage' => $this->courtesy_percentage,
            'courtesy_daily_limit' => $this->courtesy_daily_limit,
            'dual_pricing_enabled' => $this->dual_pricing_enabled,
            'dual_pricing_percentage' => $this->dual_pricing_percentage,
        ];
    }
}
