<?php

namespace App\Actions\Resources;

use App\Enums\ResourceType;
use App\Enums\ResourceVersionStatus;
use App\Models\ResourceVersion;
use App\Models\ResourceVersionTemplate;
use App\Models\User;
use App\Services\Resources\ResourceVersionCacheInvalidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Cập nhật settings/sort/title/link mapping của template draft
 * =====================================================================
 *
 * Action chỉ đổi metadata admin của version chưa ready; source HTML/artifact
 * không bị sửa. Screen id phải thuộc đúng template version và snapshot manifest
 * được cập nhật bounded để ready gate đọc cùng entry/order mới.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - handle(): lock version/template và ghi settings atomically.
 * - applyScreens(): validate ownership/order và cập nhật screen rows.
 * - manifestSnapshot(): đồng bộ entry/screens vào snapshot.
 * - fresh(): reload response payload.
 * - reject(): tạo ValidationException bounded.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : ResourceVersion template draft, settings và actor id.
 * - OUTPUT: ResourceVersion cập nhật template metadata.
 * - SIDE EFFECT: ghi screens/manifest/activity/cache; không đụng package bytes.
 * - EXCEPTION/TRANSACTION: ValidationException rollback toàn bộ mutation.
 * =====================================================================
 */
final class UpdateResourceTemplateSettingsAction
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Nhận cache invalidator
     * =====================================================================
     * INPUT: ResourceVersionCacheInvalidator từ container.
     * OUTPUT: Action sẵn sàng chạy.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function __construct(private readonly ResourceVersionCacheInvalidator $cacheInvalidator) {}

    /**
     * =====================================================================
     * CHỨC NĂNG: Ghi settings template trong transaction ngắn
     * =====================================================================
     * INPUT: version route snapshot, payload validated và actor id.
     * OUTPUT: ResourceVersion cùng template/screens mới.
     * SIDE EFFECT: Lock version/template, ghi audit/cache.
     * EXCEPTION/TRANSACTION: Reject non-template/ready/invalid screen và rollback.
     * =====================================================================
     */
    public function handle(ResourceVersion $version, array $payload, int $actorId): ResourceVersion
    {
        $versionId = (int) $version->getKey();
        $updated = DB::transaction(function () use ($versionId, $payload, $actorId): ResourceVersion {
            $version = ResourceVersion::query()
                ->with('resource')
                ->whereKey($versionId)
                ->lockForUpdate()
                ->firstOrFail();
            if ($version->resource?->type !== ResourceType::Template) {
                $this->reject('resource_version', 'Chỉ resource type template mới có template settings.');
            }
            if (in_array($version->status, [ResourceVersionStatus::Ready, ResourceVersionStatus::Archived], true)) {
                $this->reject('version', 'Template settings bị khóa sau khi version ready hoặc archive.');
            }

            $template = ResourceVersionTemplate::query()
                ->where('resource_version_id', $versionId)
                ->lockForUpdate()
                ->first();
            if (! $template) {
                $this->reject('template', 'Template version chưa có metadata xử lý.');
            }
            $screens = $template->screens()->get()->keyBy('id');
            if ($screens->isEmpty()) {
                $this->reject('screens', 'Template chưa có screen để cập nhật.');
            }

            $entry = array_key_exists('entry_screen_slug', $payload)
                ? $payload['entry_screen_slug']
                : $template->entry_screen_slug;
            if ($entry !== null && ! $screens->contains(fn ($screen): bool => $screen->slug === $entry)) {
                $this->reject('entry_screen_slug', 'Entry screen không thuộc template version.');
            }

            $rows = (array) ($payload['screens'] ?? []);
            if ($rows !== []) {
                $this->applyScreens($screens, $rows);
            }

            $template->forceFill([
                'entry_screen_slug' => $entry,
                'manifest_json' => $this->manifestSnapshot($template, $entry),
            ])->save();

            activity('resources')
                ->causedBy($actorId ? User::find($actorId) : null)
                ->performedOn($template)
                ->event('template.settings.updated')
                ->withProperties(['resource_version_id' => $versionId])
                ->log('Resource template settings updated.');

            return $this->fresh($version);
        });

        $this->cacheInvalidator->invalidateForVersion((int) $updated->resource_id, $versionId);

        return $updated;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Validate screen ownership/order và ghi title/metadata
     * =====================================================================
     * INPUT: Existing screen map và rows đã validate shape.
     * OUTPUT: Không trả giá trị; rows được persist theo order.
     * SIDE EFFECT: Ghi screen rows; chỉ nhận metadata link_mapping bounded.
     * EXCEPTION/TRANSACTION: ValidationException nếu id thiếu/trùng/sai owner.
     * =====================================================================
     */
    private function applyScreens($screens, array $rows): void
    {
        $ids = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows);
        if (count($ids) !== $screens->count() || count(array_unique($ids)) !== count($ids) || array_diff($screens->keys()->all(), $ids) !== []) {
            $this->reject('screens', 'Danh sách screen phải thuộc đúng template version và đủ một lần mỗi screen.');
        }

        foreach ($rows as $index => $row) {
            $screen = $screens->get((int) $row['id']);
            $metadata = (array) $screen->metadata;
            if (array_key_exists('metadata', $row)) {
                $requested = (array) $row['metadata'];
                if (array_key_exists('link_mapping', $requested)) {
                    $metadata['link_mapping'] = array_values(array_map(
                        static fn (mixed $value): string => mb_substr((string) $value, 0, 300),
                        (array) $requested['link_mapping'],
                    ));
                }
            }
            $screen->forceFill([
                'title' => array_key_exists('title', $row) ? $row['title'] : $screen->title,
                'sort_order' => array_key_exists('sort_order', $row) ? $row['sort_order'] : $index,
                'metadata' => $metadata,
            ])->save();
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đồng bộ entry/order/title vào manifest snapshot
     * =====================================================================
     * INPUT: Template locked và entry slug mới.
     * OUTPUT: JSON-safe bounded manifest snapshot.
     * SIDE EFFECT: Không ghi DB; caller save template.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    private function manifestSnapshot(ResourceVersionTemplate $template, ?string $entry): array
    {
        $snapshot = (array) $template->manifest_json;
        $manifest = (array) ($snapshot['manifest'] ?? []);
        $screens = $template->screens()->get()->map(fn ($screen): array => [
            'slug' => $screen->slug,
            'title' => $screen->title,
            'path' => $screen->source_path,
        ])->values()->all();
        $manifest['entry_screen'] = $entry;
        $manifest['screens'] = $screens;
        $snapshot['manifest'] = $manifest;
        $snapshot['requires_entry_selection'] = $entry === null;
        $snapshot['screens'] = $screens;

        return $snapshot;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Reload version response payload
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
            'template.screens.mediaAssetUsages.mediaAsset.media',
            'mediaAssetUsages.mediaAsset.media',
        ]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Ném validation lỗi settings bounded
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
