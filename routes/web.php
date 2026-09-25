<?php

use App\Http\Controllers\Auth\AdminSessionController;
use App\Http\Controllers\Auth\CustomerOAuthController;
use Illuminate\Support\Facades\Route;

Route::post('/admin/login', [AdminSessionController::class, 'login'])
    ->middleware('throttle:10,1');
Route::post('/admin/logout', [AdminSessionController::class, 'logout'])
    ->middleware('auth:admin');
Route::post('/admin/password/forgot', [AdminSessionController::class, 'forgotPassword'])
    ->middleware('throttle:5,1');
Route::post('/admin/password/reset', [AdminSessionController::class, 'resetPassword'])
    ->middleware('throttle:5,1');

Route::get('/auth/{provider}/redirect', [CustomerOAuthController::class, 'redirect'])
    ->whereIn('provider', ['google', 'facebook'])
    ->middleware('throttle:20,1');
Route::get('/auth/{provider}/callback', [CustomerOAuthController::class, 'callback'])
    ->whereIn('provider', ['google', 'facebook'])
    ->middleware('throttle:20,1');
Route::post('/auth/logout', [CustomerOAuthController::class, 'logout'])
    ->middleware('auth:customer');

Route::get('{any?}', function() {
    return view('application');
})->where('any', '.*');
