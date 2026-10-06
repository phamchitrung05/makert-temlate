<?php

namespace App\Services;

use App\Enums\MediaAssetKind;
use App\Enums\MediaAssetVisibility;
use App\Models\MediaAsset;
use App\Models\SeoMetadata;
use App\Models\User;
use App\Services\Settings\ProjectSettingsService;
use App\Support\SeoRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Đồng bộ và resolve metadata SEO cho mọi model nội dung.
 * =====================================================================
 *
 * Service là boundary ghi SEO metadata. Nó chỉ lưu field do client gửi, không
 * nhận score/checklist; resolve() mới ghép fallback dùng cho API/preview.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - sync(): create/update metadata theo payload partial
 * - resolve(): trả metadata hiệu lực sau khi ghép fallback
 * - fields(): lọc các field SEO được phép ghi
 * - raw(): metadata gốc cho form, không ghi fallback vào input
 * - assertOgImage(): kiểm tra ảnh public và quyền attach
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : model có quan hệ seoMetadata và mảng metadata đã validate.
 * - OUTPUT: SeoMetadata hoặc mảng metadata hiệu lực.
 * =====================================================================
 */
class SeoMetadataService
{
    private const FIELDS = [
        'focus_keyword',
        'seo_title',
        'seo_description',
        'canonical_url',
        'robots_index',
        'robots_follow',
        'og_title',
        'og_description',
        'og_image_id',
    ];

    /** Input: model SEO và payload đã validate. Output: metadata đã lưu hoặc null nếu payload rỗng. */
    public function sync(Model $seoable, array $attributes, ?User $actor = null): ?SeoMetadata
    {
        $values = $this->fields($attributes);
        if ($values === []) {
            return $seoable->relationLoaded('seoMetadata')
                ? $seoable->seoMetadata
                : $seoable->seoMetadata()->first();
        }

        $values = Validator::make($values, SeoRules::rules())->validate();

        return DB::transaction(function () use ($seoable, $values, $actor): SeoMetadata {
            // Khóa model cha để serialize hai lần tạo metadata đầu tiên.
            $seoable->newQuery()->whereKey($seoable->getKey())->lockForUpdate()->firstOrFail();
            $this->assertOgImage($values, $actor);
            $metadata = $seoable->seoMetadata()->updateOrCreate([], $values)->refresh();
            $seoable->setRelation('seoMetadata', $metadata);

            return $metadata;
        });
    }

    /** Input: model nội dung. Output: field gốc/default; không đưa fallback vào form. */
    public function raw(Model $seoable): array
    {
        $seoable->loadMissing('seoMetadata');
        $defaults = array_fill_keys(self::FIELDS, null);
        $defaults['robots_index'] = true;
        $defaults['robots_follow'] = true;

        return array_replace($defaults, $seoable->seoMetadata?->only(self::FIELDS) ?? []);
    }

    /** Input: model có trait HasSeoMetadata. Output: metadata hiệu lực đã fallback. */
    public function resolve(Model $seoable): array
    {
        $fallbacks = method_exists($seoable, 'seoFallbacks')
            ? $seoable->seoFallbacks()
            : [];
        $raw = $this->raw($seoable);
        // Global defaults chỉ áp dụng fallback, không ghi đè SEO riêng của nội dung.
        $projectSettings = app(ProjectSettingsService::class);
        $global = $projectSettings->effective('seo');
        $site = $projectSettings->effective('site');
        $fallbackTitle = str_replace(['%title%', '%sitename%'], [$fallbacks['title'] ?? '', $site['site_name']], $global['title_format']);
        // Input: giá trị ghi đè và fallback. Output: chuỗi không rỗng hoặc fallback.
        $value = static fn ($override, $fallback) => is_string($override) && trim($override) !== '' ? trim($override) : $fallback;
        $title = $value($raw['seo_title'], $fallbackTitle);
        $description = $value($raw['seo_description'], ($fallbacks['description'] ?? null) ?: $global['default_description']);

        return array_replace($raw, [
            'seo_title' => $title,
            'seo_description' => $description,
            'canonical_url' => $value($raw['canonical_url'], $fallbacks['canonical_url'] ?? null),
            'og_title' => $value($raw['og_title'], $title),
            'og_description' => $value($raw['og_description'], $description),
            'og_image_id' => $raw['og_image_id'] ?? ($fallbacks['og_image_id'] ?? null),
        ]);
    }

    /** Input: payload bất kỳ. Output: chỉ field SEO được phép, giữ null để hỗ trợ clear. */
    public function fields(array $attributes): array
    {
        return array_intersect_key($attributes, array_flip(self::FIELDS));
    }

    /** Input: field SEO có thể chứa og_image_id. Output: không đổi nếu asset là ảnh public. */
    private function assertOgImage(array $values, ?User $actor): void
    {
        if (! array_key_exists('og_image_id', $values)) {
            return;
        }

        if ($values['og_image_id'] === null) {
            abort_unless($actor?->can('media.attach'), 403);

            return;
        }

        $asset = MediaAsset::query()->lockForUpdate()->find($values['og_image_id']);
        if ($asset?->kind === MediaAssetKind::Image && $asset->visibility === MediaAssetVisibility::Public) {
            abort_unless($actor, 403);
            Gate::forUser($actor)->authorize('attach', $asset);

            return;
        }

        throw ValidationException::withMessages([
            'og_image_id' => 'Ảnh Open Graph phải là image public hợp lệ.',
        ]);
    }
}
