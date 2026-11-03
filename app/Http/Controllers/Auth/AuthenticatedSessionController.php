<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Mail\SendVerificationCode;
use App\Models\User;
use App\Models\VerificationCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AuthenticatedSessionController extends Controller
{
    /**
     * RFU-001 - Iniciar sesión.
     * RFU-002 - El Super Administrador siempre pasa por 2FA, sin importar
     * el valor guardado en two_factor_enabled; cualquier otro usuario solo
     * si lo activó para su propia cuenta.
     */
    public function store(LoginRequest $request): JsonResponse
    {
        $login = $request->input('login', $request->email);
        $field = str_contains($login, '@') ? 'email' : 'username';
        $user = User::where($field, $login)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json(['message' => __('Credenciales inválidas')], 401);
        }

        app()->setLocale($user->preferredLocale());
        $user->ensureCanAccess();

        if ($user->user_type === 'super_admin' || $user->two_factor_enabled) {
            $verification = VerificationCode::generate($user->id, 'login_2fa');

            Mail::to($user)->send(
                new SendVerificationCode($verification->code, 'Código de inicio de sesión')
            );

            $ticket = Str::random(40);
            Cache::put("2fa-ticket:{$ticket}", [
                'user_id' => $user->id,
                'verification_id' => $verification->id,
            ], now()->addMinutes(10));

            return response()->json([
                'requires_2fa' => true,
                'ticket' => $ticket,
            ]);
        }

        return response()->json($this->tokenResponse($user));
    }

    /**
     * Segundo paso del login cuando requires_2fa fue true.
     */
    public function verifyTwoFactor(Request $request): JsonResponse
    {
        $request->validate([
            'ticket' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $challenge = Cache::get("2fa-ticket:{$request->ticket}");

        if (! is_array($challenge)) {
            return response()->json(['message' => __('Ticket inválido o expirado')], 401);
        }

        return DB::transaction(function () use ($request, $challenge) {
            $user = User::whereKey($challenge['user_id'])->lockForUpdate()->firstOrFail();
            app()->setLocale($user->preferredLocale());
            $user->ensureCanAccess();
            $verification = VerificationCode::consume($user->id, 'login_2fa', $request->code, $challenge['verification_id']);

            if (is_string($verification)) {
                return response()->json(['message' => $verification], 400);
            }

            Cache::forget("2fa-ticket:{$request->ticket}");

            return response()->json($this->tokenResponse($user));
        });
    }

    /**
     * RFU-004 - Cerrar sesión: revoca solo el token usado en este request.
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => __('Sesión cerrada')]);
    }

    private function tokenResponse(User $user): array
    {
        $relation = match ($user->user_type) {
            'employee' => 'employee.restaurant',
            'customer' => 'customer',
            default => null,
        };

        return [
            'access_token' => $user->createToken('auth')->plainTextToken,
            'user' => $relation ? $user->load($relation) : $user,
        ];
    }
}
