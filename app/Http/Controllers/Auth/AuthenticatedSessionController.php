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
        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Credenciales inválidas'], 401);
        }

        if (!$user->active) {
            return response()->json(['message' => 'Cuenta inactiva'], 403);
        }

        if ($user->user_type === 'super_admin' || $user->two_factor_enabled) {
            $verification = VerificationCode::generate($user->id, 'login_2fa');

            Mail::to($user->email)->send(
                new SendVerificationCode($verification->code, 'Código de inicio de sesión')
            );

            $ticket = Str::random(40);
            Cache::put("2fa-ticket:{$ticket}", $user->id, now()->addMinutes(10));

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

        $userId = Cache::get("2fa-ticket:{$request->ticket}");

        if (!$userId) {
            return response()->json(['message' => 'Ticket inválido o expirado'], 401);
        }

        $verification = VerificationCode::check($userId, 'login_2fa', $request->code);

        if (is_string($verification)) {
            return response()->json(['message' => $verification], 400);
        }

        $verification->update(['used' => true]);
        Cache::forget("2fa-ticket:{$request->ticket}");

        return response()->json($this->tokenResponse(User::findOrFail($userId)));
    }

    /**
     * RFU-004 - Cerrar sesión: revoca solo el token usado en este request.
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada']);
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