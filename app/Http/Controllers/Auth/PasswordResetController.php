<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use App\Models\VerificationCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class PasswordResetController extends Controller
{
    public function store(ResetPasswordRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $user = User::where('email', $request->email)->lockForUpdate()->first();

            if (! $user) {
                return response()->json(['message' => __('Código inválido')], 400);
            }

            $verification = VerificationCode::consume($user->id, 'password_reset', $request->code);

            if (is_string($verification)) {
                return response()->json(['message' => $verification], 400);
            }

            $user->update(['password' => $request->password]);
            $user->verificationCodes()->where('used', false)->update(['used' => true]);

            // Invalida todas las sesiones existentes: si la contraseña se está
            // reseteando, cualquier token emitido antes queda sin confianza.
            $user->tokens()->delete();

            return response()->json(['message' => __('Contraseña actualizada correctamente')]);
        });
    }
}
