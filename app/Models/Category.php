<?php

namespace App\Models;

use App\Enums\TaxonomyStatus;
use App\Models\Concerns\HasSlug;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Đại diện cho danh mục dạng cây phân cấp
 * =====================================================================
 *
 * Category tổ chức resource theo cây cha – con qua cột `parent_id`. Cùng một
 * bảng `categorizables` polymorphic gắn category với resource nên Category
 * không chỉ dùng cho catalog mà còn dùng được cho blog ở MVP 2.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - parent(), children(): các cấp của cây danh mục
 * - slugs(): tập slug của danh mục
 * - resources(): resource đã gắn vào danh mục này
 * - scopeRoots(): lọc danh mục cấp cao nhất
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : dữ liệu danh mục từ admin taxonomy form
 * - OUTPUT: Category dùng cho filter catalog và cây điều hướng
 * =====================================================================
 */
class Category extends Model
{
    /** @use HasFactory<\Database\Factories\CategoryFactory> */
    use HasFactory, HasSlug, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'parent_id',
        'name',
        'description',
        'status',
        'sort_order',
    ];

    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo cast enum và số cho danh mục
     * =====================================================================
     *
     * OUTPUT:
     * - array<string, string>: status thành TaxonomyStatus, sort_order thành int
     */
    protected function casts(): array
    {
        return [
            'status' => TaxonomyStatus::class,
            'sort_order' => 'integer',
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy danh mục cha
     * =====================================================================
     *
     * OUTPUT:
     * - BelongsTo: Category hoặc null khi là cấp cao nhất
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy các danh mục con
     * =====================================================================
     *
     * OUTPUT:
     * - HasMany: các Category có parent_id trỏ về bản ghi hiện tại
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy toàn bộ slug của danh mục
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
     * CHỨC NĂNG: Trả về giá trị dùng để dựng slug của danh mục
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
     * CHỨC NĂNG: Trả về tên cột chứa nguồn slug của danh mục
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
     * CHỨC NĂNG: Lấy resource thuộc danh mục
     * =====================================================================
     *
     * OUTPUT:
     * - MorphToMany: Resource qua pivot categorizables
     */
    public function resources(): MorphToMany
    {
        return $this->morphedByMany(Resource::class, 'categorizable')
            ->withPivot('sort_order')
            ->withTimestamps();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Giới hạn query chỉ lấy danh mục cấp cao nhất
     * =====================================================================
     *
     * INPUT:
     * - Không có; điều kiện cố định là parent_id is null
     *
     * OUTPUT:
     * - Builder: query đã lọc
     */
    public function scopeRoots($query)
    {
        return $query->whereNull('parent_id')->orderBy('sort_order');
    }
}
