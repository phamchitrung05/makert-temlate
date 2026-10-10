<?php

namespace App\Actions\Taxonomy;

use App\Enums\MediaAssetField;
use App\Models\Category;
use App\Models\User;
use App\Repositories\Contracts\CategoryRepositoryInterface;
use App\Services\MediaAssetUsageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Lưu Category và thumbnail trong cùng transaction.
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nhận repository và service media dùng chung.
 * - handle(): tạo/sửa Category, đồng bộ ảnh khi payload có media.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): payload đã validate, actor, Category tùy chọn
 * -> Category mới nhất; rollback cả Category/slug nếu media bị từ chối.
 * =====================================================================
 */
class SaveCategoryAction
{
    /** Input: dependency từ container. Output: action dùng repository/media service của project. */
    public function __construct(
        private readonly CategoryRepositoryInterface $categories,
        private readonly MediaAssetUsageService $media,
    ) {}

    /**
     * Input: attributes đã validate, admin actor và Category khi chỉnh sửa.
     * Output: Category có slug, số bài và thumbnail; lỗi media/permission truyền lên API.
     * Side effect: ghi Category, slug và media usage trong một transaction.
     */
    public function handle(array $attributes, User $actor, ?Category $category = null): Category
    {
        return DB::transaction(function () use ($attributes, $actor, $category): Category {
            $media = $attributes['media'] ?? [];
            unset($attributes['media']);

            $saved = $category === null
                ? $this->categories->create($attributes)
                : $this->categories->update($attributes, $category->getKey());

            if (array_key_exists('thumbnail_id', $media)) {
                $thumbnailId = $media['thumbnail_id'];
                $currentId = $saved->mediaAssetUsagesForField(MediaAssetField::CategoryThumbnail)->value('media_asset_id');

                // Payload giữ nguyên ảnh không cần quyền attach; thay đổi hoặc gỡ ảnh phải được cấp quyền.
                if ($thumbnailId !== $currentId) {
                    if (! $actor->can('media.attach')) {
                        throw new AuthorizationException('Tài khoản không có quyền gắn ảnh vào Category.');
                    }
                    $this->media->syncFields($actor, $saved, [
                        MediaAssetField::CategoryThumbnail->value => $thumbnailId === null ? [] : [$thumbnailId],
                    ]);
                }
            }

            return $saved->fresh(['slugs', 'mediaAssetUsages.mediaAsset.media'])->loadCount('posts');
        });
    }
}
