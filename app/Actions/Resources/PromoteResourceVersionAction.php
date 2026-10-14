<?php

namespace App\Actions\Resources;

use App\Enums\ResourceStatus;
use App\Enums\ResourceVersionStatus;
use App\Models\Resource;
use App\Models\ResourceVersion;
use App\Models\User;
use App\Services\Resources\ResourceVersionCacheInvalidator;
use App\Services\Resources\ResourceVersionReadyGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Promote/rollback ResourceVersion thành default atomically
 * =====================================================================
 *
 * Action khóa Resource trước mọi version cùng resource để serialize hai yêu
 * cầu promote/rollback cạnh tranh. Candidate luôn được đọc lại dưới lock và
 * chạy ready gate ngay trước khi đổi default; worker/request stale không được
 * dựa vào snapshot route model binding.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nhận ready gate và cache invalidator.
 * - handle(): promote version hoặc rollback version ready cũ.
 * - fresh(): eager-load payload resource version sau mutation.
 * - reject(): tạo ValidationException bounded.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : ResourceVersion id, actor id và cờ rollback.
 * - OUTPUT: ResourceVersion ready/default sau transaction.
 * - SIDE EFFECT: demote default cũ, ghi activity và xóa cache lifecycle.
 * - EXCEPTION/TRANSACTION: DB transaction/row lock; validation rollback toàn bộ.
 * =====================================================================
 */
final class PromoteResourceVersionAction
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Nhận các gate/cache dependency của lifecycle
     * =====================================================================
     * INPUT: Ready gate và cache invalidator từ container.
     * OUTPUT: Action sẵn sàng chạy; chưa query/ghi DB.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function __construct(
        private readonly ResourceVersionReadyGate $readyGate,
        private readonly ResourceVersionCacheInvalidator $cacheInvalidator,
    ) {}

    /**
     * =====================================================================
     * CHỨC NĂNG: Promote version hoặc rollback version ready non-default
     * =====================================================================
     * INPUT: Version route snapshot, actor id và rollback flag.
     * OUTPUT: Version ready có is_default=true và payload eager-load.
     * SIDE EFFECT: Khóa Resource, demote default cũ, audit và cache invalidation.
     * EXCEPTION/TRANSACTION: ValidationException nếu resource/version/gate sai;
     *   transaction rollback toàn bộ.
     * =====================================================================
     */
    public function handle(ResourceVersion $version, int $actorId, bool $rollback = false): ResourceVersion
    {
        $versionId = (int) $version->getKey();
        $promoted = DB::transaction(function () use ($versionId, $actorId, $rollback): ResourceVersion {
            $snapshot = ResourceVersion::query()->whereKey($versionId)->firstOrFail();
            $resource = Resource::query()->whereKey($snapshot->resource_id)->lockForUpdate()->firstOrFail();
            $candidate = ResourceVersion::query()
                ->with('resource')
                ->whereKey($versionId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($resource->status === ResourceStatus::Archived) {
                $this->reject('resource', 'Không thể promote version trong resource đã archive.');
            }

            if ($candidate->status === ResourceVersionStatus::Archived) {
                $this->reject('version', 'Không thể promote version đã archive.');
            }

            if ($candidate->status !== ResourceVersionStatus::Ready) {
                $this->reject('version', 'Version phải ready trước khi promote hoặc rollback.');
            }

            $this->readyGate->assert($candidate);

            $defaults = ResourceVersion::query()
                ->where('resource_id', $resource->getKey())
                ->where('is_default', true)
                ->lockForUpdate()
                ->get();
            $current = $defaults->first();

            if ($rollback && (! $current || (int) $current->getKey() === $versionId)) {
                $this->reject('version', 'Rollback cần một version mặc định khác đang được phát hành.');
            }

            if (! $rollback && (int) $candidate->getKey() === (int) $current?->getKey()) {
                return $this->fresh($candidate);
            }

            foreach ($defaults as $default) {
                if ((int) $default->getKey() !== $versionId) {
                    $default->forceFill(['is_default' => false])->save();
                }
            }

            $candidate->forceFill([
                'status' => ResourceVersionStatus::Ready,
                'is_default' => true,
                'released_at' => $candidate->released_at ?? now(),
            ])->save();

            $event = $rollback ? 'version.rolled_back' : 'version.promoted';
            activity('resources')
                ->causedBy($actorId ? User::find($actorId) : null)
                ->performedOn($candidate)
                ->event($event)
                ->withProperties([
                    'resource_id' => $resource->getKey(),
                    'previous_default_id' => $current?->getKey(),
                    'rollback' => $rollback,
                ])
                ->log($rollback ? 'Resource version rolled back.' : 'Resource version promoted.');

            return $this->fresh($candidate);
        });

        $this->cacheInvalidator->invalidateForVersion((int) $promoted->resource_id, (int) $promoted->getKey());

        return $promoted;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Reload payload lifecycle sau transaction
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
     * CHỨC NĂNG: Ném validation lỗi lifecycle bounded
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
