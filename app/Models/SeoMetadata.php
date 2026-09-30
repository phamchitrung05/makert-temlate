<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Đại diện metadata SEO dùng chung cho model nội dung.
 * =====================================================================
 *
 * SeoMetadata không chứa điểm SEO hoặc kết quả checklist. Các giá trị đó được
 * tính realtime từ nội dung và metadata khi hiển thị form/preview.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - seoable(): model nội dung sở hữu metadata
 * - ogImage(): ảnh Open Graph tùy chọn
 * - casts(): cast robots thành boolean
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : metadata SEO từ service dùng chung.
 * - OUTPUT: bản ghi seo_metadata gắn với model nội dung.
 * =====================================================================
 */
class SeoMetadata extends Model
{
    use HasFactory;

    protected $table = 'seo_metadata';

    protected $fillable = [
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

    /** Input: metadata hiện tại. Output: model nội dung sở hữu SEO. */
    public function seoable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Input: metadata hiện tại. Output: MediaAsset OG image hoặc null. */
    public function ogImage(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'og_image_id');
    }

    /** Input: không có. Output: cast boolean cho robots flags. */
    protected function casts(): array
    {
        return [
            'robots_index' => 'boolean',
            'robots_follow' => 'boolean',
        ];
    }
}
