<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use App\Support\RestaurantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AdminPinAuthorization
{
    public function authorize(?int $adminUserId, string $pin, string $action): Employee
    {
        $authenticated = Auth::guard('sanctum')->user();
        $requester = User::whereKey($authenticated?->id)->first();
        abort_unless($requester?->user_type === 'employee', 403);
        $requester->ensureCanAccess();
        $restaurantId = app(RestaurantContext::class)->id();
        abort_unless((int) $requester->employee->restaurant_id === $restaurantId, 403);
        Validator::make(['action' => $action], ['action' => ['required', 'string', 'max:255']])->validate();

        $token = $authenticated->currentAccessToken();
        $session = match (true) {
            $token instanceof PersonalAccessToken => 'token:'.$token->getKey(),
            request()->hasSession() => 'session:'.hash('sha256', request()->session()->getId()),
            default => 'user:'.$requester->id,
        };
        $key = 'admin-pin:'.$restaurantId.':'.$session;

        $admin = Cache::lock($key.':lock', 60)->get(function () use ($key, $adminUserId, $pin) {
            abort_if(RateLimiter::tooManyAttempts($key, 5), 429, __('Demasiados intentos de PIN. Intenta más tarde.'), [
                'Retry-After' => (string) RateLimiter::availableIn($key),
            ]);
            RateLimiter::hit($key, 60);
            $matches = preg_match('/\A[0-9]{4,6}\z/', $pin) ? $this->matchingAdmins($pin) : collect();
            $admin = $matches->count() === 1 ? $matches->first() : null;
            if (! $admin?->user?->active || $admin->user->email_verified_at === null
                || ($adminUserId !== null && $adminUserId !== $admin->user_id)) {
                throw ValidationException::withMessages(['pin' => __('PIN de administrador inválido.')]);
            }

            RateLimiter::clear($key);

            return $admin;
        });
        abort_if($admin === false, 429, __('Demasiados intentos de PIN. Intenta más tarde.'), ['Retry-After' => '1']);

        Log::info('admin_pin_authorized', [
            'restaurant_id' => $restaurantId,
            'requested_by_employee_id' => $requester->id,
            'authorized_by_employee_id' => $admin->user_id,
            'action' => $action,
            'authorized_at' => now()->toIso8601String(),
        ]);

        return $admin;
    }

    /** @return Collection<int, Employee> */
    public function matchingAdmins(string $pin, ?int $except = null): Collection
    {
        return Employee::with('user')->where('role', 'admin')
            ->whereHas('user', fn ($query) => $query->where('user_type', 'employee'))
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))
            ->get()->filter(fn (Employee $admin) => Hash::check($pin, $admin->pin));
    }
}
