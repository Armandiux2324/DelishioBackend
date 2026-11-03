<?php

namespace App\Support;

use App\Models\Restaurant;
use Closure;
use Illuminate\Support\Facades\Auth;

class RestaurantContext
{
    private ?int $restaurantId = null;

    public function id(): int
    {
        if ($this->restaurantId !== null) {
            return $this->restaurantId;
        }

        $user = Auth::guard('sanctum')->user();
        abort_unless($user?->user_type === 'employee', 403, __('Se requiere contexto de restaurante'));
        $user->ensureCanAccess();

        return (int) $user->employee->restaurant_id;
    }

    // Para altas del Super Admin y trabajos sin sesión; nunca recibe IDs del request.
    public function run(Restaurant $restaurant, Closure $callback): mixed
    {
        abort_unless($restaurant->exists && ! $restaurant->trashed(), 403, __('Restaurante no disponible'));
        $previous = $this->restaurantId;
        $this->restaurantId = (int) $restaurant->id;

        try {
            return $callback();
        } finally {
            $this->restaurantId = $previous;
        }
    }
}
