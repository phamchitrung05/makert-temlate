<?php

namespace App\Models;

use App\Enums\TaxonomyStatus;
use App\Models\Concerns\HasSlug;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Đại diện cho tag gắn vào nhiều loại nội dung
 * =====================================================================
 *
 * Tag là taxonomy phẳng, dùng chung cho resource và blog thông qua bảng
 * `taggables` polymorphic. Không có cây cha – con như Category.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - slugs(): tập slug của tag
 * - resources(): resource đã gắn tag này
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : dữ liệu tag từ admin taxonomy form
 * - OUTPUT: Tag dùng cho filter và gợi ý từ khoá
 * =====================================================================
 */
class Tag extends Model
{
    /** @use HasFactory<\Database\Factories\TagFactory> */
    use HasFactory, HasSlug, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'status',
    ];

    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo cast enum cho tag
     * =====================================================================
     *
     * OUTPUT:
     * - array<string, string>: status thành TaxonomyStatus
     */
    protected function casts(): array
    {
        return [
            'status' => TaxonomyStatus::class,
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy toàn bộ slug của tag
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
     * CHỨC NĂNG: Trả về giá trị dùng để dựng slug của tag
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
     * CHỨC NĂNG: Trả về tên cột chứa nguồn slug của tag
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
     * CHỨC NĂNG: Lấy resource đã gắn tag này
     * =====================================================================
     *
     * OUTPUT:
     * - MorphToMany: Resource qua pivot taggables
     */
    public function resources(): MorphToMany
    {
        return $this->morphedByMany(Resource::class, 'taggable')->withTimestamps();
    }
}
