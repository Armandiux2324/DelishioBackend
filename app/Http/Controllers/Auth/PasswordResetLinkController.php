<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\SendVerificationCode;
use App\Models\User;
use App\Models\VerificationCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class PasswordResetLinkController extends Controller
{
    /**
     * Solicita el código para restablecer contraseña. Responde igual exista
     * o no la cuenta, para no filtrar qué correos están registrados
     * (mismo criterio que VerifyEmailController::resend).
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $user = User::where('email', $request->email)->first();

        if ($user) {
            $verification = VerificationCode::generate($user->id, 'password_reset');

            Mail::to($user->email)->send(
                new SendVerificationCode($verification->code, 'Restablece tu contraseña')
            );
        }

        return response()->json([
            'message' => 'Si la cuenta existe, se envió un código para restablecer la contraseña.',
        ]);
    }
}