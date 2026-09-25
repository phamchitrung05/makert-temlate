<?php

use App\Http\Controllers\Auth\SanctumTokenController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth:admin', 'account.active:admin'])
    ->prefix('admin')
    ->group(function (): void {
        Route::get('/me', fn () => response()->json(['user' => request()->user('admin')]));
    });

Route::middleware(['web', 'auth:customer', 'account.active:customer'])
    ->prefix('account')
    ->group(function (): void {
        Route::get('/me', fn () => response()->json(['user' => request()->user('customer')]));
        Route::post('/token', [SanctumTokenController::class, 'issueCustomerToken']);
    });

Route::middleware(['auth:sanctum', 'account.active:sanctum'])
    ->prefix('auth/token')
    ->group(function (): void {
        Route::post('/revoke', [SanctumTokenController::class, 'revokeCurrentToken']);
        Route::post('/revoke-all', [SanctumTokenController::class, 'revokeAllTokens']);
    });
