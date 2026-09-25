<?php

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
    });
