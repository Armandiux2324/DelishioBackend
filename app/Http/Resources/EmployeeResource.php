<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'user_id' => $this->user_id,
            'restaurant_id' => $this->restaurant_id,
            'name' => $this->user->name,
            'username' => $this->user->username,
            'email' => $this->user->email,
            'locale' => $this->user->locale,
            'active' => $this->user->active,
            'phone' => $this->phone,
            'role' => $this->role,
            'station' => $this->station,
            'hourly_rate' => $this->hourly_rate,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
