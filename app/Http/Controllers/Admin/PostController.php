<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Posts\CreatePostAction;
use App\Actions\Posts\UpdatePostAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PostCreateRequest;
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

/** Admin CRUD Post dùng Media Library riêng cho thumbnail và content images. */
class PostController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $paginator = Post::query()
            ->with(['slugs', 'mediaAssetUsages.mediaAsset.media'])
            ->when($request->filled('search'), fn ($query) => $query->where('title', 'like', '%'.$request->string('search').'%'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->resolvePerPage($request));

        return BaseResponse::paginated(PostResource::collection($paginator), 'Danh sách bài viết.');
    }

    public function store(PostCreateRequest $request, CreatePostAction $action): JsonResponse
    {
        $post = $action->handle($request->validated(), $this->adminUser($request)->getKey());

        return BaseResponse::created(PostResource::make($post), 'Tạo bài viết thành công.');
    }

    public function show(Post $post): JsonResponse
    {
        $post->loadMissing(['slugs', 'mediaAssetUsages.mediaAsset.media']);

        return BaseResponse::success(PostResource::make($post), 'Chi tiết bài viết.');
    }

    public function update(PostUpdateRequest $request, Post $post, UpdatePostAction $action): JsonResponse
    {
        $updated = $action->handle($post, $request->validated(), $this->adminUser($request)->getKey());

        return BaseResponse::success(PostResource::make($updated), 'Cập nhật bài viết thành công.');
    }

    public function destroy(Request $request, Post $post, MediaAssetUsageService $mediaAssetUsageService): Response
    {
        $actor = $this->adminUser($request);

        DB::transaction(function () use ($actor, $mediaAssetUsageService, $post): void {
            $mediaAssetUsageService->detachAll($actor, $post);
            $post->delete();
        });

        return BaseResponse::noContent();
    }

    private function adminUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        return $user;
    }
}
