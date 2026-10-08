<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Posts\ArchivePostAction;
use App\Actions\Posts\CreatePostAction;
use App\Actions\Posts\PublishPostAction;
use App\Actions\Posts\RejectPostAction;
use App\Actions\Posts\SubmitPostForReviewAction;
use App\Actions\Posts\UpdatePostAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PostCreateRequest;
use App\Http\Requests\Admin\PostIndexRequest;
use App\Http\Requests\Admin\PostRejectRequest;
use App\Http\Requests\Admin\PostUpdateRequest;
use App\Http\Resources\PostResource;
use App\Http\Responses\BaseResponse;
use App\Models\Post;
use App\Models\User;
use App\Services\MediaAssetUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Cung cấp API CRUD Post cho khu vực quản trị.
 * =====================================================================
 *
 * Controller chỉ là HTTP boundary; transaction, slug, SEO, taxonomy và
 * media usage được ủy quyền cho Action/Service tương ứng.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - index(): lọc và phân trang Post.
 * - authors(): trả các tác giả đang có Post để dựng filter.
 * - store(): tạo Post qua CreatePostAction.
 * - show(): trả Post cùng relation cần cho form.
 * - update(): cập nhật Post qua UpdatePostAction.
 * - submitReview()/publish()/reject()/archive(): điều phối lifecycle Post.
 * - destroy(): gỡ usage và xóa Post trong transaction.
 * - adminUser(): lấy actor admin từ request.
 * - loadForResponse(): eager load quan hệ Post cho resource.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : request admin và route model binding.
 * - OUTPUT: JsonResponse hoặc HTTP 204; lỗi do middleware/request/action xử lý.
 * =====================================================================
 */
class PostController extends Controller
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Trả danh sách Post theo filter đã validate
     * =====================================================================
     * INPUT: search, category_id, tag_id, status, author_id, created_from,
     * created_to, page và per_page.
     * OUTPUT: JsonResponse phân trang, eager load author/taxonomy/media.
     * SIDE EFFECT: chỉ đọc database; không ghi Post.
     * EXCEPTION/TRANSACTION: FormRequest trả 422; không mở transaction.
     * =====================================================================
     */
    public function index(PostIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $paginator = Post::query()
            ->with(['slugs', 'createdBy:id,name,email', 'seoMetadata.ogImage', 'categories', 'tags', 'mediaAssetUsages.mediaAsset.media'])
            ->when(filled($filters['search'] ?? null), fn ($query) => $query->where('title', 'like', '%'.addcslashes($filters['search'], '%_').'%'))
            ->when(isset($filters['category_id']), fn ($query) => $query->whereHas('categories', fn ($categories) => $categories->whereKey($filters['category_id'])))
            ->when(isset($filters['tag_id']), fn ($query) => $query->whereHas('tags', fn ($tags) => $tags->whereKey($filters['tag_id'])))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['author_id']), fn ($query) => $query->where('created_by', $filters['author_id']))
            ->when(filled($filters['created_from'] ?? null), fn ($query) => $query->whereDate('created_at', '>=', $filters['created_from']))
            ->when(filled($filters['created_to'] ?? null), fn ($query) => $query->whereDate('created_at', '<=', $filters['created_to']))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->resolvePerPage($request), ['*'], 'page', (int) ($filters['page'] ?? 1));

        return BaseResponse::paginated(PostResource::collection($paginator), 'Danh sách bài viết.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả danh sách tác giả đang có Post cho filter Admin.
     * =====================================================================
     * INPUT: admin đã có posts.view/posts.manage.
     * OUTPUT: danh sách id/name/email, không trả credential hoặc user ngoài Post.
     * SIDE EFFECT: chỉ đọc User/Post; không mở transaction.
     * EXCEPTION/TRANSACTION: quyền do route middleware; không ghi database.
     * =====================================================================
     */
    public function authors(): JsonResponse
    {
        $authors = User::query()
            ->whereIn('id', Post::query()->whereNotNull('created_by')->select('created_by')->distinct())
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(static fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ])
            ->values();

        return BaseResponse::success($authors, 'Danh sách tác giả bài viết.');
    }

    /**
     * Tạo Post qua Action để đồng bộ SEO, taxonomy và media atomically.
     *
     * Input: payload PostCreateRequest đã validate.
     * Output: JsonResponse HTTP 201 chứa PostResource.
     */
    public function store(PostCreateRequest $request, CreatePostAction $action): JsonResponse
    {
        $post = $action->handle($request->validated(), $this->adminUser($request)->getKey());

        return BaseResponse::created(PostResource::make($post), 'Tạo bài viết thành công.');
    }

    /**
     * Trả Post và relation phục vụ màn hình edit.
     *
     * Input: Post được route model binding resolve.
     * Output: JsonResponse PostResource; không ghi database.
     */
    public function show(Post $post): JsonResponse
    {
        $this->loadForResponse($post);

        return BaseResponse::success(PostResource::make($post), 'Chi tiết bài viết.');
    }

    /**
     * Cập nhật Post qua Action để giữ invariant domain.
     *
     * Input: payload PostUpdateRequest và Post hiện tại.
     * Output: JsonResponse PostResource sau cập nhật.
     */
    public function update(PostUpdateRequest $request, Post $post, UpdatePostAction $action): JsonResponse
    {
        $updated = $action->handle($post, $request->validated(), $this->adminUser($request)->getKey());

        return BaseResponse::success(PostResource::make($updated), 'Cập nhật bài viết thành công.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Gửi Post nháp hoặc bị từ chối vào hàng chờ review
     * =====================================================================
     *
     * INPUT:
     * - $post: Post route model binding.
     * - $action: action kiểm tra state và ghi audit.
     *
     * OUTPUT:
     * - JsonResponse HTTP 200 với PostResource pending_review.
     *
     * SIDE EFFECT:
     * - Ghi status/updated_by và activity log; quyền do posts.review middleware.
     * =====================================================================
     */
    public function submitReview(Request $request, Post $post, SubmitPostForReviewAction $action): JsonResponse
    {
        $submitted = $action->handle($post, $this->adminUser($request)->getKey());
        $this->loadForResponse($submitted);

        return BaseResponse::success(PostResource::make($submitted), 'Đã gửi bài viết để review.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Publish Post qua lifecycle boundary riêng
     * =====================================================================
     *
     * INPUT:
     * - $post: Post route model binding.
     * - $action: action kiểm state, published_at và audit.
     *
     * OUTPUT:
     * - JsonResponse HTTP 200 với PostResource published.
     *
     * SIDE EFFECT:
     * - Ghi status/published_at/updated_by; quyền do posts.publish middleware.
     * =====================================================================
     */
    public function publish(Request $request, Post $post, PublishPostAction $action): JsonResponse
    {
        $published = $action->handle($post, $this->adminUser($request)->getKey());
        $this->loadForResponse($published);

        return BaseResponse::success(PostResource::make($published), 'Xuất bản bài viết thành công.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Từ chối Post đang chờ review
     * =====================================================================
     *
     * INPUT:
     * - $request: reason đã qua PostRejectRequest.
     * - $post: Post route model binding.
     * - $action: action kiểm state và ghi audit.
     *
     * OUTPUT:
     * - JsonResponse HTTP 200 với PostResource rejected.
     *
     * SIDE EFFECT:
     * - Ghi status/updated_by và activity log kèm reason; quyền do posts.review middleware.
     * =====================================================================
     */
    public function reject(PostRejectRequest $request, Post $post, RejectPostAction $action): JsonResponse
    {
        $rejected = $action->handle(
            $post,
            (string) $request->validated('reason'),
            $this->adminUser($request)->getKey(),
        );
        $this->loadForResponse($rejected);

        return BaseResponse::success(PostResource::make($rejected), 'Đã từ chối bài viết.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Archive Post và giữ lại lịch sử publish
     * =====================================================================
     *
     * INPUT:
     * - $post: Post route model binding.
     * - $action: action chuyển status và ghi audit.
     *
     * OUTPUT:
     * - JsonResponse HTTP 200 với PostResource archived.
     *
     * SIDE EFFECT:
     * - Ghi status/updated_by; quyền do posts.archive middleware.
     * =====================================================================
     */
    public function archive(Request $request, Post $post, ArchivePostAction $action): JsonResponse
    {
        $archived = $action->handle($post, $this->adminUser($request)->getKey());
        $this->loadForResponse($archived);

        return BaseResponse::success(PostResource::make($archived), 'Đã lưu trữ bài viết.');
    }

    /**
     * Gỡ toàn bộ media usage rồi soft-delete Post trong một transaction.
     *
     * Input: actor admin và Post cần xóa.
     * Output: HTTP 204; rollback nếu detach hoặc delete thất bại.
     */
    public function destroy(Request $request, Post $post, MediaAssetUsageService $mediaAssetUsageService): Response
    {
        $actor = $this->adminUser($request);

        DB::transaction(function () use ($actor, $mediaAssetUsageService, $post): void {
            $mediaAssetUsageService->detachAll($actor, $post);
            $post->delete();
        });

        return BaseResponse::noContent();
    }

    /**
     * Lấy actor User từ request đã qua auth:sanctum.
     *
     * Input: Request authenticated.
     * Output: User; abort 401 nếu guard không trả model phù hợp.
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
     * CHỨC NĂNG: Nạp quan hệ cần thiết trước khi serialize Post
     * =====================================================================
     *
     * INPUT:
     * - $post: Post cần trả về.
     *
     * OUTPUT:
     * - Post: cùng instance sau khi eager load relations.
     *
     * SIDE EFFECT:
     * - Chỉ đọc database; không mở transaction.
     */
    private function loadForResponse(Post $post): Post
    {
        return $post->loadMissing([
            'slugs',
            'createdBy:id,name,email',
            'seoMetadata.ogImage',
            'categories',
            'tags',
            'mediaAssetUsages.mediaAsset.media',
        ]);
    }
}
