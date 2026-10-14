<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Resources\TemplateShareRequest;
use App\Http\Responses\BaseResponse;
use App\Models\ResourceVersion;
use App\Models\TemplateShareLink;
use App\Models\User;
use App\Services\Resources\TemplateAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Admin API tạo/thu hồi share link capability
 * CÁC HÀM/METHOD TRONG FILE: store(), revoke(), adminUser().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): version/share -> response bounded.
 * =====================================================================
 */
final class TemplateShareController extends Controller
{
    /** Input: share request/version. Output: URL token một lần, capability và expiry. */
    public function store(TemplateShareRequest $request, ResourceVersion $resourceVersion, TemplateAccessService $access): JsonResponse
    {
        $actor = $this->adminUser($request);
        $payload = $access->share(
            $resourceVersion,
            $actor,
            (string) ($request->validated('capability') ?? 'preview'),
            (int) ($request->validated('days') ?? 7),
        );

        return BaseResponse::success($payload, 'Đã tạo share link.', Response::HTTP_CREATED);
    }

    /** Input: share UUID. Output: revoked share metadata without raw token. */
    public function revoke(Request $request, TemplateShareLink $share): JsonResponse
    {
        $actor = $this->adminUser($request);
        DB::transaction(function () use ($share, $actor): void {
            $share = TemplateShareLink::query()->whereKey($share->getKey())->lockForUpdate()->firstOrFail();
            if ($share->revoked_at === null) {
                $share->forceFill(['revoked_at' => now()])->save();
                activity('resources')->performedOn($share->resourceVersion()->first())->causedBy($actor)->event('template.share.revoked')->log('Template share revoked.');
            }
        });

        return BaseResponse::success(['id' => $share->getKey(), 'revoked_at' => now()->toIso8601String()], 'Đã thu hồi share link.');
    }

    /** Input: request actor. Output: authenticated admin User. */
    private function adminUser(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
