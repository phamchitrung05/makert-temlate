<?php

namespace App\Models;

use App\Enums\PostStatus;
use App\Models\Concerns\HasMediaAssets;
use App\Models\Concerns\HasSlug;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Đại diện cho bài viết dùng chung Media Library thumbnail/content images. */
class Post extends Model
{
    /** @use HasFactory<\Database\Factories\PostFactory> */
    use HasFactory, HasMediaAssets, HasSlug, SoftDeletes;

    protected $fillable = [
        'created_by',
        'updated_by',
        'title',
        'content',
        'status',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PostStatus::class,
            'published_at' => 'datetime',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function slugs(): MorphMany
    {
        return $this->morphMany(Slug::class, 'sluggable');
    }

    public function slugSource(): string
    {
        return (string) $this->title;
    }

    public function primarySlug(): ?Slug
    {
        return $this->slugs()
            ->where('is_primary', true)
            ->where('locale', config('app.locale'))
            ->first();
    }
}
