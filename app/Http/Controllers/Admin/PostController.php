<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Posts\CreatePostAction;
use App\Actions\Posts\UpdatePostAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PostCreateRequest;
use App\Http\Requests\Admin\PostIndexRequest;
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
 * - store(): tạo Post qua CreatePostAction.
 * - show(): trả Post cùng relation cần cho form.
 * - update(): cập nhật Post qua UpdatePostAction.
 * - destroy(): gỡ usage và xóa Post trong transaction.
 * - adminUser(): lấy actor admin từ request.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : request admin và route model binding.
 * - OUTPUT: JsonResponse hoặc HTTP 204; lỗi do middleware/request/action xử lý.
 * =====================================================================
 */
class PostController extends Controller
{
    /**
     * Trả danh sách Post theo filter đã validate.
     *
     * Input: search, category_id, tag_id, status, page và per_page.
     * Output: JsonResponse phân trang; chỉ đọc database.
     */
    public function index(PostIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $paginator = Post::query()
            ->with(['slugs', 'seoMetadata.ogImage', 'categories', 'tags', 'mediaAssetUsages.mediaAsset.media'])
            ->when(filled($filters['search'] ?? null), fn ($query) => $query->where('title', 'like', '%'.addcslashes($filters['search'], '%_').'%'))
            ->when(isset($filters['category_id']), fn ($query) => $query->whereHas('categories', fn ($categories) => $categories->whereKey($filters['category_id'])))
            ->when(isset($filters['tag_id']), fn ($query) => $query->whereHas('tags', fn ($tags) => $tags->whereKey($filters['tag_id'])))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->resolvePerPage($request), ['*'], 'page', (int) ($filters['page'] ?? 1));

        return BaseResponse::paginated(PostResource::collection($paginator), 'Danh sách bài viết.');
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
        $post->loadMissing(['slugs', 'seoMetadata.ogImage', 'categories', 'tags', 'mediaAssetUsages.mediaAsset.media']);

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
}
