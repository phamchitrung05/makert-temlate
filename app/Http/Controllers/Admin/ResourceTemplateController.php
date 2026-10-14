<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Resources\UpdateResourceTemplateSettingsAction;
use App\Enums\MediaAssetField;
use App\Enums\ResourceType;
use App\Enums\ResourceVersionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Resources\ResourceTemplateProcessRequest;
use App\Http\Requests\Admin\Resources\ResourceTemplateScreenPreviewRequest;
use App\Http\Requests\Admin\Resources\ResourceTemplateUpdateRequest;
use App\Http\Resources\Ai\AiTaskRunResource;
use App\Http\Resources\Resources\ResourceVersionResource;
use App\Http\Responses\BaseResponse;
use App\Models\MediaAsset;
use App\Models\ResourceVersion;
use App\Models\ResourceVersionTemplateScreen;
use App\Models\User;
use App\Services\MediaAssetUsageService;
use App\Services\Resources\TemplateVersionProcessingService;
use App\Services\Resources\ResourceVersionReadyGate;
use App\Services\Resources\TemplateAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Admin API orchestration cho Resource Template TPL-06
 * =====================================================================
 *
 * Controller chỉ kiểm tra owner/type/screen binding và gọi action/service;
 * parser, processing gate và media usage invariant nằm ở tầng nghiệp vụ.
 * API không trả raw storage path, HTML source hoặc private exception stack.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - show(): trả template processing state/screens/media bounded.
 * - process(): enqueue/rebuild và trả 202 tracker.
 * - update(): cập nhật entry/order/title/link mapping draft.
 * - screenPreview(): attach screenshot qua MediaAssetUsageService.
 * - preview(): cấp grant iframe short-lived.
 * - adminUser(): lấy actor admin hoặc 401.
 * - assertTemplateVersion(): kiểm resource type template.
 * - assertScreenBelongsToVersion(): chặn screen id cross-version.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : route ResourceVersion/screen, FormRequest và media id.
 * - OUTPUT: JSON envelope version/template/tracker bounded.
 * - SIDE EFFECT: enqueue queue, ghi settings hoặc media usage/audit.
 * - EXCEPTION/TRANSACTION: service/action rollback; owner/type/screen sai 422.
 * =====================================================================
 */
final class ResourceTemplateController extends Controller
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Đọc metadata processing/screens của version
     * =====================================================================
     * INPUT: ResourceVersion route model binding.
     * OUTPUT: ResourceVersionResource với template state bounded.
     * SIDE EFFECT: Chỉ eager-load database.
     * EXCEPTION/TRANSACTION: ValidationException nếu không phải template.
     * =====================================================================
     */
    public function show(ResourceVersion $resourceVersion): JsonResponse
    {
        $this->assertTemplateVersion($resourceVersion);
        $resourceVersion->loadMissing([
            'resource',
            'template.screens.mediaAssetUsages.mediaAsset.media',
            'mediaAssetUsages.mediaAsset.media',
        ]);

        $payload = ResourceVersionResource::make($resourceVersion)->resolve(request());
        $canReady = false;
        try {
            app(ResourceVersionReadyGate::class)->assert($resourceVersion);
            $canReady = $resourceVersion->status === ResourceVersionStatus::Draft;
        } catch (\Throwable) {
            // Capability là gợi ý UI; action kiểm gate dưới lock khi mutate.
        }
        $payload['capabilities'] = [
            'can_ready' => $canReady,
            'can_edit' => $resourceVersion->status === ResourceVersionStatus::Draft,
            'can_process' => $resourceVersion->status !== ResourceVersionStatus::Archived,
        ];

        return BaseResponse::success($payload, 'Chi tiết template version.');
    }

    /** Input: admin/version. Output: URL grant short-lived, không storage path hoặc token log. */
    public function preview(Request $request, ResourceVersion $resourceVersion, TemplateAccessService $access): JsonResponse
    {
        $this->assertTemplateVersion($resourceVersion);
        [$grant, $token] = $access->issue($resourceVersion, 'preview', 'admin', ['user_id' => $this->adminUser($request)->id]);

        return BaseResponse::success(['url' => $access->previewUrl($token), 'expires_at' => $grant->expires_at->toIso8601String()]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Enqueue/rebuild template processing
     * =====================================================================
     * INPUT: FormRequest process và ResourceVersion.
     * OUTPUT: HTTP 202 tracker queued/active bounded.
     * SIDE EFFECT: Ghi processing token và dispatch job after commit.
     * EXCEPTION/TRANSACTION: Package/type/permission lỗi được chuẩn hóa API.
     * =====================================================================
     */
    public function process(
        ResourceTemplateProcessRequest $request,
        ResourceVersion $resourceVersion,
        TemplateVersionProcessingService $service,
    ): JsonResponse {
        $this->assertTemplateVersion($resourceVersion);
        $task = $service->enqueue($resourceVersion);

        return BaseResponse::success(
            AiTaskRunResource::make($task)->resolve($request),
            'Đã xếp hàng xử lý template.',
            Response::HTTP_ACCEPTED,
        );
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Cập nhật settings draft của template
     * =====================================================================
     * INPUT: FormRequest entry/screens và ResourceVersion.
     * OUTPUT: ResourceVersionResource state mới.
     * SIDE EFFECT: Ghi screen order/title/link mapping/manifest snapshot.
     * EXCEPTION/TRANSACTION: Action kiểm version draft và screen ownership.
     * =====================================================================
     */
    public function update(
        ResourceTemplateUpdateRequest $request,
        ResourceVersion $resourceVersion,
        UpdateResourceTemplateSettingsAction $action,
    ): JsonResponse {
        $this->assertTemplateVersion($resourceVersion);
        $version = $action->handle(
            $resourceVersion,
            $request->validated(),
            $this->adminUser($request)->getKey(),
        );

        return BaseResponse::success(ResourceVersionResource::make($version), 'Đã cập nhật template settings.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Attach/replace screenshot preview cho đúng screen
     * =====================================================================
     * INPUT: media_asset_id image và screen route thuộc version.
     * OUTPUT: ResourceVersionResource với usage screenshot mới.
     * SIDE EFFECT: MediaAssetUsageService ghi usage/audit.
     * EXCEPTION/TRANSACTION: Cross-version screen hoặc ready version bị chặn.
     * =====================================================================
     */
    public function screenPreview(
        ResourceTemplateScreenPreviewRequest $request,
        ResourceVersion $resourceVersion,
        ResourceVersionTemplateScreen $screen,
        MediaAssetUsageService $usageService,
    ): JsonResponse {
        $this->assertTemplateVersion($resourceVersion);
        $this->assertScreenBelongsToVersion($resourceVersion, $screen);
        if (in_array($resourceVersion->status, [ResourceVersionStatus::Ready, ResourceVersionStatus::Archived], true)) {
            throw ValidationException::withMessages(['screen' => 'Screenshot bị khóa sau khi version ready hoặc archive.']);
        }

        $actor = $this->adminUser($request);
        $assetId = $request->validated('media_asset_id');
        $assets = $assetId === null ? [] : [MediaAsset::query()->findOrFail((int) $assetId)];
        $usageService->replace($actor, $screen, MediaAssetField::TemplateScreenPreview, $assets);
        $resourceVersion->loadMissing(['resource', 'template.screens.mediaAssetUsages.mediaAsset.media', 'mediaAssetUsages.mediaAsset.media']);

        return BaseResponse::success(ResourceVersionResource::make($resourceVersion), 'Đã cập nhật screenshot template.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy actor admin từ request
     * =====================================================================
     * INPUT: Request có user authenticated.
     * OUTPUT: User admin.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: 401 nếu guard không trả User.
     * =====================================================================
     */
    private function adminUser(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        return $user;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chặn route ResourceVersion ngoài resource template
     * =====================================================================
     * INPUT: ResourceVersion route model.
     * OUTPUT: Không trả giá trị nếu type template.
     * SIDE EFFECT: Load resource khi cần.
     * EXCEPTION/TRANSACTION: ValidationException nếu type khác.
     * =====================================================================
     */
    private function assertTemplateVersion(ResourceVersion $version): void
    {
        $version->loadMissing('resource');
        if ($version->resource?->type !== ResourceType::Template) {
            throw ValidationException::withMessages([
                'resource_version' => 'Chỉ resource type template mới có template API.',
            ]);
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm screen thuộc đúng ResourceVersion route
     * =====================================================================
     * INPUT: ResourceVersion và ResourceVersionTemplateScreen route.
     * OUTPUT: Không trả giá trị nếu ownership đúng.
     * SIDE EFFECT: Đọc relation template/resource version.
     * EXCEPTION/TRANSACTION: ValidationException nếu screen cross-version.
     * =====================================================================
     */
    private function assertScreenBelongsToVersion(ResourceVersion $version, ResourceVersionTemplateScreen $screen): void
    {
        $versionId = $screen->resourceVersionTemplate()->value('resource_version_id');
        if ((int) $versionId !== (int) $version->getKey()) {
            throw ValidationException::withMessages([
                'screen' => 'Screen không thuộc resource version này.',
            ]);
        }
    }
}
