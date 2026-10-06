<?php

use App\Http\Controllers\Auth\CustomerOAuthController;
use App\Http\Controllers\Public\HomeController;
use App\Services\Settings\ProjectSettingsService;
use Illuminate\Support\Facades\Route;

Route::get('/auth/{provider}/redirect', [CustomerOAuthController::class, 'redirect'])
    ->whereIn('provider', ['google', 'facebook'])
    ->middleware('throttle:20,1');
Route::get('/auth/{provider}/callback', [CustomerOAuthController::class, 'callback'])
    ->whereIn('provider', ['google', 'facebook'])
    ->middleware('throttle:20,1');
Route::post('/auth/logout', [CustomerOAuthController::class, 'logout'])
    ->middleware('auth:customer');

// Public website renders with Blade so the catalog, blog and landing pages stay
// server-rendered for SEO. This boundary is registered before the admin SPA so
// a future public route can never be shadowed by the catch-all below.
Route::get('/', [HomeController::class, 'index'])->name('home');

// Nội dung robots được đọc từ Settings, không nhận HTML/script hoặc đường dẫn file.
Route::get('/robots.txt', function (ProjectSettingsService $settings) {
    return response($settings->effective('seo')['robots_txt'], 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
});

// Admin SPA boundary. The Vue router mounts under this prefix, so no arbitrary
// root URL can boot the admin application.
Route::view('/admin', 'admin')->name('admin.login');
Route::get('/admin/{any?}', function () {
    return view('admin');
})->where('any', '.*');
