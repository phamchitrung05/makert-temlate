<?php

namespace App\Services\Catalog;

use App\Enums\MediaAssetField;
use App\Enums\ResourceType;
use App\Enums\ResourceVersionStatus;
use App\Models\Resource;
use App\Models\ResourceVersion;
use App\Models\Slug;
use App\Services\ResourceVersionPackageGuard;
use App\Services\SeoMetadataService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Query/DTO Resource public template catalog bounded
 * CÁC HÀM/METHOD TRONG FILE:
 * - paginate(): lọc published/public/type/q và phân trang ổn định.
 * - resolveSlug(): resolve primary/historical slug nhưng không lộ private.
 * - card(): DTO card allowlist có default version/template state.
 * - detail(): DTO detail có SEO/screens bounded.
 * - visibleVersion(): chọn default ready/template/package clean.
 * - versionRelations(): quan hệ version/package để eager load theo lô.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): filters/slug -> paginator/DTO public.
 * =====================================================================
 */
final class ResourceCatalogService
{
    /** Input: package guard và SEO service. Output: public query service. */
    public function __construct(
        private readonly ResourceVersionPackageGuard $packages,
        private readonly SeoMetadataService $seo,
    ) {}

    /** Input: allowlist query params. Output: paginator DTO/resource models public-safe. */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Resource::query()
            ->publiclyVisible()
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->where('type', ResourceType::Template)
            ->with([
                'slugs' => fn ($q) => $q->where('locale', config('app.locale')),
                'seoMetadata',
                'mediaAssetUsages.mediaAsset.media',
            ])
            ->with($this->versionRelations())
            ->orderByDesc('published_at')->orderByDesc('id');
        if (filled($filters['q'] ?? null)) {
            $query->where(fn ($q) => $q->where('title', 'like', '%'.$filters['q'].'%')->orWhere('short_description', 'like', '%'.$filters['q'].'%'));
        }
        if (filled($filters['type'] ?? null) && $filters['type'] === ResourceType::Template->value) {
            $query->where('type', $filters['type']);
        }

        return $query->paginate((int) ($filters['per_page'] ?? 12))->withQueryString();
    }

    /** Input: slug. Output: [resource, historical] or null when hidden/not found. */
    public function resolveSlug(string $slug): ?array
    {
        $record = Slug::query()->where('slug', $slug)->where('locale', config('app.locale'))->first();
        $resource = $record?->sluggable;
        if (! $resource instanceof Resource || $resource->type !== ResourceType::Template
            || ! $resource->publiclyVisible()->whereKey($resource->id)->whereNotNull('published_at')->where('published_at', '<=', now())->exists()) {
            return null;
        }

        return [$resource->load(['slugs', 'seoMetadata', 'categories.slugs', 'mediaAssetUsages.mediaAsset.media']), ! $record->is_primary];
    }

    /** Input: public Resource. Output: bounded catalog card. */
    public function card(Resource $resource): array
    {
        $version = $this->visibleVersion($resource);

        $cover = $resource->relationLoaded('mediaAssetUsages')
            ? $resource->mediaAssetUsages->first(fn ($usage): bool => $usage->field?->value === 'resource.cover')?->mediaAsset?->getFirstMedia('library')?->getUrl()
            : null;

        return [
            'id' => $resource->id, 'title' => $resource->title, 'type' => $resource->type->value,
            'slug' => $resource->primarySlug()?->slug, 'short_description' => Str::limit((string) $resource->short_description, 300),
            'cover' => $cover,
            'default_version' => $version ? ['id' => $version->id, 'version' => $version->version] : null,
            'seo' => $this->seo->resolve($resource),
        ];
    }

    /** Input: public Resource. Output: bounded detail with screens and SEO. */
    public function detail(Resource $resource): array
    {
        $version = $this->visibleVersion($resource);
        $card = $this->card($resource);
        $card['description'] = Str::limit((string) $resource->description, 5000);
        $card['categories'] = $resource->categories->map(fn ($category): array => ['name' => $category->name, 'slug' => $category->primarySlug()?->slug])->values()->all();
        $card['screens'] = $version?->template?->screens?->map(fn ($screen): array => [
            'slug' => $screen->slug, 'title' => $screen->title, 'source_path' => $screen->source_path,
        ])->values()->all() ?? [];

        return $card;
    }

    /** Input: Resource. Output: default ready/template/package-clean version or null. */
    public function visibleVersion(Resource $resource): ?ResourceVersion
    {
        $resource->loadMissing($this->versionRelations());
        foreach ($resource->versions as $version) {
            if ($resource->type !== ResourceType::Template || $version->status !== ResourceVersionStatus::Ready
                || ! $version->is_default || ! $version->template || $version->template->processing_status->value !== 'ready') {
                continue;
            }
            try {
                $this->packages->cleanLoadedPackageFor($version);

                return $version;
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /** Input: không có. Output: quan hệ ready/default và package/template để đọc catalog theo lô. */
    private function versionRelations(): array
    {
        return [
            'versions' => fn ($query) => $query->where('status', ResourceVersionStatus::Ready)->where('is_default', true),
            'versions.template.screens',
            'versions.mediaAssetUsages' => fn ($query) => $query->where('field', MediaAssetField::ResourceVersionPackage),
            'versions.mediaAssetUsages.mediaAsset.media',
        ];
    }
}
