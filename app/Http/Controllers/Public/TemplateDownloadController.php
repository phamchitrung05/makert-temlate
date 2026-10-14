<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Resource;
use App\Models\TemplateShareLink;
use App\Services\Catalog\ResourceCatalogService;
use App\Services\Resources\TemplateDownloadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Public/customer endpoint cấp và stream template ZIP
 * CÁC HÀM/METHOD TRONG FILE: request(), share(), stream().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): slug/share/token -> grant hoặc stream.
 * =====================================================================
 */
final class TemplateDownloadController extends Controller
{
    /** Input: public slug và customer session. Output: redirect tới URL grant ghim version. */
    public function request(Request $request, string $slug, ResourceCatalogService $catalog, TemplateDownloadService $downloads, ?string $version = null)
    {
        abort_unless(Auth::guard('customer')->check(), 401);
        $resolved = $catalog->resolveSlug($slug);
        abort_unless($resolved, 404);
        [$resource] = $resolved;
        $payload = $downloads->startCustomer($resource, $version, Auth::guard('customer')->user(), $request);

        return redirect($payload['url']);
    }

    /** Input: raw share token. Output: redirect tới download grant riêng. */
    public function share(Request $request, string $token, TemplateDownloadService $downloads, \App\Services\Resources\TemplateAccessService $access)
    {
        $share = $access->resolveShare($token);
        abort_unless($share->capability === 'download', 403);
        $payload = $downloads->startShare($share, $request);

        return redirect($payload['url']);
    }

    /** Input: short-lived download token. Output: private ZIP response. */
    public function stream(Request $request, string $token, TemplateDownloadService $downloads)
    {
        return $downloads->stream($token, $request);
    }
}
