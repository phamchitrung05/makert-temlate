<?php

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Preview routes stateless, không web/session/cookie middleware
 * CÁC HÀM/METHOD TRONG FILE: host/file routes với token hash grant.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): URL -> capability controller.
 * =====================================================================
 */
use App\Http\Controllers\Public\TemplatePreviewController;
use App\Http\Middleware\TemplatePreviewHeaders;
use Illuminate\Support\Facades\Route;

Route::middleware([TemplatePreviewHeaders::class, 'throttle:240,1'])->group(function (): void {
    Route::get('/template-preview/{token}', [TemplatePreviewController::class, 'host'])->name('template-preview.host');
    Route::get('/template-preview/{token}/site/{path}', [TemplatePreviewController::class, 'file'])
        ->where('path', '.*')->name('template-preview.file');
});
