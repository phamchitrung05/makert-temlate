<?php

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Đăng ký API health, admin và account/token.
 * CÁC HÀM/METHOD TRONG FILE:
 * - route health/login: health check và cấp admin token.
 * - route admin AI task: list/detail/cancel tracker dùng chung.
 * - route admin content/import/provider/profile: điều phối API nghiệp vụ và worker.
 * - route account/media/slug: quản lý tài khoản, media và endpoint dùng chung đa model.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): HTTP path và middleware -> controller JSON.
 * =====================================================================
 */

use App\Http\Controllers\Admin\AccessPermissionController;
use App\Http\Controllers\Admin\AdminUserRoleController;
use App\Http\Controllers\Admin\AiArticleArchiveController;
use App\Http\Controllers\Admin\AiContentReviewController;
use App\Http\Controllers\Admin\AiImageGenerationController;
use App\Http\Controllers\Admin\AiImageProfileController;
use App\Http\Controllers\Admin\AiImportController;
use App\Http\Controllers\Admin\AiProviderController;
use App\Http\Controllers\Admin\AiSourcePreviewController;
use App\Http\Controllers\Admin\AiTaskRunController;
use App\Http\Controllers\Admin\AiWritingProfileAnalysisController;
use App\Http\Controllers\Admin\AiWritingProfileController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\Marketing\CampaignController;
use App\Http\Controllers\Admin\Marketing\ProductController;
use App\Http\Controllers\Admin\Marketing\SocialAccountController;
use App\Http\Controllers\Admin\Marketing\SocialPostController;
use App\Http\Controllers\Admin\MediaAssetController;
use App\Http\Controllers\Admin\PostController;
use App\Http\Controllers\Admin\ResourceController;
use App\Http\Controllers\Admin\ResourceTemplateController;
use App\Http\Controllers\Admin\ResourceVersionController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SlugPreviewController;
use App\Http\Controllers\Admin\TagController;
use App\Http\Controllers\Admin\TechnologyController;
use App\Http\Controllers\Admin\TemplateShareController;
use App\Http\Controllers\Admin\VisualStyleController;
use App\Http\Controllers\Admin\VisualStyleGenerationController;
use App\Http\Controllers\Admin\VisualStyleShotController;
use App\Http\Controllers\Auth\AdminTokenController;
use App\Http\Controllers\Auth\SanctumTokenController;
use App\Http\Controllers\HealthController;
use App\Http\Responses\BaseResponse;
use App\Services\Settings\ProjectSettingsService;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('api.health');

Route::post('/admin/login', [AdminTokenController::class, 'login'])
    ->middleware('throttle:admin-login');

Route::middleware(['auth:sanctum', 'abilities:admin', 'account.active:sanctum,admin'])
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
            Route::post('/access/users', [AdminUserRoleController::class, 'store']);
            Route::patch('/access/users/{user}', [AdminUserRoleController::class, 'updateAccount'])->whereNumber('user');
            Route::delete('/access/users/{user}', [AdminUserRoleController::class, 'destroy'])->whereNumber('user');
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
            ->middleware(['permission:media.upload,admin', 'throttle:30,1'])
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
            Route::post('/resource-versions/{resourceVersion}/promote', [ResourceVersionController::class, 'promote'])
                ->whereNumber('resourceVersion');
            Route::post('/resource-versions/{resourceVersion}/rollback', [ResourceVersionController::class, 'rollback'])
                ->whereNumber('resourceVersion');
            Route::post('/resource-versions/{resourceVersion}/archive', [ResourceVersionController::class, 'archive'])
                ->whereNumber('resourceVersion');
            Route::get('/resource-versions/{resourceVersion}/template', [ResourceTemplateController::class, 'show'])
                ->whereNumber('resourceVersion');
            Route::post('/resource-versions/{resourceVersion}/template/process', [ResourceTemplateController::class, 'process'])
                ->whereNumber('resourceVersion');
            Route::patch('/resource-versions/{resourceVersion}/template', [ResourceTemplateController::class, 'update'])
                ->whereNumber('resourceVersion');
            Route::post('/resource-versions/{resourceVersion}/template/preview', [ResourceTemplateController::class, 'preview'])
                ->whereNumber('resourceVersion')->middleware('throttle:30,1');
            Route::post('/resource-versions/{resourceVersion}/template/screens/{screen}/preview', [ResourceTemplateController::class, 'screenPreview'])
                ->whereNumber('resourceVersion')
                ->whereNumber('screen');
            Route::post('/resource-versions/{resourceVersion}/template/shares', [TemplateShareController::class, 'store'])
                ->whereNumber('resourceVersion')->middleware('throttle:30,1');
            Route::delete('/template-shares/{share}', [TemplateShareController::class, 'revoke'])
                ->whereUuid('share');
        });

        // Gate registry kiểm quyền module; từng endpoint tiếp tục kiểm target và owner.
        Route::middleware('can:use-ai')->group(function (): void {
            Route::get('/ai/tasks', [AiTaskRunController::class, 'index']);
            Route::get('/ai/tasks/{taskRun}', [AiTaskRunController::class, 'show'])->whereUuid('taskRun');
            Route::post('/ai/tasks/{taskRun}/cancel', [AiTaskRunController::class, 'cancel'])->whereUuid('taskRun');
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
            Route::get('/ai-agent/candidates/{aiImport}/review', [AiContentReviewController::class, 'show'])->whereUuid('aiImport');
            Route::get('/ai-agent/candidates/{aiImport}/review/history', [AiContentReviewController::class, 'history'])->whereUuid('aiImport');
            Route::post('/ai-agent/candidates/{aiImport}/approve', [AiContentReviewController::class, 'approve'])->whereUuid('aiImport');
            Route::post('/ai-agent/candidates/{aiImport}/reject', [AiContentReviewController::class, 'reject'])->whereUuid('aiImport');
            Route::middleware('permission:posts.manage,admin')->group(function (): void {
                Route::get('/ai-agent/approved-archives', [AiArticleArchiveController::class, 'index']);
                Route::get('/ai-agent/approved-archives/{aiArticleArchive}', [AiArticleArchiveController::class, 'show'])
                    ->whereNumber('aiArticleArchive');
                Route::get('/ai-agent/candidates/{aiImport}/quality', [AiContentReviewController::class, 'quality'])->whereUuid('aiImport');
                Route::post('/ai-agent/candidates/{aiImport}/quality/rescore', [AiContentReviewController::class, 'rescore'])->whereUuid('aiImport');
            });
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
        Route::get('/ai/image-profiles/options', [AiImageProfileController::class, 'options'])->middleware('can:use-ai');
        Route::middleware('permission:ai_settings.manage,admin')->prefix('ai/image-profiles')->group(function (): void {
            Route::get('/', [AiImageProfileController::class, 'index']);
            Route::post('/', [AiImageProfileController::class, 'store'])->middleware('throttle:6,1');
            Route::get('/{imageProfile}', [AiImageProfileController::class, 'show'])->whereNumber('imageProfile');
            Route::put('/{imageProfile}', [AiImageProfileController::class, 'update'])->whereNumber('imageProfile');
            Route::delete('/{imageProfile}', [AiImageProfileController::class, 'destroy'])->whereNumber('imageProfile');
        });
        /**
         * =====================================================================
         * CHỨC NĂNG: Visual Style Engine — CRUD style, DNA, lifecycle và shots.
         * =====================================================================
         * INPUT: admin HTTP có ai_settings.manage.
         * OUTPUT: style/shot JSON qua Resource; analyze queue qua job bất đồng bộ.
         * =====================================================================
         */
        Route::middleware('permission:ai_settings.manage,admin')->prefix('ai/visual-styles')->group(function (): void {
            Route::get('/', [VisualStyleController::class, 'index']);
            Route::post('/', [VisualStyleController::class, 'store']);
            Route::get('/dna-meta', [VisualStyleController::class, 'dnaMeta']);
            Route::post('/analyze', [VisualStyleController::class, 'analyze'])->middleware('throttle:6,1');
            Route::post('/generate', [VisualStyleGenerationController::class, 'store'])->middleware('throttle:6,1');
            Route::get('/generations/{importId}', [VisualStyleGenerationController::class, 'show'])->whereUuid('importId');
            Route::get('/{visualStyle}', [VisualStyleController::class, 'show'])->whereNumber('visualStyle');
            Route::get('/{visualStyle}/reference-image', [VisualStyleController::class, 'referenceImage'])->whereNumber('visualStyle');
            Route::put('/{visualStyle}', [VisualStyleController::class, 'update'])->whereNumber('visualStyle');
            Route::delete('/{visualStyle}', [VisualStyleController::class, 'destroy'])->whereNumber('visualStyle');
            Route::put('/{visualStyle}/dna', [VisualStyleController::class, 'updateDna'])->whereNumber('visualStyle');
            Route::post('/{visualStyle}/activate', [VisualStyleController::class, 'activate'])->whereNumber('visualStyle');
            Route::post('/{visualStyle}/archive', [VisualStyleController::class, 'archive'])->whereNumber('visualStyle');
            Route::post('/{visualStyle}/duplicate', [VisualStyleController::class, 'duplicate'])->whereNumber('visualStyle');
            Route::get('/{visualStyle}/shots', [VisualStyleShotController::class, 'index'])->whereNumber('visualStyle');
            Route::post('/{visualStyle}/shots', [VisualStyleShotController::class, 'store'])->whereNumber('visualStyle');
            Route::post('/{visualStyle}/shots/reorder', [VisualStyleShotController::class, 'reorder'])->whereNumber('visualStyle');
            Route::put('/{visualStyle}/shots/{shot}', [VisualStyleShotController::class, 'update'])->whereNumber(['visualStyle', 'shot']);
            Route::delete('/{visualStyle}/shots/{shot}', [VisualStyleShotController::class, 'destroy'])->whereNumber(['visualStyle', 'shot']);
        });
        Route::middleware('permission:ai_settings.manage,admin')->prefix('ai/writing-profiles')->group(function (): void {
            Route::get('/', [AiWritingProfileController::class, 'index']);
            Route::post('/', [AiWritingProfileController::class, 'store']);
            Route::get('/analyses', [AiWritingProfileAnalysisController::class, 'index']);
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
            Route::delete('/providers/{provider}', [AiProviderController::class, 'destroy'])->whereNumber('provider');
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

        /**
         * =====================================================================
         * CHỨC NĂNG: Marketing Social Post/API Page account.
         * =====================================================================
         * Caption là canonical dùng chung; generation/publisher chạy queue.
         * =====================================================================
         */
        Route::middleware('permission:marketing.view|social_posts.view,admin')->group(function (): void {
            Route::get('/marketing/products', [ProductController::class, 'index']);
            Route::get('/marketing/products/{product}', [ProductController::class, 'show'])->whereNumber('product');
            Route::get('/marketing/campaigns', [CampaignController::class, 'index']);
            Route::get('/marketing/campaigns/{campaign}', [CampaignController::class, 'show'])->whereNumber('campaign');
            Route::get('/social-posts', [SocialPostController::class, 'index']);
            Route::get('/social-posts/{socialPost}', [SocialPostController::class, 'show'])->whereNumber('socialPost');
            Route::get('/social-posts/{socialPost}/versions', [SocialPostController::class, 'versions'])->whereNumber('socialPost');
            Route::get('/social-posts/{socialPost}/publications', [SocialPostController::class, 'publications'])->whereNumber('socialPost');
            Route::get('/social-posts/{socialPost}/variants', [SocialPostController::class, 'variants'])->whereNumber('socialPost');
            Route::get('/social-accounts', [SocialAccountController::class, 'index']);
        });
        Route::post('/marketing/products', [ProductController::class, 'store'])->middleware('permission:marketing.manage|social_posts.create,admin');
        Route::put('/marketing/products/{product}', [ProductController::class, 'update'])->whereNumber('product')->middleware('permission:marketing.manage|social_posts.update,admin');
        Route::delete('/marketing/products/{product}', [ProductController::class, 'destroy'])->whereNumber('product')->middleware('permission:marketing.manage|social_posts.delete,admin');
        Route::post('/marketing/campaigns', [CampaignController::class, 'store'])->middleware('permission:marketing.manage|social_posts.create,admin');
        Route::put('/marketing/campaigns/{campaign}', [CampaignController::class, 'update'])->whereNumber('campaign')->middleware('permission:marketing.manage|social_posts.update,admin');
        Route::delete('/marketing/campaigns/{campaign}', [CampaignController::class, 'destroy'])->whereNumber('campaign')->middleware('permission:marketing.manage|social_posts.delete,admin');
        Route::post('/social-posts', [SocialPostController::class, 'store'])
            ->middleware('permission:marketing.manage|social_posts.create,admin');
        Route::put('/social-posts/{socialPost}', [SocialPostController::class, 'update'])
            ->whereNumber('socialPost')->middleware('permission:marketing.manage|social_posts.update,admin');
        Route::delete('/social-posts/{socialPost}', [SocialPostController::class, 'destroy'])
            ->whereNumber('socialPost')->middleware('permission:marketing.manage|social_posts.delete,admin');
        Route::post('/social-posts/{socialPost}/generate', [SocialPostController::class, 'generate'])
            ->whereNumber('socialPost')->middleware('permission:marketing.manage|social_posts.create|social_posts.update,admin');
        Route::post('/social-posts/{socialPost}/regenerate-caption', [SocialPostController::class, 'regenerateCaption'])
            ->whereNumber('socialPost')->middleware('permission:marketing.manage|social_posts.update,admin');
        Route::post('/social-posts/{socialPost}/regenerate-image', [SocialPostController::class, 'regenerateImage'])
            ->whereNumber('socialPost')->middleware('permission:marketing.manage|social_posts.update,admin');
        Route::put('/social-posts/{socialPost}/variants/facebook/content', [SocialPostController::class, 'updateFacebookContent'])
            ->whereNumber('socialPost')->middleware('permission:marketing.manage|social_posts.update,admin');
        Route::post('/social-posts/{socialPost}/variants/facebook/generate-content', [SocialPostController::class, 'generateFacebookContent'])
            ->whereNumber('socialPost')->middleware('permission:marketing.manage|social_posts.update,admin');
        Route::post('/social-posts/{socialPost}/variants/facebook/generate-images', [SocialPostController::class, 'generateFacebookImages'])
            ->whereNumber('socialPost')->middleware('permission:marketing.manage|social_posts.update,admin');
        Route::post('/social-posts/{socialPost}/variants/facebook/images/{slot}/regenerate', [SocialPostController::class, 'regenerateImageSlot'])
            ->whereNumber('socialPost')->where('slot', '[0-9]')->middleware('permission:marketing.manage|social_posts.update,admin');
        Route::post('/social-posts/{socialPost}/variants/facebook/images/replace', [SocialPostController::class, 'replaceFacebookImages'])
            ->whereNumber('socialPost')->middleware('permission:marketing.manage|social_posts.update,admin');
        Route::post('/social-posts/{socialPost}/variants/facebook/images/reorder', [SocialPostController::class, 'reorderFacebookImages'])
            ->whereNumber('socialPost')->middleware('permission:marketing.manage|social_posts.update,admin');
        Route::post('/social-posts/{socialPost}/submit-review', [SocialPostController::class, 'submitReview'])
            ->whereNumber('socialPost')->middleware('permission:marketing.manage|social_posts.review,admin');
        Route::post('/social-posts/{socialPost}/approve', [SocialPostController::class, 'approve'])
            ->whereNumber('socialPost')->middleware('permission:marketing.manage|social_posts.review,admin');
        Route::post('/social-posts/{socialPost}/reject', [SocialPostController::class, 'reject'])
            ->whereNumber('socialPost')->middleware('permission:marketing.manage|social_posts.review,admin');
        Route::post('/social-posts/{socialPost}/request-revision', [SocialPostController::class, 'requestRevision'])
            ->whereNumber('socialPost')->middleware('permission:marketing.manage|social_posts.review,admin');
        Route::post('/social-posts/{socialPost}/publish', [SocialPostController::class, 'publish'])
            ->whereNumber('socialPost')->middleware('permission:marketing.manage|social_posts.publish,admin');
        Route::post('/social-publications/{publication}/retry', [SocialPostController::class, 'retryPublication'])
            ->whereNumber('publication')->middleware('permission:marketing.manage|social_posts.publish,admin');
        Route::post('/social-accounts/facebook/connect', [SocialAccountController::class, 'connect'])
            ->middleware('permission:marketing.manage|social_accounts.manage,admin');
        Route::post('/social-accounts/{socialAccount}/reconnect', [SocialAccountController::class, 'reconnect'])
            ->whereNumber('socialAccount')->middleware('permission:marketing.manage|social_accounts.manage,admin');
        Route::post('/social-accounts/{socialAccount}/disconnect', [SocialAccountController::class, 'disconnect'])
            ->whereNumber('socialAccount')->middleware('permission:marketing.manage|social_accounts.manage,admin');
    });

Route::get('/admin/social-accounts/facebook/callback', [SocialAccountController::class, 'callback'])
    ->middleware('throttle:20,1')
    ->name('admin.social-accounts.facebook.callback');

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
