<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Media\UpdateMediaAssetMetadataAction;
use App\Actions\Media\UploadMediaAssetAction;
use App\Enums\MediaAssetField;
use App\Enums\MediaAssetKind;
use App\Enums\MediaConversionStatus;
use App\Enums\MediaScanStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MediaAssetIndexRequest;
use App\Http\Requests\Admin\MediaAssetMetadataUpdateRequest;
use App\Http\Requests\Admin\MediaAssetReorderRequest;
use App\Http\Requests\Admin\MediaAssetUploadRequest;
use App\Http\Requests\Admin\MediaAssetUsageRequest;
use App\Http\Resources\MediaAssetResource;
use App\Http\Resources\MediaAssetUsageResource;
use App\Http\Responses\BaseResponse;
use App\Jobs\Media\ProcessMediaConversionsJob;
use App\Jobs\Media\ScanMediaAssetJob;
use App\Models\MediaAsset;
use App\Models\MediaAssetUsage;
use App\Models\User;
use App\Services\Media\MediaAssetLinkableResolver;
use App\Services\MediaAssetUsageService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Cung cấp API quản trị Media Library trung tâm
 * =====================================================================
 *
 * Controller là HTTP boundary cho list/detail/upload/update/delete, usage,
 * retry và download. Policy/middleware xử lý authorization; Action/Service
 * giữ transaction và invariant nghiệp vụ. Response luôn dùng BaseResponse và
 * JsonResource, private file không trả storage path hoặc URL lâu hạn.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - index(): lọc và phân trang asset phía server
 * - store(): upload asset qua pipeline an toàn
 * - show(): trả chi tiết asset và usage
 * - update(): cập nhật metadata và visibility
 * - destroy(): soft-delete asset chưa bị usage khóa
 * - attach(): gắn asset vào model/field nghiệp vụ
 * - detach(): tháo một usage của asset
 * - reorder(): sắp xếp usage theo field multiple
 * - retry(): chạy lại scan hoặc conversion thất bại
 * - download(): trả public URL, temporary URL hoặc stream private
 * - applyFilters(): gắn các filter đã validate vào query
 * - mediaQuery(): giới hạn eager load vào collection library
 * - adminUser(): lấy admin hiện tại từ request
 * - authorizeForRequest(): chạy policy bằng đúng actor của request hiện tại
 * - mediaItem(): lấy file library của asset
 * - dispatchRetry(): đặt status pending và dispatch job tương ứng
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Request admin đã qua Sanctum, ability và permission middleware
 * - OUTPUT: JsonResponse envelope BaseResponse hoặc StreamedResponse download
 * - SIDE EFFECT: database mutation, queue dispatch, file stream/temporary URL
 * =====================================================================
 */
class MediaAssetController extends Controller
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Trả danh sách MediaAsset có filter và pagination
     * =====================================================================
     *
     * INPUT: query kind, field, visibility, owner, search, status, sort/page.
     * OUTPUT: JsonResponse dataTable với items và meta.pagination.
     * SIDE EFFECT: chỉ đọc database.
     */
    public function index(MediaAssetIndexRequest $request): JsonResponse
    {
        $this->authorizeForRequest($request, 'viewAny', MediaAsset::class);

        $filters = $request->validated();
        $query = $this->mediaQuery()->with('createdBy');
        $actor = $this->adminUser($request);
        if (! $actor->can('media.upload')) {
            $query->where('visibility', 'public');
        }
        $this->applyFilters($query, $filters);

        $sort = $filters['sort'] ?? 'created_at';
        $direction = $filters['direction'] ?? 'desc';
        $paginator = $query
            ->orderBy($sort, $direction)
            ->paginate(
                $this->resolvePerPage($request),
                ['*'],
                'page',
                (int) ($filters['page'] ?? 1),
            );

        return BaseResponse::dataTable(
            MediaAssetResource::collection($paginator),
            'Danh sách media asset.',
        );
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Upload asset qua pipeline validation và security
     * =====================================================================
     *
     * INPUT: multipart file, kind/title/visibility/alt_text đã validate.
     * OUTPUT: JsonResponse HTTP 201 chứa MediaAssetResource.
     * SIDE EFFECT: ghi file, database và dispatch scan/conversion job.
     */
    public function store(
        MediaAssetUploadRequest $request,
        UploadMediaAssetAction $action,
    ): JsonResponse {
        $this->authorizeForRequest($request, 'create', MediaAsset::class);
        $payload = $request->validated();
        $asset = $action->handle(
            $request->file('file'),
            MediaAssetKind::from($payload['kind']),
            $payload['title'],
            $this->adminUser($request)->getKey(),
            isset($payload['visibility'])
                ? \App\Enums\MediaAssetVisibility::from($payload['visibility'])
                : null,
            $payload['alt_text'] ?? null,
        );

        return BaseResponse::created(
            MediaAssetResource::make($asset->load('createdBy')),
            'Upload media thành công.',
        );
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả chi tiết MediaAsset và usage đang liên kết
     * =====================================================================
     *
     * INPUT: $mediaAsset đã được implicit route binding nạp sẵn.
     * OUTPUT: JsonResponse HTTP 200 chứa MediaAssetResource đầy đủ.
     * SIDE EFFECT: eager load relation, không ghi database.
     */
    public function show(Request $request, MediaAsset $mediaAsset): JsonResponse
    {
        $this->authorizeForRequest($request, 'view', $mediaAsset);
        $mediaAsset->loadMissing(['createdBy', 'usages.linkable', 'media']);

        return BaseResponse::success(
            MediaAssetResource::make($mediaAsset),
            'Chi tiết media asset.',
        );
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Cập nhật metadata và đồng bộ visibility/disk
     * =====================================================================
     *
     * INPUT: MediaAsset và payload title/alt_text/visibility đã validate.
     * OUTPUT: JsonResponse HTTP 200 chứa asset sau cập nhật.
     * SIDE EFFECT: update metadata, có thể move file Spatie sang disk mới.
     */
    public function update(
        MediaAssetMetadataUpdateRequest $request,
        MediaAsset $mediaAsset,
        UpdateMediaAssetMetadataAction $action,
    ): JsonResponse {
        $this->authorizeForRequest($request, 'update', $mediaAsset);
        $updated = $action->handle($mediaAsset, $request->validated());

        return BaseResponse::success(
            MediaAssetResource::make($updated->load('createdBy')),
            'Cập nhật metadata media thành công.',
        );
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Soft-delete asset không còn usage nghiệp vụ
     * =====================================================================
     *
     * INPUT: MediaAsset chưa bị soft-delete.
     * OUTPUT: Response HTTP 204 khi xóa thành công.
     * EXCEPTION: ValidationException 422 nếu asset còn usage.
     */
    public function destroy(Request $request, MediaAsset $mediaAsset): Response
    {
        $this->authorizeForRequest($request, 'delete', $mediaAsset);

        if ($mediaAsset->usages()->exists()) {
            throw ValidationException::withMessages([
                'media_asset' => 'Không thể xóa asset đang được model nghiệp vụ sử dụng.',
            ]);
        }

        $mediaAsset->delete();

        return BaseResponse::noContent();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Attach asset vào model/field nghiệp vụ
     * =====================================================================
     *
     * INPUT: asset route, field/linkable payload và actor admin.
     * OUTPUT: JsonResponse HTTP 201 chứa usage vừa tạo.
     * SIDE EFFECT: usage service ghi relation và activity trong transaction.
     */
    public function attach(
        MediaAssetUsageRequest $request,
        MediaAsset $mediaAsset,
        MediaAssetUsageService $service,
        MediaAssetLinkableResolver $resolver,
    ): JsonResponse {
        $this->authorizeForRequest($request, 'attach', $mediaAsset);
        $payload = $request->validated();
        $field = MediaAssetField::from($payload['field']);
        $linkable = $resolver->resolve($payload['linkable_type'], (int) $payload['linkable_id']);
        $usage = $service->attach(
            $this->adminUser($request),
            $linkable,
            $mediaAsset,
            $field,
            isset($payload['sort_order']) ? (int) $payload['sort_order'] : null,
        );

        return BaseResponse::created(
            MediaAssetUsageResource::make($usage->load('mediaAsset')),
            'Attach media thành công.',
        );
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Detach usage khỏi MediaAsset
     * =====================================================================
     *
     * INPUT: asset route và usage route cần tháo.
     * OUTPUT: Response HTTP 204 khi detach thành công.
     * EXCEPTION: 404 nếu usage không thuộc asset route.
     */
    public function detach(
        MediaAsset $mediaAsset,
        MediaAssetUsage $usage,
        MediaAssetUsageService $service,
        Request $request,
    ): Response {
        $this->authorizeForRequest($request, 'detach', $mediaAsset);

        if ((int) $usage->media_asset_id !== (int) $mediaAsset->getKey()) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $service->detach($this->adminUser($request), $usage);

        return BaseResponse::noContent();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Reorder usage của field multiple
     * =====================================================================
     *
     * INPUT: field/linkable và usage_ids theo thứ tự mới.
     * OUTPUT: JsonResponse HTTP 200 chứa danh sách usage sau reorder.
     * SIDE EFFECT: service cập nhật sort_order trong transaction.
     */
    public function reorder(
        MediaAssetReorderRequest $request,
        MediaAssetUsageService $service,
        MediaAssetLinkableResolver $resolver,
    ): JsonResponse {
        $payload = $request->validated();
        $linkable = $resolver->resolve($payload['linkable_type'], (int) $payload['linkable_id']);
        $field = MediaAssetField::from($payload['field']);
        $service->reorder(
            $this->adminUser($request),
            $linkable,
            $field,
            array_map('intval', $payload['usage_ids']),
        );

        $usages = $linkable->mediaAssetUsagesForField($field)
            ->with('linkable')
            ->orderBy('sort_order')
            ->get();

        return BaseResponse::success(
            MediaAssetUsageResource::collection($usages),
            'Reorder media thành công.',
        );
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Retry scan archive hoặc conversion image thất bại
     * =====================================================================
     *
     * INPUT: MediaAsset có custom status error/failed.
     * OUTPUT: JsonResponse HTTP 202 với asset đang pending.
     * SIDE EFFECT: cập nhật status và dispatch queue job có retry/backoff.
     * EXCEPTION: ValidationException nếu asset chưa ở trạng thái retry được.
     */
    public function retry(Request $request, MediaAsset $mediaAsset): JsonResponse
    {
        $this->authorizeForRequest($request, 'retry', $mediaAsset);
        $media = $this->mediaItem($mediaAsset);

        if ($media === null) {
            throw ValidationException::withMessages([
                'media_asset' => 'Asset chưa có file vật lý để retry.',
            ]);
        }

        $this->dispatchRetry($mediaAsset, $media);

        return BaseResponse::success(
            MediaAssetResource::make($mediaAsset->fresh()->load('createdBy')),
            'Media đã được đưa vào hàng đợi retry.',
            Response::HTTP_ACCEPTED,
        );
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Cấp URL public hoặc stream/temporary URL private
     * =====================================================================
     *
     * INPUT: MediaAsset đã qua policy view.
     * OUTPUT: JsonResponse URL public/private hoặc StreamedResponse file.
     * SIDE EFFECT: đọc file storage; không ghi database.
     */
    public function download(Request $request, MediaAsset $mediaAsset): JsonResponse|StreamedResponse
    {
        $this->authorizeForRequest($request, 'view', $mediaAsset);
        $media = $this->mediaItem($mediaAsset);

        if ($media === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        if ($mediaAsset->kind === MediaAssetKind::Archive
            && $media->getCustomProperty('scan_status') !== MediaScanStatus::Clean->value) {
            throw ValidationException::withMessages([
                'scan_status' => 'Package chỉ được download sau khi security scan ở trạng thái clean.',
            ]);
        }

        if ($mediaAsset->visibility->value === 'public'
            && $mediaAsset->kind !== MediaAssetKind::Archive) {
            return BaseResponse::success([
                'url' => $media->getUrl(),
                'expires_at' => null,
            ], 'URL media công khai.');
        }

        $disk = Storage::disk($media->disk);
        $path = $media->getPathRelativeToRoot();
        $expiresAt = now()->addMinutes((int) config('media-library.temporary_url_default_lifetime', 5));

        if ($disk->providesTemporaryUrls()) {
            return BaseResponse::success([
                'temporary_url' => $disk->temporaryUrl($path, $expiresAt),
                'expires_at' => $expiresAt->toIso8601String(),
            ], 'URL media riêng tư tạm thời.');
        }

        return $disk->download($path, $media->file_name, [
            'Content-Type' => $media->mime_type ?: 'application/octet-stream',
        ]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Gắn filter server-side vào query MediaAsset
     * =====================================================================
     *
     * INPUT: Builder và filter đã qua MediaAssetIndexRequest.
     * OUTPUT: Không trả giá trị; Builder được thêm điều kiện lọc.
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (isset($filters['field'])) {
            $field = MediaAssetField::from($filters['field']);
            $query->where('kind', $field->kind()->value);
        } elseif (isset($filters['kind'])) {
            $query->where('kind', $filters['kind']);
        }

        if (isset($filters['visibility'])) {
            $query->where('visibility', $filters['visibility']);
        }

        if (isset($filters['owner'])) {
            $query->where('created_by', (int) $filters['owner']);
        }

        if (isset($filters['scan_status'])) {
            $query->whereHas('media', fn (Builder $mediaQuery) => $mediaQuery
                ->where('collection_name', 'library')
                ->where('custom_properties->scan_status', $filters['scan_status']));
        }

        if (isset($filters['conversion_status'])) {
            $query->whereHas('media', fn (Builder $mediaQuery) => $mediaQuery
                ->where('collection_name', 'library')
                ->where('custom_properties->conversion_status', $filters['conversion_status']));
        }

        if (! empty($filters['search'])) {
            $search = '%'.addcslashes($filters['search'], '%_').'%';
            $query->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery
                    ->where('title', 'like', $search)
                    ->orWhereHas('media', fn (Builder $mediaQuery) => $mediaQuery
                        ->where('collection_name', 'library')
                        ->where('file_name', 'like', $search));
            });
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo query eager load media collection library
     * =====================================================================
     *
     * OUTPUT: Builder có relation media giới hạn đúng collection library.
     */
    private function mediaQuery(): Builder
    {
        return MediaAsset::query()->with([
            'media' => fn ($mediaQuery) => $mediaQuery
                ->where('collection_name', 'library'),
        ]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy admin hiện tại từ request
     * =====================================================================
     *
     * INPUT: Request đã qua auth:sanctum.
     * OUTPUT: User admin đang xác thực.
     * EXCEPTION: 401 nếu guard không trả User.
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
     * CHỨC NĂNG: Chạy policy bằng đúng admin của request hiện tại
     * =====================================================================
     *
     * INPUT: Request đã xác thực, ability policy và model/class cần kiểm tra.
     * OUTPUT: Không trả giá trị khi được phép.
     * EXCEPTION: AuthorizationException HTTP 403 khi actor thiếu quyền.
     */
    private function authorizeForRequest(Request $request, string $ability, mixed $arguments): void
    {
        Gate::forUser($this->adminUser($request))->authorize($ability, $arguments);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy media item library đầu tiên của asset
     * =====================================================================
     *
     * INPUT: MediaAsset owner nghiệp vụ.
     * OUTPUT: Media Spatie hoặc null nếu asset chưa có file.
     */
    private function mediaItem(MediaAsset $mediaAsset): ?\Spatie\MediaLibrary\MediaCollections\Models\Media
    {
        return $mediaAsset->getFirstMedia('library');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đặt status pending và dispatch retry job tương ứng
     * =====================================================================
     *
     * INPUT: asset và media item cần retry.
     * OUTPUT: Không trả giá trị; queue nhận ScanMediaAssetJob hoặc conversion job.
     * EXCEPTION: ValidationException nếu status hiện tại không retry được.
     */
    private function dispatchRetry(MediaAsset $mediaAsset, \Spatie\MediaLibrary\MediaCollections\Models\Media $media): void
    {
        if ($mediaAsset->kind === MediaAssetKind::Archive) {
            if ($media->getCustomProperty('scan_status') !== MediaScanStatus::Error->value) {
                throw ValidationException::withMessages([
                    'scan_status' => 'Chỉ archive có scan_status error mới được retry.',
                ]);
            }

            $media->setCustomProperty('scan_status', MediaScanStatus::Pending->value)->save();
            ScanMediaAssetJob::dispatch($media->getKey());

            return;
        }

        if ($mediaAsset->kind === MediaAssetKind::Image) {
            if ($media->getCustomProperty('conversion_status') !== MediaConversionStatus::Failed->value) {
                throw ValidationException::withMessages([
                    'conversion_status' => 'Chỉ image có conversion_status failed mới được retry.',
                ]);
            }

            $media->setCustomProperty('conversion_status', MediaConversionStatus::Pending->value)->save();
            ProcessMediaConversionsJob::dispatch($media->getKey());

            return;
        }

        throw ValidationException::withMessages([
            'media_asset' => 'Kind này chưa có queue retry tương ứng.',
        ]);
    }
}
