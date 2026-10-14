<?php

namespace App\Actions\Resources;

use App\Enums\ResourceVersionStatus;
use App\Enums\ResourceVersionTemplateProcessingStatus;
use App\Models\Resource;
use App\Models\ResourceVersion;
use App\Models\ResourceVersionTemplate;
use App\Models\User;
use App\Services\Resources\ResourceVersionCacheInvalidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Archive ResourceVersion và giữ package/retention history
 * =====================================================================
 *
 * Version archive không tháo package hoặc screen artifact. Action chỉ cho
 * archive version không phải default, không có download history đang giữ và
 * không còn processing active; các bản ready non-default vẫn được giữ để
 * rollback/đối chiếu theo policy.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nhận cache invalidator.
 * - handle(): khóa resource/version, kiểm retention và archive.
 * - fresh(): reload payload response.
 * - reject(): tạo ValidationException bounded.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : ResourceVersion id và actor id.
 * - OUTPUT: ResourceVersion archived cùng media/template metadata.
 * - SIDE EFFECT: ghi status/audit, giữ usage và invalidate cache.
 * - EXCEPTION/TRANSACTION: ValidationException rollback khi default/download/
 *   processing active; không xóa MediaAsset.
 * =====================================================================
 */
final class ArchiveResourceVersionAction
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Nhận cache invalidator lifecycle
     * =====================================================================
     * INPUT: ResourceVersionCacheInvalidator từ container.
     * OUTPUT: Action sẵn sàng chạy; chưa query/ghi DB.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function __construct(private readonly ResourceVersionCacheInvalidator $cacheInvalidator) {}

    /**
     * =====================================================================
     * CHỨC NĂNG: Archive version sau khi kiểm tra default/download/processing
     * =====================================================================
     * INPUT: Version route snapshot và actor id.
     * OUTPUT: Version status archived, package/usage vẫn tồn tại.
     * SIDE EFFECT: Khóa Resource/version/template, ghi audit và cache invalidation.
     * EXCEPTION/TRANSACTION: ValidationException nếu retention gate thất bại.
     * =====================================================================
     */
    public function handle(ResourceVersion $version, int $actorId): ResourceVersion
    {
        $versionId = (int) $version->getKey();
        $archived = DB::transaction(function () use ($versionId, $actorId): ResourceVersion {
            $snapshot = ResourceVersion::query()->whereKey($versionId)->firstOrFail();
            $resource = Resource::query()->whereKey($snapshot->resource_id)->lockForUpdate()->firstOrFail();
            $version = ResourceVersion::query()
                ->with('resource')
                ->whereKey($versionId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($version->status === ResourceVersionStatus::Archived) {
                $this->reject('version', 'Version đã archive trước đó.');
            }

            if ($version->is_default) {
                $this->reject('version', 'Không thể archive version mặc định; hãy rollback/promote version khác trước.');
            }

            if (DB::table('downloads')->where('resource_version_id', $versionId)->exists()) {
                $this->reject('version', 'Không thể archive version đang có lịch sử download cần retention.');
            }

            $template = ResourceVersionTemplate::query()
                ->where('resource_version_id', $versionId)
                ->lockForUpdate()
                ->first();
            if ($template && in_array($template->processing_status, [
                ResourceVersionTemplateProcessingStatus::Pending,
                ResourceVersionTemplateProcessingStatus::Processing,
            ], true)) {
                $this->reject('template', 'Không thể archive khi template đang được xử lý.');
            }

            $fromStatus = $version->status;
            $version->forceFill(['status' => ResourceVersionStatus::Archived])->save();

            activity('resources')
                ->causedBy($actorId ? User::find($actorId) : null)
                ->performedOn($version)
                ->event('version.archived')
                ->withProperties([
                    'resource_id' => $resource->getKey(),
                    'from_status' => $fromStatus->value,
                ])
                ->log('Resource version archived.');

            return $this->fresh($version);
        });

        $this->cacheInvalidator->invalidateForVersion((int) $archived->resource_id, (int) $archived->getKey());

        return $archived;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Reload payload version archived
     * =====================================================================
     * INPUT: ResourceVersion đã persist.
     * OUTPUT: version cùng resource/template/screens/media.
     * SIDE EFFECT: Chỉ đọc database.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    private function fresh(ResourceVersion $version): ResourceVersion
    {
        return $version->fresh([
            'resource',
            'template.screens',
            'mediaAssetUsages.mediaAsset.media',
        ]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Ném validation lỗi archive bounded
     * =====================================================================
     * INPUT: field và message public-safe.
     * OUTPUT: Không trả giá trị.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: ValidationException cho caller rollback.
     * =====================================================================
     */
    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
