<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\SendVerificationCode;
use App\Models\User;
use App\Models\VerificationCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class VerifyEmailController extends Controller
{
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['message' => 'Usuario no encontrado'], 404);
        }

        $verification = VerificationCode::check($user->id, 'email_verification', $request->code);

        if (is_string($verification)) {
            return response()->json(['message' => $verification], 400);
        }

        $verification->update(['used' => true]);
        $user->update(['email_verified_at' => now()]);

        return response()->json(['message' => 'Correo verificado correctamente']);
    }

    /**
     * Reenvía el código si el usuario no lo recibió o expiró.
     */
    public function resend(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $user = User::where('email', $request->email)->first();

        if (!$user || $user->email_verified_at) {
            // misma respuesta exista o no el usuario / ya esté verificado, para no filtrar qué correos están registrados
            return response()->json(['message' => 'Si la cuenta existe, se envió un nuevo código.']);
        }

        $verification = VerificationCode::generate($user->id, 'email_verification');

        Mail::to($user->email)->send(
            new SendVerificationCode($verification->code, 'Verifica tu cuenta')
        );

        return response()->json(['message' => 'Si la cuenta existe, se envió un nuevo código.']);
    }
}