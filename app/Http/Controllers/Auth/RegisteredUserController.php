<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Mail\SendVerificationCode;
use App\Models\Customer;
use App\Models\User;
use App\Models\VerificationCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class RegisteredUserController extends Controller
{
    /**
     * RFCL-001 - Registro de Cuenta.
     *
     * Este endpoint es exclusivo para Cliente: Mesero y Chef los da de alta
     * el Administrador (RFA-021), el Administrador inicial lo da de alta el
     * Super Administrador (RFSA-001), y Super Admin no se autorregistra.
     */
    public function store(RegisterRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request) {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => $request->password,
                'user_type' => 'customer',
            ]);

            Customer::create([
                'user_id' => $user->id,
                'phone' => $request->phone,
            ]);

            return $user;
        });

        $verification = VerificationCode::generate($user->id, 'email_verification');

        Mail::to($user->email)->send(
            new SendVerificationCode($verification->code, 'Verifica tu cuenta')
        );

        return response()->json([
            'message' => 'Cuenta creada. Revisa tu correo para verificarla.',
        ], 201);
    }
}