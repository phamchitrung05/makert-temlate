<?php

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Đăng ký API health, admin và account/token.
 * CÁC HÀM/METHOD TRONG FILE: các route group closure cho auth/quyền;
 * Slug dùng endpoint chung đa model có throttle; không còn endpoint riêng của Post.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): HTTP path và middleware -> controller JSON.
 * =====================================================================
 */

use App\Http\Controllers\Admin\AccessPermissionController;
use App\Http\Controllers\Admin\AdminUserRoleController;
use App\Http\Controllers\Admin\AiContentReviewController;
use App\Http\Controllers\Admin\AiArticleArchiveController;
use App\Http\Controllers\Admin\AiImageGenerationController;
use App\Http\Controllers\Admin\AiImportController;
use App\Http\Controllers\Admin\AiProviderController;
use App\Http\Controllers\Admin\AiSourcePreviewController;
use App\Http\Controllers\Admin\AiWritingProfileAnalysisController;
use App\Http\Controllers\Admin\AiWritingProfileController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\MediaAssetController;
use App\Http\Controllers\Admin\PostController;
use App\Http\Controllers\Admin\ResourceController;
use App\Http\Controllers\Admin\ResourceVersionController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SlugPreviewController;
use App\Http\Controllers\Admin\TagController;
use App\Http\Controllers\Admin\TechnologyController;
use App\Http\Controllers\Auth\AdminTokenController;
use App\Http\Controllers\Auth\SanctumTokenController;
use App\Http\Controllers\HealthController;
use App\Http\Responses\BaseResponse;
use App\Services\Settings\ProjectSettingsService;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('api.health');

Route::post('/admin/login', [AdminTokenController::class, 'login'])
    ->middleware('throttle:admin-login');

Route::middleware(['auth:sanctum', 'abilities:admin', 'account.active:sanctum'])
    ->prefix('admin')
    ->group(function (): void {
        Route::get('/me', [AdminTokenController::class, 'me']);

        // Guard admin tách khỏi customer; service kiểm lại quyền mutation dưới lock.
        Route::middleware('permission:users.view|users.manage|roles.view|roles.create|roles.update|roles.delete,admin')->group(function (): void {
            Route::get('/permissions/catalog', [AccessPermissionController::class, 'catalog']);
            Route::get('/permissions', [AccessPermissionController::class, 'index']);
            Route::get('/roles', [RoleController::class, 'index']);
            Route::get('/roles/{role}', [RoleController::class, 'show'])->whereNumber('role');
            Route::get('/access/users', [AdminUserRoleController::class, 'index']);
            Route::get('/access/users/{user}', [AdminUserRoleController::class, 'show'])->whereNumber('user');
        });
        Route::post('/roles', [RoleController::class, 'store'])
            ->middleware('permission:users.manage|roles.create,admin');
        Route::patch('/roles/{role}', [RoleController::class, 'update'])
            ->whereNumber('role')
            ->middleware('permission:users.manage|roles.update,admin');
        Route::delete('/roles/{role}', [RoleController::class, 'destroy'])
            ->whereNumber('role')
            ->middleware('permission:users.manage|roles.delete,admin');
        Route::middleware('permission:users.manage,admin')->group(function (): void {
            Route::patch('/access/users/{user}/roles', [AdminUserRoleController::class, 'update'])->whereNumber('user');
        });

        // Locale là preference an toàn; cấu hình vận hành dùng quyền Settings riêng.
        Route::get('/settings/preferences', [SettingsController::class, 'preferences']);
        Route::middleware('permission:settings.view|settings.manage,admin')->group(function (): void {
            Route::get('/settings', [SettingsController::class, 'index']);
            Route::get('/settings/cron', [SettingsController::class, 'cron']);
            Route::get('/settings/webhooks', [SettingsController::class, 'webhooks']);
            Route::get('/settings/system-info', [SettingsController::class, 'system']);
        });
        Route::patch('/settings/{group}', [SettingsController::class, 'update'])
            ->whereIn('group', array_keys(ProjectSettingsService::CLASSES))
            ->middleware('permission:settings.manage,admin');
        Route::post('/settings/mail/test', [SettingsController::class, 'testMail'])
            ->middleware(['permission:settings.manage,admin', 'throttle:3,1']);

        // Input: alias/title/ID; quyền theo model kiểm tra tại controller.
        Route::post('/slugs/preview', SlugPreviewController::class)
            ->middleware('throttle:60,1');

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

        // Quyền tài nguyên được đọc từ config và kiểm tra tại từng AI endpoint.
        Route::group([], function (): void {
            Route::get('/ai-agent/targets', [AiImportController::class, 'targets']);
            Route::post('/ai-agent/source-preview', [AiSourcePreviewController::class, '__invoke'])->middleware('throttle:6,1');
            Route::get('/ai-agent/capabilities/{target}', [AiImportController::class, 'capabilities'])
                ->where('target', '[a-z][a-z0-9_-]*');
            Route::post('/ai-agent/sessions', [AiImportController::class, 'store']);
            Route::get('/ai-agent/sessions', [AiImportController::class, 'index']);
            Route::get('/ai-agent/sessions/{aiImport}', [AiImportController::class, 'show'])->whereUuid('aiImport');
            Route::post('/ai-agent/sessions/{aiImport}/regenerate', [AiImportController::class, 'regenerate'])->whereUuid('aiImport');
            Route::post('/ai-agent/sessions/{aiImport}/retry', [AiImportController::class, 'retry'])->whereUuid('aiImport');
            Route::get('/ai-agent/sessions/{aiImport}/candidates', [AiImportController::class, 'candidates'])->whereUuid('aiImport');
            Route::post('/ai-agent/sessions/{aiImport}/cancel', [AiImportController::class, 'cancel'])->whereUuid('aiImport');
            Route::delete('/ai-agent/sessions/{aiImport}', [AiImportController::class, 'destroy'])->whereUuid('aiImport');
            Route::patch('/ai-agent/candidates/{aiImport}', [AiImportController::class, 'updateCandidate'])->whereUuid('aiImport');
            Route::post('/ai-agent/candidates/{aiImport}/apply', [AiImportController::class, 'apply'])->whereUuid('aiImport');
            Route::middleware('permission:posts.manage,admin')->group(function (): void {
                Route::get('/ai-agent/approved-archives', [AiArticleArchiveController::class, 'index']);
                Route::get('/ai-agent/approved-archives/{aiArticleArchive}', [AiArticleArchiveController::class, 'show'])
                    ->whereNumber('aiArticleArchive');
                Route::get('/ai-agent/candidates/{aiImport}/review', [AiContentReviewController::class, 'show'])->whereUuid('aiImport');
                Route::get('/ai-agent/candidates/{aiImport}/review/history', [AiContentReviewController::class, 'history'])->whereUuid('aiImport');
                Route::post('/ai-agent/candidates/{aiImport}/approve', [AiContentReviewController::class, 'approve'])->whereUuid('aiImport');
                Route::post('/ai-agent/candidates/{aiImport}/reject', [AiContentReviewController::class, 'reject'])->whereUuid('aiImport');
            });
        });

        Route::middleware('permission:posts.manage,admin')->group(function (): void {
            Route::post('/posts/ai/import', [AiImportController::class, 'store']);
            Route::get('/posts/ai/import/{aiImport}', [AiImportController::class, 'show'])->whereUuid('aiImport');
            Route::post('/posts/ai/import/{aiImport}/regenerate', [AiImportController::class, 'regenerate'])->whereUuid('aiImport');
            Route::post('/posts/ai/import/{aiImport}/retry', [AiImportController::class, 'retry'])->whereUuid('aiImport');
            Route::get('/posts/ai/import/{aiImport}/candidates', [AiImportController::class, 'candidates'])->whereUuid('aiImport');
            Route::post('/posts/ai/import/{aiImport}/apply', [AiImportController::class, 'apply'])->whereUuid('aiImport');
            Route::post('/posts/ai/import/{aiImport}/cancel', [AiImportController::class, 'cancel'])->whereUuid('aiImport');
            Route::delete('/posts/ai/import/{aiImport}', [AiImportController::class, 'destroy'])->whereUuid('aiImport');
        });

        // Post CRUD và lifecycle dùng permission chi tiết; posts.manage là alias toàn quyền.
        Route::get('/posts', [PostController::class, 'index'])
            ->middleware('permission:posts.view|posts.manage,admin');
        Route::get('/posts/authors', [PostController::class, 'authors'])
            ->middleware('permission:posts.view|posts.manage,admin');
        Route::post('/posts', [PostController::class, 'store'])
            ->middleware('permission:posts.create|posts.manage,admin');
        Route::get('/posts/{post}', [PostController::class, 'show'])
            ->whereNumber('post')
            ->middleware('permission:posts.view|posts.manage,admin');
        Route::put('/posts/{post}', [PostController::class, 'update'])
            ->whereNumber('post')
            ->middleware('permission:posts.update|posts.manage,admin');
        Route::delete('/posts/{post}', [PostController::class, 'destroy'])
            ->whereNumber('post')
            ->middleware('permission:posts.delete|posts.manage,admin');
        Route::post('/posts/{post}/submit-review', [PostController::class, 'submitReview'])
            ->whereNumber('post')
            ->middleware('permission:posts.review|posts.manage,admin');
        Route::post('/posts/{post}/publish', [PostController::class, 'publish'])
            ->whereNumber('post')
            ->middleware('permission:posts.publish|posts.manage,admin');
        Route::post('/posts/{post}/reject', [PostController::class, 'reject'])
            ->whereNumber('post')
            ->middleware('permission:posts.review|posts.manage,admin');
        Route::post('/posts/{post}/archive', [PostController::class, 'archive'])
            ->whereNumber('post')
            ->middleware('permission:posts.archive|posts.manage,admin');

        /**
         * =====================================================================
         * CHỨC NĂNG: Options văn phong dùng quyền viết bài; CRUD/analysis dùng quyền Settings.
         * =====================================================================
         * INPUT: admin HTTP đã authenticated.
         * OUTPUT: profile hoặc analysis preview; không tự lưu mẫu/Publish Post.
         * =====================================================================
         */
        Route::get('/ai/writing-profiles/options', [AiWritingProfileController::class, 'options']);
        Route::middleware('permission:ai_settings.manage,admin')->prefix('ai/writing-profiles')->group(function (): void {
            Route::get('/', [AiWritingProfileController::class, 'index']);
            Route::post('/', [AiWritingProfileController::class, 'store']);
            Route::post('/analyses', [AiWritingProfileAnalysisController::class, 'store'])->middleware('throttle:6,1');
            Route::get('/analyses/{analysis}', [AiWritingProfileAnalysisController::class, 'show'])->whereUuid('analysis');
            Route::post('/analyses/{analysis}/cancel', [AiWritingProfileAnalysisController::class, 'cancel'])->whereUuid('analysis');
            Route::get('/{profile}', [AiWritingProfileController::class, 'show'])->whereNumber('profile');
            Route::put('/{profile}', [AiWritingProfileController::class, 'update'])->whereNumber('profile');
            Route::delete('/{profile}', [AiWritingProfileController::class, 'destroy'])->whereNumber('profile');
        });

        /**
         * =====================================================================
         * GHI CHÚ: AI connection/model catalog là system setting; API key và default
         * không được mở bằng permission quản lý Post.
         * =====================================================================
         */
        Route::middleware('permission:ai_settings.manage,admin')->prefix('settings/ai')->group(function (): void {
            Route::get('/', [AiProviderController::class, 'index']);
            Route::get('/settings', [AiProviderController::class, 'settings']);
            Route::put('/settings', [AiProviderController::class, 'updateSettings']);
            Route::post('/providers', [AiProviderController::class, 'store']);
            Route::put('/providers/{provider}', [AiProviderController::class, 'update'])->whereNumber('provider');
            Route::post('/providers/{provider}/disable', [AiProviderController::class, 'disable'])->whereNumber('provider');
            Route::post('/providers/{provider}/test', [AiProviderController::class, 'test'])
                ->whereNumber('provider')->middleware('throttle:6,1');
            Route::post('/providers/{provider}/sync', [AiProviderController::class, 'sync'])
                ->whereNumber('provider')->middleware('throttle:6,1');
            Route::post('/providers/{provider}/models', [AiProviderController::class, 'storeModel'])->whereNumber('provider');
            Route::put('/providers/{provider}/models/{model}', [AiProviderController::class, 'updateModel'])
                ->whereNumber(['provider', 'model']);
        });

        Route::middleware('permission:posts.manage,admin')->prefix('ai-image')->group(function (): void {
            Route::get('/generations/{aiImport}', [AiImageGenerationController::class, 'show'])
                ->whereUuid('aiImport')->middleware('permission:media.view,admin');
            Route::post('/generations', [AiImageGenerationController::class, 'store'])
                ->middleware(['permission:media.upload,admin', 'throttle:12,1']);
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
