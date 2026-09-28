<?php

use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\MediaAssetController;
use App\Http\Controllers\Admin\PostController;
use App\Http\Controllers\Admin\ResourceController;
use App\Http\Controllers\Admin\ResourceVersionController;
use App\Http\Controllers\Admin\TagController;
use App\Http\Controllers\Admin\TechnologyController;
use App\Http\Controllers\Auth\AdminTokenController;
use App\Http\Controllers\Auth\SanctumTokenController;
use App\Http\Controllers\HealthController;
use App\Http\Responses\BaseResponse;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('api.health');

Route::post('/admin/login', [AdminTokenController::class, 'login'])
    ->middleware('throttle:10,1');

Route::middleware(['auth:sanctum', 'abilities:admin', 'account.active:sanctum'])
    ->prefix('admin')
    ->group(function (): void {
        Route::get('/me', [AdminTokenController::class, 'me']);

        Route::middleware('permission:media.view,admin')->group(function (): void {
            Route::get('/media-assets', [MediaAssetController::class, 'index'])
                ->name('admin.media-assets.index');
            Route::get('/media-assets/{mediaAsset}', [MediaAssetController::class, 'show'])
                ->whereNumber('mediaAsset')
                ->name('admin.media-assets.show');
            Route::get('/media-assets/{mediaAsset}/download', [MediaAssetController::class, 'download'])
                ->whereNumber('mediaAsset')
                ->name('admin.media-assets.download');
        });

        Route::post('/media-assets', [MediaAssetController::class, 'store'])
            ->middleware('permission:media.upload,admin')
            ->name('admin.media-assets.store');
        Route::patch('/media-assets/{mediaAsset}', [MediaAssetController::class, 'update'])
            ->whereNumber('mediaAsset')
            ->middleware('permission:media.upload,admin')
            ->name('admin.media-assets.update');
        Route::delete('/media-assets/{mediaAsset}', [MediaAssetController::class, 'destroy'])
            ->whereNumber('mediaAsset')
            ->middleware('permission:media.delete,admin')
            ->name('admin.media-assets.destroy');
        Route::post('/media-assets/{mediaAsset}/retry', [MediaAssetController::class, 'retry'])
            ->whereNumber('mediaAsset')
            ->middleware('permission:media.retry,admin')
            ->name('admin.media-assets.retry');

        Route::middleware('permission:media.attach,admin')->group(function (): void {
            Route::post('/media-assets/usages/reorder', [MediaAssetController::class, 'reorder'])
                ->name('admin.media-assets.usages.reorder');
            Route::post('/media-assets/{mediaAsset}/usages', [MediaAssetController::class, 'attach'])
                ->whereNumber('mediaAsset')
                ->name('admin.media-assets.usages.attach');
            Route::delete('/media-assets/{mediaAsset}/usages/{usage}', [MediaAssetController::class, 'detach'])
                ->whereNumber('mediaAsset')
                ->whereNumber('usage')
                ->name('admin.media-assets.usages.detach');
        });

        Route::middleware('permission:resources.view,admin')->group(function (): void {
            Route::get('/resources', [ResourceController::class, 'index']);
            Route::get('/resources/{resource}', [ResourceController::class, 'show'])
                ->whereNumber('resource');

            Route::get('/categories', [CategoryController::class, 'index']);
            Route::get('/categories/{category}', [CategoryController::class, 'show'])
                ->whereNumber('category');

            Route::get('/tags', [TagController::class, 'index']);
            Route::get('/tags/{tag}', [TagController::class, 'show'])
                ->whereNumber('tag');

            Route::get('/technologies', [TechnologyController::class, 'index']);
            Route::get('/technologies/{technology}', [TechnologyController::class, 'show'])
                ->whereNumber('technology');
        });

        Route::middleware('permission:taxonomy.manage,admin')->group(function (): void {
            Route::post('/categories', [CategoryController::class, 'store']);
            Route::put('/categories/{category}', [CategoryController::class, 'update'])
                ->whereNumber('category');
            Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])
                ->whereNumber('category');

            Route::post('/tags', [TagController::class, 'store']);
            Route::put('/tags/{tag}', [TagController::class, 'update'])
                ->whereNumber('tag');
            Route::delete('/tags/{tag}', [TagController::class, 'destroy'])
                ->whereNumber('tag');

            Route::post('/technologies', [TechnologyController::class, 'store']);
            Route::put('/technologies/{technology}', [TechnologyController::class, 'update'])
                ->whereNumber('technology');
            Route::delete('/technologies/{technology}', [TechnologyController::class, 'destroy'])
                ->whereNumber('technology');
        });

        Route::post('/resources', [ResourceController::class, 'store'])
            ->middleware('permission:resources.create,admin');
        Route::put('/resources/{resource}', [ResourceController::class, 'update'])
            ->whereNumber('resource')
            ->middleware('permission:resources.update,admin');
        Route::delete('/resources/{resource}', [ResourceController::class, 'destroy'])
            ->whereNumber('resource')
            ->middleware('permission:resources.delete,admin');
        Route::post('/resources/{resource}/publish', [ResourceController::class, 'publish'])
            ->whereNumber('resource')
            ->middleware('permission:resources.publish,admin');
        Route::post('/resources/{resource}/archive', [ResourceController::class, 'archive'])
            ->whereNumber('resource')
            ->middleware('permission:resources.archive,admin');

        Route::middleware('permission:resource_versions.manage,admin')->group(function (): void {
            Route::get('/resource-versions', [ResourceVersionController::class, 'index']);
            Route::post('/resource-versions', [ResourceVersionController::class, 'store']);
            Route::get('/resource-versions/{resourceVersion}', [ResourceVersionController::class, 'show'])
                ->whereNumber('resourceVersion');
            Route::put('/resource-versions/{resourceVersion}', [ResourceVersionController::class, 'update'])
                ->whereNumber('resourceVersion');
            Route::delete('/resource-versions/{resourceVersion}', [ResourceVersionController::class, 'destroy'])
                ->whereNumber('resourceVersion');
            Route::post('/resource-versions/{resourceVersion}/ready', [ResourceVersionController::class, 'ready'])
                ->whereNumber('resourceVersion');
        });

        Route::middleware('permission:posts.manage,admin')->group(function (): void {
            Route::get('/posts', [PostController::class, 'index']);
            Route::post('/posts', [PostController::class, 'store']);
            Route::get('/posts/{post}', [PostController::class, 'show'])->whereNumber('post');
            Route::put('/posts/{post}', [PostController::class, 'update'])->whereNumber('post');
            Route::delete('/posts/{post}', [PostController::class, 'destroy'])->whereNumber('post');
        });
    });

Route::middleware(['web', 'auth:customer', 'account.active:customer'])
    ->prefix('account')
    ->group(function (): void {
        Route::get('/me', fn () => BaseResponse::success([
            'user' => request()->user('customer'),
        ]));
        Route::post('/token', [SanctumTokenController::class, 'issueCustomerToken']);
    });

Route::middleware(['auth:sanctum', 'account.active:sanctum'])
    ->prefix('auth/token')
    ->group(function (): void {
        Route::post('/revoke', [SanctumTokenController::class, 'revokeCurrentToken']);
        Route::post('/revoke-all', [SanctumTokenController::class, 'revokeAllTokens']);
    });
