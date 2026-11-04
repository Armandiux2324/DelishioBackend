<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use App\Support\RestaurantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AdminPinAuthorization
{
    public function authorize(int $adminUserId, string $pin, string $action): Employee
    {
        $requester = User::whereKey(Auth::guard('sanctum')->id())->first();
        abort_unless($requester?->user_type === 'employee', 403);
        $requester->ensureCanAccess();
        $restaurantId = app(RestaurantContext::class)->id();
        abort_unless((int) $requester->employee->restaurant_id === $restaurantId, 403);
        Validator::make(['action' => $action], ['action' => ['required', 'string', 'max:255']])->validate();
        $key = 'admin-pin:'.$restaurantId.':'.$requester->id;
        abort_if(RateLimiter::tooManyAttempts($key, 5), 429, __('Demasiados intentos de PIN. Intenta más tarde.'), [
            'Retry-After' => (string) RateLimiter::availableIn($key),
        ]);
        RateLimiter::hit($key, 60);
        $admin = Employee::with('user')->whereKey($adminUserId)->where('role', 'admin')
            ->whereHas('user', fn ($query) => $query->where('user_type', 'employee')->where('active', true)->whereNotNull('email_verified_at'))->first();

        if (! preg_match('/\\A[0-9]{4,6}\\z/', $pin) || ! $admin || ! Hash::check($pin, $admin->pin)) {
            throw ValidationException::withMessages(['pin' => __('PIN de administrador inválido.')]);
        }

        RateLimiter::clear($key);
        // Identify the PIN owner without persisting the credential itself.
        Log::info('admin_pin_authorized', [
            'restaurant_id' => $restaurantId,
            'requested_by_employee_id' => $requester->id,
            'authorized_by_employee_id' => $admin->user_id,
            'action' => $action,
            'authorized_at' => now()->toIso8601String(),
        ]);

        return $admin;
    }
}
