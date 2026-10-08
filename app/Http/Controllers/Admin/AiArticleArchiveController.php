<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AiArticleArchiveIndexRequest;
use App\Http\Resources\AiArticleArchiveResource;
use App\Http\Resources\AiArticleArchiveSummaryResource;
use App\Http\Responses\BaseResponse;
use App\Models\AiArticleArchive;
use Illuminate\Http\JsonResponse;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: API đọc kho bài AI đã được duyệt dài hạn.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: index(), show(), approvedQuery().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : bộ lọc/phân trang hoặc ID archive từ màn hình AI Approved.
 * - OUTPUT: danh sách/chi tiết snapshot allowlist; không có mutation.
 * - SIDE EFFECT: chỉ đọc archive và Post liên kết, không gọi AI.
 * - EXCEPTION/TRANSACTION: permission do route; không mở transaction.
 * =====================================================================
 */
final class AiArticleArchiveController extends Controller
{
    /**
     * Input: filter đã validate. Output: danh sách archive approved phân trang.
     * Side effect: eager load Post tối thiểu; không sửa dữ liệu.
     */
    public function index(AiArticleArchiveIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $paginator = $this->approvedQuery()
            ->with('post:id,title,status')
            ->when(filled($filters['search'] ?? null), function ($query) use ($filters): void {
                $search = addcslashes($filters['search'], '%_');
                $query->where(function ($nested) use ($search): void {
                    $nested->where('draft_snapshot_json->title', 'like', '%'.$search.'%')
                        ->orWhere(function ($targetQuery) use ($search): void {
                            $targetQuery->where('target_type', 'post')
                                ->whereHas('post', fn ($post) => $post->where('title', 'like', '%'.$search.'%'));
                        });
                });
            })
            ->when(filled($filters['created_from'] ?? null), fn ($query) => $query->whereDate('created_at', '>=', $filters['created_from']))
            ->when(filled($filters['created_to'] ?? null), fn ($query) => $query->whereDate('created_at', '<=', $filters['created_to']))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 15), ['*'], 'page', (int) ($filters['page'] ?? 1));

        return BaseResponse::paginated(AiArticleArchiveSummaryResource::collection($paginator), 'Danh sách bài AI đã duyệt.');
    }

    /**
     * Input: archive route ID. Output: chi tiết bản AI gốc đã sanitize.
     * Side effect: eager load Post nếu còn tồn tại; không sửa snapshot.
     */
    public function show(AiArticleArchive $aiArticleArchive): JsonResponse
    {
        abort_unless($this->approvedQuery()->whereKey($aiArticleArchive->getKey())->exists(), 404);
        $aiArticleArchive->load('post:id,title,status');

        return BaseResponse::success(new AiArticleArchiveResource($aiArticleArchive), 'Chi tiết bài AI đã duyệt.');
    }

    /** Input: không có. Output: builder chọn archive target bất kỳ có nội dung AI và trạng thái approved. */
    private function approvedQuery()
    {
        return AiArticleArchive::query()
            ->where('has_generated_content', true)
            ->where('lifecycle_json->review_status', 'approved');
    }
}
