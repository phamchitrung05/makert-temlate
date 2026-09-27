<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Lưu slug tập trung cho mọi model có URL public
 * =====================================================================
 *
 * Mọi slug nằm trong bảng `slugable` thay vì cột `slug` rải rác trên từng
 * bảng. Mỗi model có đúng một slug `is_primary = true` cho mỗi locale; slug
 * cũ được giữ lại với `is_primary = false` để phục vụ redirect 301.
 *
 * Lưu ý tên cột trong bảng là `sluggable_type` và `sluggable_id` (viết sai
 * chính tả, hai chữ g) nên các quan hệ phải khai báo morph name là `sluggable`.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - sluggable(): model sở hữu slug hiện tại
 * - scopePrimary(): lọc slug đang là primary của locale hiện tại
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : slug, locale và morph key do SlugService sinh ra
 * - OUTPUT: model chứa bản ghi slugable
 * =====================================================================
 */
class Slug extends Model
{
    /** @use HasFactory<\Database\Factories\SlugFactory> */
    use HasFactory;

    /**
     * Tên bảng lưu trữ slug, khai báo tường minh vì không theo quy ước
     * số nhiều của Eloquent.
     *
     * @var string
     */
    protected $table = 'slugable';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'slug',
        'locale',
        'is_primary',
    ];

    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo cast cho cột boolean của slug
     * =====================================================================
     *
     * OUTPUT:
     * - array<string, string>: is_primary được cast sang boolean
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy model sở hữu slug này
     * =====================================================================
     *
     * INPUT:
     * - Không có; dùng morph key `sluggable_type` và `sluggable_id`
     *
     * OUTPUT:
     * - MorphTo: Resource, Category, Tag hoặc Post tương ứng
     *
     * SIDE EFFECT:
     * - Truy vấn bảng của model đích theo id trong `sluggable_id`
     */
    public function sluggable(): MorphTo
    {
        return $this->morphTo('sluggable');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Giới hạn query chỉ lấy slug primary của locale hiện tại
     * =====================================================================
     *
     * INPUT:
     * - Không có; locale lấy từ config app
     *
     * OUTPUT:
     * - Builder: query đã lọc is_primary và locale
     */
    public function scopePrimary($query)
    {
        return $query
            ->where('is_primary', true)
            ->where('locale', config('app.locale'));
    }
}
