<?php

use App\Http\Controllers\Auth\CustomerOAuthController;
use App\Http\Controllers\Public\HomeController;
use App\Http\Controllers\Public\ResourceCatalogController;
use App\Http\Controllers\Public\TemplateDownloadController;
use App\Http\Controllers\Public\TemplatePublicPreviewController;
use App\Http\Controllers\Public\TemplateShareController;
use App\Http\Controllers\Public\SitemapController;
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
    $robots = (string) $settings->effective('seo')['robots_txt'];
    if (! str_contains($robots, 'Sitemap:')) {
        $robots .= "\nSitemap: ".url('/sitemap.xml');
    }

    return response($robots, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
});

Route::get('/sitemap.xml', SitemapController::class)->name('public.sitemap');

// Public resource/template boundary. These routes are declared before the
// admin catch-all so canonical slugs can render Blade and remain indexable.
Route::get('/resources', [ResourceCatalogController::class, 'index'])->name('public.resources.index');
Route::get('/resources/{slug}/preview/{version?}', [TemplatePublicPreviewController::class, 'show'])
    ->where('version', '[^/]+')->name('public.resources.preview');
Route::get('/resources/{slug}/download/{version?}', [TemplateDownloadController::class, 'request'])
    ->where('version', '[^/]+')->middleware(['auth:customer', 'account.active:customer'])->name('public.resources.download');
Route::get('/resources/{slug}', [ResourceCatalogController::class, 'show'])->name('public.resources.show');
Route::get('/template-shares/{token}', [TemplateShareController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:60,1')->name('template-shares.show');
Route::get('/template-share-download/{token}', [TemplateDownloadController::class, 'share'])
    ->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:60,1')->name('template-shares.download');
Route::get('/template-download/{token}', [TemplateDownloadController::class, 'stream'])
    ->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:60,1')->name('template-download.stream');

// Admin SPA boundary. The Vue router mounts under this prefix, so no arbitrary
// root URL can boot the admin application.
Route::view('/admin', 'admin')->name('admin.login');
Route::get('/admin/{any?}', function () {
    return view('admin');
})->where('any', '.*');
