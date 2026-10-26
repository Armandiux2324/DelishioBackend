<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class TwoFactorController extends Controller
{
    /**
     * Habilita 2FA (email-OTP) para la cuenta autenticada. No aplica a
     * Super Administrador porque ya lo tiene forzado de forma permanente
     * (RFU-002), no es un estado que se pueda togglear para ese rol.
     */
    public function enable(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->user_type === 'super_admin') {
            return response()->json([
                'message' => 'La autenticación de dos factores ya está activa de forma permanente para Super Administrador',
            ], 422);
        }

        $request->validate(['password' => ['required', 'string']]);

        if (!Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Contraseña incorrecta'], 422);
        }

        $user->update(['two_factor_enabled' => true]);

        return response()->json(['message' => 'Autenticación de dos factores activada']);
    }

    public function disable(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->user_type === 'super_admin') {
            return response()->json([
                'message' => 'La autenticación de dos factores no se puede desactivar para Super Administrador',
            ], 422);
        }

        $request->validate(['password' => ['required', 'string']]);

        if (!Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Contraseña incorrecta'], 422);
        }

        $user->update(['two_factor_enabled' => false]);

        return response()->json(['message' => 'Autenticación de dos factores desactivada']);
    }
}