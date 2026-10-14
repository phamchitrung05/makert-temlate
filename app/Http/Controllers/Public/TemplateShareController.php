<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Services\Resources\TemplateAccessService;
use App\Services\Resources\TemplateDownloadService;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Resolve share token thành capability preview/download
 * CÁC HÀM/METHOD TRONG FILE: show().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): raw token -> grant URL, không lưu token.
 * =====================================================================
 */
final class TemplateShareController extends Controller
{
    /** Input: raw share token. Output: redirect capability đã ghim version. */
    public function show(string $token, TemplateAccessService $access, TemplateDownloadService $downloads)
    {
        $share = $access->resolveShare($token);
        $version = \App\Models\ResourceVersion::query()->findOrFail($share->resource_version_id);
        if ($share->capability === 'preview') {
            [, $grantToken] = $access->issue($version, 'preview', 'share', ['share_link_id' => $share->getKey()]);

            return redirect($access->previewUrl($grantToken));
        }
        $payload = $downloads->startShare($share, request());

        return redirect($payload['url']);
    }
}
