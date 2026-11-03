<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RestaurantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'address' => $this->address,
            'phone' => $this->phone,
            'logo_url' => $this->logo_url,
            'schedule' => $this->schedule,
            'currency' => $this->currency,
            'status' => $this->status,
            'created_at' => $this->created_at,
        ];
    }
}