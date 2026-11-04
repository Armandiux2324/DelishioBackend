<?php

use App\Http\Controllers\Api\Admin\CategoryController;
use App\Http\Controllers\Api\Admin\RestaurantConfigurationController;
use App\Http\Controllers\Api\Admin\SuperCategoryController;
use App\Http\Controllers\Api\Admin\TaxClassController;
use App\Http\Controllers\Api\RestaurantController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::middleware(['auth:sanctum', 'account.active', 'role:admin'])->prefix('admin')->group(function () {
        Route::apiResource('tax-classes', TaxClassController::class);
        Route::apiResource('super-categories', SuperCategoryController::class);
        Route::apiResource('categories', CategoryController::class);
        Route::get('/restaurant/configuration', [RestaurantConfigurationController::class, 'show']);
        Route::patch('/restaurant/configuration', [RestaurantConfigurationController::class, 'update']);
    });

    Route::get('/user', function (Request $request) {
        return $request->user();
    })->middleware(['auth:sanctum', 'account.active']);

    Route::prefix('auth')->group(function () {
        Route::post('/register', [RegisteredUserController::class, 'store'])->middleware('throttle:6,1');
        Route::post('/verify-email', [VerifyEmailController::class, 'verify'])->middleware('throttle:6,1');
        Route::post('/verify-email/resend', [VerifyEmailController::class, 'resend'])->middleware('throttle:3,1');

        Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:6,1');
        Route::post('/login/verify-2fa', [AuthenticatedSessionController::class, 'verifyTwoFactor'])->middleware('throttle:6,1');
        Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->middleware('auth:sanctum');

        Route::post('/password/forgot', [PasswordResetLinkController::class, 'store'])->middleware('throttle:3,1');
        Route::post('/password/reset', [PasswordResetController::class, 'store'])->middleware('throttle:6,1');

        Route::middleware(['auth:sanctum', 'account.active'])->group(function () {
            Route::post('/2fa/enable', [TwoFactorController::class, 'enable']);
            Route::post('/2fa/disable', [TwoFactorController::class, 'disable']);
        });

        Route::middleware(['auth:sanctum', 'account.active', 'role:super_admin'])->prefix('super-admin')->group(function () {
            Route::apiResource('restaurants', RestaurantController::class)->except(['destroy'])->middleware('throttle:30,1');
            Route::delete('/restaurants/{restaurant}', [RestaurantController::class, 'destroy'])->middleware('throttle:10,1');
            Route::post('/restaurants/{restaurant}/activate', [RestaurantController::class, 'activate']);
            Route::post('/restaurants/{restaurant}/suspend', [RestaurantController::class, 'suspend']);
        });
    });
});
