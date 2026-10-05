<?php

namespace App\Services\Ai\Runs;

use App\Models\AiImport;
use App\Models\MediaAsset;
use App\Services\Media\ContentMediaReferenceService;
use Illuminate\Support\Facades\DB;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Cleanup asset tạm cho text/image run qua một boundary chung.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: cleanup().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT: run hết hạn/bị xóa.
 * - OUTPUT: orphan asset được dọn nếu không còn tham chiếu.
 * SIDE EFFECT: clear media collection và soft-delete asset; không đụng Post đang dùng.
 * EXCEPTION/TRANSACTION: caller quản lý transaction/retention; không gọi provider.
 * =====================================================================
 */
final class AiRunAssetCleaner
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Dọn asset tạm không còn usage hoặc candidate đang giữ
     * =====================================================================
     * INPUT: AiImport hết hạn/bị xóa hoặc bị hủy.
     * OUTPUT: không trả giá trị; giữ asset vẫn được dùng bởi Post/candidate khác.
     * SIDE EFFECT: Query usage/candidate, clear media collection và soft-delete orphan asset.
     * EXCEPTION/TRANSACTION: Transaction/row lock cho từng asset; lỗi DB/storage truyền lên caller.
     * =====================================================================
     */
    public function cleanup(AiImport $import): void
    {
        $ids = array_unique(array_filter([
            data_get($import->result_json, 'draft.thumbnail.media_asset_id'),
            data_get($import->result_json, 'image.media_asset_id'),
        ]));
        foreach ($ids as $id) {
            // INPUT: asset tạm. OUTPUT: quyết định xóa dưới cùng row lock với lưu Post/candidate.
            DB::transaction(function () use ($id, $import): void {
                $asset = MediaAsset::query()->whereKey($id)->lockForUpdate()->first();
                if (! $asset || $asset->usages()->exists()) {
                    return;
                }
                if (! app(ContentMediaReferenceService::class)->isReferencedByRetainedAiRun((int) $id, $import->id)) {
                    $asset->clearMediaCollection('library');
                    $asset->delete();
                }
            });
        }
    }
}
