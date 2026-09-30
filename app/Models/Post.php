<?php

namespace App\Models;

use App\Enums\PostStatus;
use App\Models\Concerns\HasMediaAssets;
use App\Models\Concerns\HasSeoMetadata;
use App\Models\Concerns\HasSlug;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Post với slug, media và metadata SEO độc lập.
 * CÁC HÀM/METHOD TRONG FILE: casts(), createdBy(), updatedBy(), slugs(),
 * seoFallbacks(), slugSource(), primarySlug(): cast dữ liệu, quan hệ và nguồn slug.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): thuộc tính Post -> model/quan hệ Eloquent.
 * =====================================================================
 */
class Post extends Model
{
    /** @use HasFactory<\Database\Factories\PostFactory> */
    use HasFactory, HasMediaAssets, HasSeoMetadata, HasSlug, SoftDeletes;

    protected $fillable = [
        'created_by',
        'updated_by',
        'title',
        'content',
        'status',
        'published_at',
        'excerpt',
    ];

    /** Input: không có. Output: kiểu enum/date/boolean cho thuộc tính. */
    protected function casts(): array
    {
        return [
            'status' => PostStatus::class,
            'published_at' => 'datetime',
        ];
    }

    /** Input: model hiện tại. Output: quan hệ người tạo. */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Input: model hiện tại. Output: quan hệ người sửa cuối. */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Input: model hiện tại. Output: danh mục được gắn qua pivot đa hình. */
    public function categories(): MorphToMany
    {
        return $this->morphToMany(Category::class, 'categorizable')
            ->withPivot('sort_order')
            ->withTimestamps();
    }

    /** Input: model hiện tại. Output: tag được gắn qua pivot đa hình. */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable')->withTimestamps();
    }

    /** Input: Post hiện tại. Output: fallback canonical theo permalink Post. */
    public function seoFallbacks(): array
    {
        $slug = $this->relationLoaded('slugs')
            ? $this->slugs->first(fn (Slug $item): bool => $item->is_primary && $item->locale === config('app.locale'))?->slug
            : $this->primarySlug()?->slug;

        return [
            'title' => $this->title,
            'description' => $this->excerpt,
            'canonical_url' => $slug ? url('/blog/'.$slug) : null,
            'og_image_id' => $this->seoFeaturedImageId('post.thumbnail'),
        ];
    }

    /** Input: model hiện tại. Output: quan hệ lịch sử slug. */
    public function slugs(): MorphMany
    {
        return $this->morphMany(Slug::class, 'sluggable');
    }

    /** Input: title hiện tại. Output: nguồn SlugService. */
    public function slugSource(): string
    {
        return (string) $this->title;
    }

    /** Input: locale ứng dụng. Output: slug primary hoặc null, đọc DB. */
    public function primarySlug(): ?Slug
    {
        return $this->slugs()
            ->where('is_primary', true)
            ->where('locale', config('app.locale'))
            ->first();
    }
}
