<?php

namespace App\Http\Controllers\Public;

use App\Services\Catalog\ResourceCatalogService;
use App\Services\Resources\TemplateAccessService;
use App\Enums\ResourceVersionStatus;
use Illuminate\Http\RedirectResponse;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Public preview resolve latest/version cố định
 * CÁC HÀM/METHOD TRONG FILE: show(): cấp public grant sau policy.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): slug/version -> redirect grant preview.
 * =====================================================================
 */
final class TemplatePublicPreviewController
{
    /** Input: public resource slug/version tùy chọn. Output: redirect preview grant. */
    public function show(string $slug, ResourceCatalogService $catalog, TemplateAccessService $access, ?string $version = null): RedirectResponse
    {
        $resolved = $catalog->resolveSlug($slug);
        abort_unless($resolved, 404);
        [$resource] = $resolved;
        $candidate = $version === null
            ? $catalog->visibleVersion($resource)
            : $resource->versions()->where('version', $version)->where('status', ResourceVersionStatus::Ready)->with('template.screens')->first();
        abort_unless($candidate && $candidate->resource_id === $resource->id, 404);
        [, $token] = $access->issue($candidate, 'preview', 'public');

        return redirect($access->previewUrl($token));
    }
}
