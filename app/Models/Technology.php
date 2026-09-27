<?php

namespace App\Models;

use App\Enums\TechnologyType;
use App\Models\Concerns\HasSlug;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Đại diện cho công nghệ mà resource tương thích
 * =====================================================================
 *
 * Technology mô tả framework, ngôn ngữ, build tool... mà một resource hỗ trợ.
 * Quan hệ với resource là belongsToMany thuần qua pivot `resource_technology`
 * vì bảng này không polymorphic. Bảng technologies không có soft delete nên
 * việc gỡ công nghệ khỏi danh mục chỉ thực hiện bằng cách xoá bản ghi.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - resources(): resource đang dùng công nghệ này
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : dữ liệu công nghệ từ admin taxonomy form
 * - OUTPUT: Technology dùng cho filter theo stack kỹ thuật
 * =====================================================================
 */
class Technology extends Model
{
    /** @use HasFactory<\Database\Factories\TechnologyFactory> */
    use HasFactory, HasSlug;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'type',
    ];

    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo cast enum cho nhóm công nghệ
     * =====================================================================
     *
     * OUTPUT:
     * - array<string, string>: type thành TechnologyType
     */
    protected function casts(): array
    {
        return [
            'type' => TechnologyType::class,
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả về giá trị dùng để dựng slug của công nghệ
     * =====================================================================
     *
     * INPUT:
     * - Không có; đọc cột name của bản ghi hiện tại
     *
     * OUTPUT:
     * - string: name, giá trị mà SlugService chuyển thành slug
     */
    public function slugSource(): string
    {
        return (string) $this->name;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả về tên cột chứa nguồn slug của công nghệ
     * =====================================================================
     *
     * OUTPUT:
     * - string: name
     */
    public function slugSourceColumn(): string
    {
        return 'name';
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy toàn bộ slug của công nghệ
     * =====================================================================
     *
     * OUTPUT:
     * - MorphMany: tập Slug, morph name `sluggable` khớp tên cột trong DB
     */
    public function slugs(): MorphMany
    {
        return $this->morphMany(Slug::class, 'sluggable');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy resource tương thích với công nghệ này
     * =====================================================================
     *
     * OUTPUT:
     * - BelongsToMany: Resource qua pivot resource_technology
     */
    public function resources(): BelongsToMany
    {
        return $this->belongsToMany(Resource::class, 'resource_technology')
            ->withPivot('version_constraint')
            ->withTimestamps();
    }
}
