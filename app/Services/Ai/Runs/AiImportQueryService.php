<?php

namespace App\Services\Ai\Runs;

use App\Enums\MediaAssetKind;
use App\Models\AiImport;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Ai\Registries\TargetRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Truy vấn AI import trong phạm vi owner và target được phép.
 * =====================================================================
 * Tách truy vấn/phân trang và tải thumbnail theo lô khỏi HTTP controller.
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nhận target registry.
 * - paginateFor(): lọc run còn hạn theo owner/quyền và phân trang.
 * - loadThumbnails(): nạp thumbnail/media theo lô cho list và detail.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): actor/filter hoặc collection -> dữ liệu đọc an toàn.
 * Không ghi database, dispatch job hoặc gọi provider.
 * =====================================================================
 */
final class AiImportQueryService
{
    /** Input: registry target. Output: query service, chưa truy vấn database. */
    public function __construct(private readonly TargetRegistry $targets) {}

    /** Input: admin và filters đã validate. Output: paginator, lỗi quyền nếu không có target; chỉ đọc DB. */
    public function paginateFor(User $actor, array $filters): LengthAwarePaginator
    {
        $allowedTargets = collect($this->targets->all())
            ->filter(fn (array $target): bool => $actor->can($target['permission'] ?? 'posts.manage'))
            ->keys()->all();
        if ($allowedTargets === []) {
            throw new AuthorizationException;
        }
        $paginator = AiImport::query()
            ->where('created_by', $actor->getKey())
            ->whereIn('input_target_type', $allowedTargets)
            ->where(fn ($query) => $query->whereNull('operation')->orWhereIn('operation', ['create', 'regenerate']))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where('status', '!=', 'expired')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 100), ['*'], 'page', (int) ($filters['page'] ?? 1));

        $this->loadThumbnails($paginator->getCollection());

        return $paginator;
    }

    /** Input: collection AiImport. Output: gắn relation thumbnail/media theo lô, không ghi dữ liệu. */
    public function loadThumbnails(Collection $imports): void
    {
        $unloaded = $imports->reject(fn (AiImport $import): bool => $import->relationLoaded('thumbnail'));
        $assetIds = $unloaded
            ->map(fn (AiImport $import): int => (int) data_get($import->result_json, 'draft.thumbnail.media_asset_id', 0))
            ->filter(fn (int $id): bool => $id > 0)->unique()->values();
        $assets = $assetIds->isEmpty()
            ? collect()
            : MediaAsset::query()->ofKind(MediaAssetKind::Image)->whereKey($assetIds)
                ->with('media')->get()->keyBy('id');

        // Spatie cần owner thật để dựng URL conversion theo cấu hình asset.
        foreach ($assets as $asset) {
            foreach ($asset->media as $media) {
                $media->setRelation('model', $asset);
            }
        }

        foreach ($unloaded as $import) {
            $assetId = (int) data_get($import->result_json, 'draft.thumbnail.media_asset_id', 0);
            $import->setRelation('thumbnail', $assets->get($assetId));
        }
    }
}
