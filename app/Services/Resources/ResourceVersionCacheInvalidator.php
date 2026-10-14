<?php

namespace App\Services\Resources;

use Illuminate\Support\Facades\Cache;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Xóa các cache key resource/version sau lifecycle mutation
 * =====================================================================
 *
 * Catalog/preview cache chưa dùng một repository riêng, nhưng mọi lifecycle
 * action gọi cùng allowlist key này để khi cache được bật không giữ default,
 * status hoặc artifact pointer cũ. Cache miss là thao tác hợp lệ và không làm
 * hỏng transaction nghiệp vụ.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - invalidateForResource(): xóa catalog/default/preview cache của resource.
 * - invalidateForVersion(): xóa detail/preview cache của version và resource.
 * - keysForResource(): dựng key allowlist bounded.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : resource id và version id nội bộ.
 * - OUTPUT: Không trả giá trị.
 * - SIDE EFFECT: Cache::forget các key lifecycle; không ghi database.
 * - EXCEPTION/TRANSACTION: Cache backend exception truyền ra để caller quyết
 *   định rollback; không gọi provider hoặc filesystem.
 * =====================================================================
 */
final class ResourceVersionCacheInvalidator
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Xóa cache catalog/default/preview của resource
     * =====================================================================
     * INPUT: resource id và version id tùy chọn.
     * OUTPUT: Không trả giá trị.
     * SIDE EFFECT: Xóa key resource và version nếu có.
     * EXCEPTION/TRANSACTION: Không mở transaction; Cache exception được truyền.
     * =====================================================================
     */
    public function invalidateForResource(int $resourceId, ?int $versionId = null): void
    {
        foreach ($this->keysForResource($resourceId) as $key) {
            Cache::forget($key);
        }

        if ($versionId !== null) {
            $this->invalidateForVersion($resourceId, $versionId, false);
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Xóa cache detail/preview của một version
     * =====================================================================
     * INPUT: resource id, version id và cờ xóa resource key.
     * OUTPUT: Không trả giá trị.
     * SIDE EFFECT: Xóa detail/preview key bounded.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function invalidateForVersion(int $resourceId, int $versionId, bool $includeResource = true): void
    {
        if ($includeResource) {
            $this->invalidateForResource($resourceId);
        }

        foreach ([
            'resource-version:'.$versionId,
            'resource-version:'.$versionId.':preview',
        ] as $key) {
            Cache::forget($key);
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Dựng allowlist key cache resource
     * =====================================================================
     * INPUT: resource id.
     * OUTPUT: list key catalog/default/preview bounded.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    private function keysForResource(int $resourceId): array
    {
        return [
            'resource:'.$resourceId.':catalog',
            'resource:'.$resourceId.':versions',
            'resource:'.$resourceId.':default-version',
            'resource:'.$resourceId.':preview',
        ];
    }
}
