<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\SuperAdmin\RestaurantController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    })->middleware('auth:sanctum');

    Route::prefix('auth')->group(function () {
        Route::post('/register', [RegisteredUserController::class, 'store'])->middleware('throttle:6,1');
        Route::post('/verify-email', [VerifyEmailController::class, 'verify'])->middleware('throttle:6,1');
        Route::post('/verify-email/resend', [VerifyEmailController::class, 'resend'])->middleware('throttle:3,1');

        Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:6,1');
        Route::post('/login/verify-2fa', [AuthenticatedSessionController::class, 'verifyTwoFactor'])->middleware('throttle:6,1');
        Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->middleware('auth:sanctum');

        Route::post('/password/forgot', [PasswordResetLinkController::class, 'store'])->middleware('throttle:3,1');
        Route::post('/password/reset', [PasswordResetController::class, 'store'])->middleware('throttle:6,1');

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/2fa/enable', [TwoFactorController::class, 'enable']);
            Route::post('/2fa/disable', [TwoFactorController::class, 'disable']);
        });

        Route::middleware(['auth:sanctum', 'role:super_admin'])->prefix('super-admin')->group(function () {
            Route::apiResource('restaurants', RestaurantController::class)->except(['destroy'])->middleware('throttle:30,1');
            Route::delete('/restaurants/{restaurant}', [RestaurantController::class, 'destroy'])->middleware('throttle:10,1');
            Route::post('/restaurants/{restaurant}/activate', [RestaurantController::class, 'activate']);
            Route::post('/restaurants/{restaurant}/suspend', [RestaurantController::class, 'suspend']);
        });
    });
});