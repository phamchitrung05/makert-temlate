<?php

namespace App\Actions\Media;

use App\Enums\MediaAssetKind;
use App\Enums\MediaAssetVisibility;
use App\Exceptions\MediaSecurityException;
use App\Models\MediaAsset;
use Illuminate\Database\DatabaseManager;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Cập nhật metadata và visibility của MediaAsset
 * =====================================================================
 *
 * Action giữ thay đổi metadata trong transaction và di chuyển media vật lý
 * khi visibility đổi disk. File không bị đổi nội dung; custom properties của
 * Spatie được giữ nguyên trong quá trình move.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nhận database manager để mở transaction
 * - handle(): cập nhật metadata và move media nếu cần
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : MediaAsset và metadata đã qua FormRequest
 * - OUTPUT: MediaAsset sau cập nhật, kèm media ở đúng disk
 * - EXCEPTION: MediaSecurityException nếu archive bị chuyển public
 * =====================================================================
 */
class UpdateMediaAssetMetadataAction
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Khởi tạo action với database manager
     * =====================================================================
     *
     * INPUT: $database là DatabaseManager của Laravel container.
     * OUTPUT: Action sẵn sàng chạy mutation metadata.
     */
    public function __construct(private readonly DatabaseManager $database) {}

    /**
     * =====================================================================
     * CHỨC NĂNG: Cập nhật metadata và đồng bộ disk theo visibility
     * =====================================================================
     *
     * INPUT:
     * - $asset: MediaAsset tồn tại và chưa soft-delete
     * - $attributes: title, alt_text và/hoặc visibility đã validate
     * OUTPUT: MediaAsset fresh sau mutation
     * SIDE EFFECT: UPDATE media_assets; move media Spatie nếu đổi visibility
     * EXCEPTION/TRANSACTION: rollback row nếu validation/domain lỗi
     * =====================================================================
     */
    public function handle(MediaAsset $asset, array $attributes): MediaAsset
    {
        return $this->database->transaction(function () use ($asset, $attributes): MediaAsset {
            $asset->fill($attributes);

            if ($asset->kind === MediaAssetKind::Archive
                && $asset->visibility === MediaAssetVisibility::Public) {
                throw new MediaSecurityException('Archive/package bắt buộc ở private disk.');
            }

            $asset->save();
            $media = $asset->getFirstMedia('library');

            if ($media !== null && $media->disk !== $asset->mediaDisk()) {
                $media->move($asset, 'library', $asset->mediaDisk(), $media->file_name);
            }

            return $asset->fresh();
        });
    }
}
