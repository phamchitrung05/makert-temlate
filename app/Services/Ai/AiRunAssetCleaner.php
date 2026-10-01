<?php

namespace App\Services\Ai;

use App\Models\AiImport;
use App\Models\MediaAsset;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Cleanup asset tạm cho text/image run qua một boundary chung.
 * =====================================================================
 * CÁC HÀM/METHOD: cleanup().
 * INPUT: run hết hạn/bị xóa; OUTPUT: orphan asset được dọn nếu không còn tham chiếu.
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
     * EXCEPTION/TRANSACTION: Không mở transaction tổng; lỗi DB/storage truyền lên caller.
     * =====================================================================
     */
    public function cleanup(AiImport $import): void
    {
        $ids = array_unique(array_filter([
            data_get($import->result_json, 'draft.thumbnail.media_asset_id'),
            data_get($import->result_json, 'image.media_asset_id'),
        ]));
        foreach ($ids as $id) {
            $asset = MediaAsset::query()->find($id);
            if (! $asset || $asset->usages()->exists()) {
                continue;
            }
            $hasOtherRun = AiImport::query()->where('id', '!=', $import->id)
                ->where('status', 'ready')->where(function ($query): void {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })->where(function ($query) use ($id): void {
                    $query->where('result_json->draft->thumbnail->media_asset_id', $id)
                        ->orWhere('result_json->image->media_asset_id', $id);
                })->exists();
            if (! $hasOtherRun) {
                $asset->clearMediaCollection('library');
                $asset->delete();
            }
        }
    }
}
