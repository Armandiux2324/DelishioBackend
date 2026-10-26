<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use App\Models\VerificationCode;
use Illuminate\Http\JsonResponse;

class PasswordResetController extends Controller
{
    public function store(ResetPasswordRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['message' => 'Código inválido'], 400);
        }

        $verification = VerificationCode::check($user->id, 'password_reset', $request->code);

        if (is_string($verification)) {
            return response()->json(['message' => $verification], 400);
        }

        $verification->update(['used' => true]);
        $user->update(['password' => $request->password]);

        // Invalida todas las sesiones existentes: si la contraseña se está
        // reseteando, cualquier token emitido antes queda sin confianza.
        $user->tokens()->delete();

        return response()->json(['message' => 'Contraseña actualizada correctamente']);
    }
}