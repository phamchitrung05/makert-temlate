<?php

namespace App\Http\Controllers\Admin\Marketing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Marketing\SocialPostGenerationRequest;
use App\Http\Requests\Admin\Marketing\SocialPostIndexRequest;
use App\Http\Requests\Admin\Marketing\SocialPostReviewRequest;
use App\Http\Requests\Admin\Marketing\SocialPostStoreRequest;
use App\Http\Requests\Admin\Marketing\SocialPostUpdateRequest;
use App\Http\Requests\Admin\Marketing\SocialPostVariantContentRequest;
use App\Http\Resources\Marketing\SocialPostResource;
use App\Enums\MediaAssetField;
use App\Models\SocialPostVariant;
use App\Http\Responses\BaseResponse;
use App\Models\SocialPost;
use App\Models\SocialPublication;
use App\Services\Marketing\SocialPostGenerationService;
use App\Services\Marketing\SocialPostLifecycleService;
use App\Services\Marketing\SocialPublicationService;
use App\Services\MediaAssetUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: API CRUD, AI generation và review Social Post.
 * =====================================================================
 * Controller chỉ là HTTP boundary; lifecycle/generation/media/publisher nằm ở
 * service tương ứng để job và command dùng lại được.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - index()/show()/store()/update()/destroy(): CRUD Social Post.
 * - generate()/regenerateCaption()/regenerateImage(): xếp hàng AI task.
 * - submitReview()/approve()/reject()/requestRevision(): review lifecycle.
 * - versions()/publications(): đọc audit/publication history.
 * - publish()/retryPublication(): tạo hoặc retry publication jobs.
 * - adminUser(): lấy actor đã authenticated.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : admin request và SocialPost route binding.
 * - OUTPUT: BaseResponse JSON/resource; provider luôn chạy async.
 * - SIDE EFFECT: DB mutation/queue dispatch qua service; không gọi provider trong HTTP.
 * =====================================================================
 */
final class SocialPostController extends Controller
{
    /**
     * Trả danh sách Social Post theo filter và thống kê KPI bounded.
     *
     * Input: SocialPostIndexRequest filter/pagination.
     * Output: paginated SocialPostResource + counts.
     * Side effect: chỉ đọc DB, eager load relation.
     */
    public function index(SocialPostIndexRequest $request, SocialPostLifecycleService $lifecycle): JsonResponse
    {
        $filters = $request->validated();
        $query = SocialPost::query()
            ->with(['campaign', 'product', 'targets.socialAccount', 'targets.socialPostVariant', 'variants.mediaAssetUsages.mediaAsset.media', 'mediaAssetUsages.mediaAsset.media'])
            ->when(filled($filters['search'] ?? null), fn ($q) => $q->where('caption', 'like', '%'.addcslashes((string) $filters['search'], '%_').'%'))
            ->when(isset($filters['campaign_id']), fn ($q) => $q->where('campaign_id', $filters['campaign_id']))
            ->when(isset($filters['product_id']), fn ($q) => $q->where('product_id', $filters['product_id']))
            ->when(isset($filters['generation_status']), fn ($q) => $q->where('generation_status', $filters['generation_status']))
            ->when(isset($filters['review_status']), fn ($q) => $q->where('review_status', $filters['review_status']))
            ->when(isset($filters['publication_status']), fn ($q) => $q->where('publication_status', $filters['publication_status']))
            ->when(isset($filters['platform']), fn ($q) => $q->whereHas('targets', fn ($targets) => $targets->where('platform', $filters['platform'])))
            ->when(filled($filters['created_from'] ?? null), fn ($q) => $q->whereDate('created_at', '>=', $filters['created_from']))
            ->when(filled($filters['created_to'] ?? null), fn ($q) => $q->whereDate('created_at', '<=', $filters['created_to']))
            ->orderByDesc('created_at')->orderByDesc('id');
        $paginator = $query->paginate($this->resolvePerPage($request), ['*'], 'page', (int) ($filters['page'] ?? 1));
        $stats = [
            'draft' => SocialPost::query()->where('review_status', 'draft')->count(),
            'pending_review' => SocialPost::query()->where('review_status', 'pending_review')->count(),
            'approved' => SocialPost::query()->where('review_status', 'approved')->count(),
            'published' => SocialPost::query()->where('publication_status', 'published')->count(),
            'failed' => SocialPost::query()->where('review_status', 'rejected')->count(),
        ];

        return BaseResponse::paginated(SocialPostResource::collection($paginator), 'Danh sách Social Post.', ['stats' => $stats]);
    }

    /**
     * Trả chi tiết Social Post cùng versions/targets/media.
     *
     * Input: SocialPost route binding.
     * Output: SocialPostResource; chỉ đọc DB.
     */
    public function show(SocialPost $socialPost, SocialPostLifecycleService $lifecycle): JsonResponse
    {
        return BaseResponse::success(SocialPostResource::make($lifecycle->loadForResponse($socialPost)), 'Chi tiết Social Post.');
    }

    /**
     * Tạo Social Post draft và tùy chọn xếp generation.
     *
     * Input: store payload, actor.
     * Output: HTTP 201 SocialPost và job IDs nếu generate=true.
     * Side effect: insert DB và dispatch sau commit; không gọi provider.
     */
    public function store(
        SocialPostStoreRequest $request,
        SocialPostLifecycleService $lifecycle,
        SocialPostGenerationService $generation,
    ): JsonResponse {
        $actor = $this->adminUser($request);
        $data = $request->validated();
        $post = $lifecycle->create($data, (int) $actor->getKey());
        $queued = null;
        if ((bool) ($data['generate'] ?? true)) {
            $queued = $generation->queue($post, (int) $actor->getKey(), $data);
        }
        $payload = $lifecycle->loadForResponse($post->fresh());

        return BaseResponse::created([
            'post' => SocialPostResource::make($payload),
            'generation' => $queued,
        ], 'Đã tạo Social Post draft.', ['generation' => $queued]);
    }

    /**
     * Cập nhật canonical caption/context/target.
     *
     * Input: update payload và SocialPost.
     * Output: SocialPostResource fresh.
     * Side effect: approved content quay về draft khi thay đổi.
     */
    public function update(
        SocialPostUpdateRequest $request,
        SocialPost $socialPost,
        SocialPostLifecycleService $lifecycle,
    ): JsonResponse {
        $updated = $lifecycle->update($socialPost, $request->validated(), (int) $this->adminUser($request)->getKey());

        return BaseResponse::success(SocialPostResource::make($lifecycle->loadForResponse($updated)), 'Đã cập nhật Social Post.');
    }

    /**
     * Xóa Social Post draft và detach toàn bộ media usage.
     *
     * Input: actor/Post route binding.
     * Output: HTTP 204.
     * Side effect: transaction detach usage + soft delete post.
     */
    public function destroy(Request $request, SocialPost $socialPost, MediaAssetUsageService $usages): Response
    {
        $actor = $this->adminUser($request);
        DB::transaction(function () use ($actor, $socialPost, $usages): void {
            abort_if($socialPost->review_status?->value === 'approved', 409, 'Không xóa Social Post đã approved.');
            $usages->detachAll($actor, $socialPost);
            $socialPost->delete();
        });

        return BaseResponse::noContent();
    }

    /**
     * Xếp hai branch caption + image prompt cho generation mới.
     *
     * Input: SocialPost và model/profile selection.
     * Output: 202 generation IDs; provider chạy queue.
     */
    public function generate(
        SocialPostGenerationRequest $request,
        SocialPost $socialPost,
        SocialPostGenerationService $generation,
    ): JsonResponse {
        $result = $generation->queue($socialPost, (int) $this->adminUser($request)->getKey(), $request->validated());

        return BaseResponse::success($result, 'Đã xếp hàng tạo caption và ảnh.', 202);
    }

    /**
     * Regenerate riêng caption, giữ ảnh hiện tại.
     *
     * Input: request model/profile tùy chọn và SocialPost.
     * Output: 202 caption job ID.
     */
    public function regenerateCaption(
        SocialPostGenerationRequest $request,
        SocialPost $socialPost,
        SocialPostGenerationService $generation,
    ): JsonResponse {
        $result = $generation->queue($socialPost, (int) $this->adminUser($request)->getKey(), [...$request->validated(), 'component' => 'caption']);

        return BaseResponse::success($result, 'Đã xếp hàng tạo lại caption.', 202);
    }

    /**
     * Regenerate riêng ảnh, giữ caption hiện tại.
     *
     * Input: request image model tùy chọn và SocialPost.
     * Output: 202 image prompt job ID.
     */
    public function regenerateImage(
        SocialPostGenerationRequest $request,
        SocialPost $socialPost,
        SocialPostGenerationService $generation,
    ): JsonResponse {
        $result = $generation->queue($socialPost, (int) $this->adminUser($request)->getKey(), [...$request->validated(), 'component' => 'image']);

        return BaseResponse::success($result, 'Đã xếp hàng tạo lại ảnh.', 202);
    }

    public function generateFacebookContent(
        SocialPostGenerationRequest $request,
        SocialPost $socialPost,
        SocialPostGenerationService $generation,
    ): JsonResponse {
        $result = $generation->queue($socialPost, (int) $this->adminUser($request)->getKey(), [...$request->validated(), 'component' => 'caption']);

        return BaseResponse::success($result, 'Đã xếp hàng tạo content Facebook.', 202);
    }

    public function generateFacebookImages(
        SocialPostGenerationRequest $request,
        SocialPost $socialPost,
        SocialPostGenerationService $generation,
    ): JsonResponse {
        $result = $generation->queue($socialPost, (int) $this->adminUser($request)->getKey(), [...$request->validated(), 'component' => 'image']);

        return BaseResponse::success($result, 'Đã xếp hàng tạo gallery Facebook.', 202);
    }

    /**
     * Gửi draft vào review sau khi caption/ảnh đã ready.
     *
     * Input: SocialPost route binding và actor.
     * Output: SocialPost pending_review.
     */
    public function submitReview(Request $request, SocialPost $socialPost, SocialPostLifecycleService $lifecycle): JsonResponse
    {
        $post = $lifecycle->transition($socialPost, 'submit', $this->adminUser($request));

        return BaseResponse::success(SocialPostResource::make($lifecycle->loadForResponse($post)), 'Đã gửi Social Post để duyệt.');
    }

    /**
     * Approve Social Post đủ điều kiện review.
     *
     * Input: SocialPost pending_review.
     * Output: SocialPost approved, publication pending.
     */
    public function approve(Request $request, SocialPost $socialPost, SocialPostLifecycleService $lifecycle): JsonResponse
    {
        $post = $lifecycle->transition($socialPost, 'approve', $this->adminUser($request));

        return BaseResponse::success(SocialPostResource::make($lifecycle->loadForResponse($post)), 'Đã approve Social Post.');
    }

    /**
     * Reject Social Post đang pending review.
     *
     * Input: reason tùy chọn và SocialPost.
     * Output: SocialPost rejected.
     */
    public function reject(SocialPostReviewRequest $request, SocialPost $socialPost, SocialPostLifecycleService $lifecycle): JsonResponse
    {
        $post = $lifecycle->transition($socialPost, 'reject', $this->adminUser($request), $request->validated('reason'));

        return BaseResponse::success(SocialPostResource::make($lifecycle->loadForResponse($post)), 'Đã reject Social Post.');
    }

    /**
     * Yêu cầu sửa và đưa bài về revision_requested.
     *
     * Input: reason tùy chọn và SocialPost.
     * Output: SocialPost revision_requested.
     */
    public function requestRevision(SocialPostReviewRequest $request, SocialPost $socialPost, SocialPostLifecycleService $lifecycle): JsonResponse
    {
        $post = $lifecycle->transition($socialPost, 'request_revision', $this->adminUser($request), $request->validated('reason'));

        return BaseResponse::success(SocialPostResource::make($lifecycle->loadForResponse($post)), 'Đã yêu cầu chỉnh sửa Social Post.');
    }

    /**
     * Trả lịch sử version snapshot.
     *
     * Input: SocialPost.
     * Output: list version bounded.
     */
    public function versions(SocialPost $socialPost): JsonResponse
    {
        return BaseResponse::success($socialPost->versions()->get()->map(fn ($version): array => [
            'id' => (int) $version->id,
            'version_no' => (int) $version->version_no,
            'caption' => $version->caption,
            'content' => $version->content_json ?? [],
            'variant_id' => $version->social_post_variant_id ? (int) $version->social_post_variant_id : null,
            'asset_ids' => $version->asset_ids_json ?? [],
            'reason' => $version->reason,
            'version_hash' => $version->version_hash,
            'created_by' => $version->created_by,
            'created_at' => $version->created_at?->toIso8601String(),
        ])->values(), 'Lịch sử Social Post.');
    }

    /**
     * Trả publications theo từng target.
     *
     * Input: SocialPost.
     * Output: list publication bounded.
     */
    public function publications(SocialPost $socialPost): JsonResponse
    {
        return BaseResponse::success($socialPost->publications()->with('socialPostTarget.socialAccount')->get()->map(fn ($publication): array => [
            'id' => (int) $publication->id,
            'target_id' => (int) $publication->social_post_target_id,
            'status' => $publication->status?->value,
            'external_post_id' => $publication->external_post_id,
            'external_post_url' => $publication->external_post_url,
            'attempts' => (int) $publication->attempts,
            'error_code' => $publication->error_code,
            'error_message' => $publication->error_message,
            'completed_at' => $publication->completed_at?->toIso8601String(),
        ])->values(), 'Lịch sử publish Social Post.');
    }

    /** Trả các variant theo platform, hiện Facebook là variant đầu tiên. */
    public function variants(SocialPost $socialPost, SocialPostLifecycleService $lifecycle): JsonResponse
    {
        $post = $lifecycle->loadForResponse($socialPost);

        return BaseResponse::success(SocialPostResource::make($post)->resolve(request())['variants'] ?? [], 'Các variant Social Post.');
    }

    /** Cập nhật content blocks Facebook và reset review gate. */
    public function updateFacebookContent(
        SocialPostVariantContentRequest $request,
        SocialPost $socialPost,
        SocialPostLifecycleService $lifecycle,
    ): JsonResponse {
        $post = $lifecycle->update($socialPost, ['facebook_content' => $request->validated('content')], (int) $this->adminUser($request)->getKey());

        return BaseResponse::success(SocialPostResource::make($lifecycle->loadForResponse($post)), 'Đã cập nhật content Facebook.');
    }

    /** Thay gallery Facebook theo thứ tự asset IDs truyền vào. */
    public function replaceFacebookImages(
        Request $request,
        SocialPost $socialPost,
        SocialPostLifecycleService $lifecycle,
    ): JsonResponse {
        $data = $request->validate([
            'asset_ids' => ['array', 'max:4'],
            'asset_ids.*' => ['integer', 'distinct', 'exists:media_assets,id'],
        ]);
        $post = $lifecycle->update($socialPost, ['facebook_image_ids' => $data['asset_ids'] ?? []], (int) $this->adminUser($request)->getKey());

        return BaseResponse::success(SocialPostResource::make($lifecycle->loadForResponse($post)), 'Đã cập nhật gallery Facebook.');
    }

    /** Reorder usage gallery Facebook mà không thay asset. */
    public function reorderFacebookImages(
        Request $request,
        SocialPost $socialPost,
        MediaAssetUsageService $usages,
        SocialPostLifecycleService $lifecycle,
    ): JsonResponse {
        $data = $request->validate(['usage_ids' => ['required', 'array', 'min:1', 'max:4'], 'usage_ids.*' => ['integer', 'distinct']]);
        $post = $lifecycle->loadForResponse($socialPost);
        $variant = $post->variants->first(fn ($item): bool => ($item->platform?->value ?? (string) $item->platform) === 'facebook');
        abort_unless($variant instanceof SocialPostVariant, 404, 'Facebook variant không tồn tại.');
        $usages->reorder($this->adminUser($request), $variant, MediaAssetField::SocialPostFacebookImages, $data['usage_ids']);

        return BaseResponse::success(SocialPostResource::make($lifecycle->loadForResponse($post->fresh())), 'Đã sắp xếp gallery Facebook.');
    }

    /**
     * Tạo publication jobs sau khi approved gate đã qua.
     *
     * Input: approved SocialPost.
     * Output: 202 publication IDs.
     * Side effect: tạo publication per target và dispatch publisher queue.
     */
    public function publish(Request $request, SocialPost $socialPost, SocialPublicationService $publications): JsonResponse
    {
        $result = $publications->queue($socialPost, $this->adminUser($request));

        return BaseResponse::success($result, 'Đã xếp hàng publish theo từng Page.', 202);
    }

    /**
     * Retry một publication lỗi theo cùng idempotency key.
     *
     * Input: SocialPublication failed/retryable.
     * Output: publication queued; không tạo row duplicate.
     * Side effect: reset lỗi và dispatch publisher job.
     */
    public function retryPublication(SocialPublication $publication, SocialPublicationService $publications): JsonResponse
    {
        return BaseResponse::success($publications->retry($publication), 'Đã xếp hàng retry publication.', 202);
    }

    /**
     * Lấy actor admin hiện tại từ request.
     *
     * Input: Request authenticated Sanctum admin.
     * Output: User; abort 401 nếu guard không có actor.
     */
    private function adminUser(Request $request): \App\Models\User
    {
        $user = $request->user();
        abort_unless($user instanceof \App\Models\User, 401);

        return $user;
    }
}
