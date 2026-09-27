<?php

namespace App\Services;

use App\Enums\MediaAssetField;
use App\Models\MediaAsset;
use App\Models\MediaAssetUsage;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Điều phối attach/detach/reorder MediaAsset usage
 * =====================================================================
 *
 * Service là boundary duy nhất kiểm tra field-kind, morph alias, cardinality,
 * soft delete và permission trước khi ghi media_asset_usages. Mọi mutation
 * nhiều bản ghi chạy trong transaction và ghi activity sau mỗi thay đổi hợp lệ.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - attach(): attach một asset vào model/field
 * - detach(): tháo một usage
 * - reorder(): cập nhật thứ tự usage của field multiple
 * - replace(): thay toàn bộ asset của một field
 * - assertAttachable(): kiểm tra invariant và quyền attach
 * - morphAlias(): lấy alias của linkable model
 * - logUsageActivity(): ghi audit activity cho mutation
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : User actor, linkable model, MediaAsset, MediaAssetField và thứ tự
 * - OUTPUT: MediaAssetUsage/Collection hoặc exception validation/authorization
 * - SIDE EFFECT: ghi usage, xóa usage, reorder, activity log trong transaction
 * =====================================================================
 */
class MediaAssetUsageService
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Attach một asset vào field của model nghiệp vụ
     * =====================================================================
     *
     * INPUT:
     * - $actor: admin có permission media.attach
     * - $linkable: model có trait HasMediaAssets
     * - $asset: MediaAsset chưa bị soft delete
     * - $field: field phải cùng morph alias và kind với asset
     * - $sortOrder: thứ tự tùy chọn cho field multiple
     *
     * OUTPUT:
     * - MediaAssetUsage: usage vừa tạo
     *
     * EXCEPTION/TRANSACTION:
     * - ValidationException nếu sai field/kind/cardinality hoặc duplicate
     * - AuthorizationException nếu actor thiếu media.attach
     * - Không ghi dở dang vì toàn bộ mutation nằm trong transaction
     */
    public function attach(
        User $actor,
        Model $linkable,
        MediaAsset $asset,
        MediaAssetField $field,
        ?int $sortOrder = null,
    ): MediaAssetUsage {
        return DB::transaction(function () use ($actor, $linkable, $asset, $field, $sortOrder): MediaAssetUsage {
            $this->assertAttachable($actor, $linkable, $asset, $field);

            $existing = $linkable->mediaAssetUsagesForField($field)->exists();
            if ($existing && ! $field->allowsMultiple()) {
                throw ValidationException::withMessages([
                    'field' => "Field {$field->value} chỉ được gắn một asset; dùng replace() để thay thế.",
                ]);
            }

            if ($linkable->mediaAssetUsagesForField($field)
                ->where('media_asset_id', $asset->getKey())
                ->exists()) {
                throw ValidationException::withMessages([
                    'media_asset_id' => 'Asset đã được attach vào field này.',
                ]);
            }

            $usage = $linkable->mediaAssetUsages()->create([
                'media_asset_id' => $asset->getKey(),
                'field' => $field,
                'sort_order' => $sortOrder ?? $this->nextSortOrder($linkable, $field),
            ]);

            $this->logUsageActivity($actor, $asset, 'media_asset.attached', $usage);

            return $usage;
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Detach một usage khỏi model nghiệp vụ
     * =====================================================================
     *
     * INPUT:
     * - $actor: admin có permission media.attach
     * - $usage: usage cần xóa
     *
     * OUTPUT:
     * - Không trả giá trị; usage bị xóa khỏi bảng liên kết
     *
     * EXCEPTION/TRANSACTION:
     * - AuthorizationException nếu actor thiếu quyền attach/detach
     * - Không xóa MediaAsset hoặc file vật lý
     */
    public function detach(User $actor, MediaAssetUsage $usage): void
    {
        DB::transaction(function () use ($actor, $usage): void {
            $asset = $usage->mediaAsset()->withTrashed()->firstOrFail();
            Gate::forUser($actor)->authorize('attach', $asset);

            $usage->delete();
            $this->logUsageActivity($actor, $asset, 'media_asset.detached', $usage);
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Reorder các usage của field multiple
     * =====================================================================
     *
     * INPUT:
     * - $actor: admin có permission media.attach
     * - $linkable: model sở hữu các usage
     * - $field: field phải cho phép multiple
     * - $usageIds: danh sách usage id theo thứ tự mới
     *
     * OUTPUT:
     * - Không trả giá trị; sort_order được đánh lại từ 0
     *
     * EXCEPTION/TRANSACTION:
     * - ValidationException nếu field single hoặc danh sách không khớp usage
     */
    public function reorder(
        User $actor,
        Model $linkable,
        MediaAssetField $field,
        array $usageIds,
    ): void {
        DB::transaction(function () use ($actor, $linkable, $field, $usageIds): void {
            if (! $field->allowsMultiple()) {
                throw ValidationException::withMessages([
                    'field' => "Field {$field->value} không hỗ trợ reorder.",
                ]);
            }

            $usages = $linkable->mediaAssetUsagesForField($field)
                ->whereIn('id', $usageIds)
                ->with('mediaAsset')
                ->get()
                ->keyBy('id');

            if ($usages->count() !== count($usageIds)) {
                throw ValidationException::withMessages([
                    'usage_ids' => 'Danh sách usage không thuộc đúng model hoặc field.',
                ]);
            }

            foreach (array_values($usageIds) as $sortOrder => $usageId) {
                $usage = $usages->get($usageId);
                Gate::forUser($actor)->authorize('attach', $usage->mediaAsset);
                $usage->update(['sort_order' => $sortOrder]);
            }
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Thay toàn bộ asset của một field
     * =====================================================================
     *
     * INPUT:
     * - $actor: admin có permission media.attach
     * - $linkable: model nghiệp vụ
     * - $field: field single hoặc multiple
     * - $assets: danh sách asset mới
     *
     * OUTPUT:
     * - Collection<int, MediaAssetUsage>: usage mới theo thứ tự truyền vào
     *
     * EXCEPTION/TRANSACTION:
     * - ValidationException nếu single nhận nhiều asset hoặc asset sai kind
     * - Rollback toàn bộ nếu một asset không attach được
     */
    public function replace(
        User $actor,
        Model $linkable,
        MediaAssetField $field,
        iterable $assets,
    ): Collection {
        $assets = collect($assets)->values();

        if (! $field->allowsMultiple() && $assets->count() > 1) {
            throw ValidationException::withMessages([
                'assets' => "Field {$field->value} chỉ nhận một asset.",
            ]);
        }

        $assetIds = $assets->map(fn (mixed $asset): mixed => $asset instanceof MediaAsset ? $asset->getKey() : null);
        if ($assetIds->filter()->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages([
                'assets' => 'Không được lặp cùng một asset trong một field.',
            ]);
        }

        return DB::transaction(function () use ($actor, $linkable, $field, $assets): Collection {
            $assets->each(fn (mixed $asset) => $this->assertAttachable(
                $actor,
                $linkable,
                $asset,
                $field,
            ));

            $linkable->mediaAssetUsagesForField($field)->delete();

            return $assets->values()->map(function (MediaAsset $asset, int $sortOrder) use (
                $actor,
                $linkable,
                $field,
            ): MediaAssetUsage {
                $usage = $linkable->mediaAssetUsages()->create([
                    'media_asset_id' => $asset->getKey(),
                    'field' => $field,
                    'sort_order' => $sortOrder,
                ]);

                $this->logUsageActivity($actor, $asset, 'media_asset.replaced', $usage);

                return $usage;
            });
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra asset có thể attach vào field hay không
     * =====================================================================
     *
     * INPUT:
     * - $actor, $linkable, $asset, $field: context của mutation
     *
     * OUTPUT:
     * - Không trả giá trị nếu hợp lệ
     *
     * EXCEPTION/TRANSACTION:
     * - AuthorizationException hoặc ValidationException nếu invariant sai
     */
    private function assertAttachable(
        User $actor,
        Model $linkable,
        mixed $asset,
        MediaAssetField $field,
    ): void {
        if (! $asset instanceof MediaAsset) {
            throw ValidationException::withMessages([
                'media_asset_id' => 'Asset phải là MediaAsset hợp lệ.',
            ]);
        }

        Gate::forUser($actor)->authorize('attach', $asset);

        if ($asset->trashed()) {
            throw ValidationException::withMessages([
                'media_asset_id' => 'Không thể attach asset đã bị xóa.',
            ]);
        }

        if ($field->kind() !== $asset->kind) {
            throw ValidationException::withMessages([
                'media_asset_id' => "Asset {$asset->kind->value} không phù hợp field {$field->value}.",
            ]);
        }

        if ($this->morphAlias($linkable) !== $field->linkableMorphAlias()) {
            throw ValidationException::withMessages([
                'field' => "Field {$field->value} không thuộc model {$this->morphAlias($linkable)}.",
            ]);
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tính sort_order tiếp theo cho field multiple
     * =====================================================================
     *
     * INPUT:
     * - $linkable: model sở hữu usage
     * - $field: field cần tính thứ tự
     *
     * OUTPUT:
     * - int: thứ tự tiếp theo, bắt đầu từ 0
     */
    private function nextSortOrder(Model $linkable, MediaAssetField $field): int
    {
        return ((int) $linkable->mediaAssetUsagesForField($field)->max('sort_order')) + 1;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy morph alias hiện tại của model linkable
     * =====================================================================
     *
     * INPUT:
     * - $linkable: Eloquent model có morph map
     *
     * OUTPUT:
     * - string: alias dùng trong linkable_type
     */
    private function morphAlias(Model $linkable): string
    {
        return $linkable->getMorphClass();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Ghi activity audit cho mutation usage
     * =====================================================================
     *
     * INPUT:
     * - $actor, $asset, $event, $usage: context mutation đã thành công
     *
     * OUTPUT:
     * - Không trả giá trị; activity được ghi với subject MediaAsset
     */
    private function logUsageActivity(
        User $actor,
        MediaAsset $asset,
        string $event,
        MediaAssetUsage $usage,
    ): void {
        activity()
            ->causedBy($actor)
            ->performedOn($asset)
            ->withProperties([
                'usage_id' => $usage->getKey(),
                'field' => $usage->field->value,
                'linkable_type' => $usage->linkable_type,
                'linkable_id' => $usage->linkable_id,
            ])
            ->log($event);
    }
}
