<?php

namespace App\Models;

use App\Enums\ResourceStatus;
use App\Enums\ResourceType;
use App\Enums\ResourceVisibility;
use App\Models\Concerns\HasMediaAssets;
use App\Models\Concerns\HasSlug;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Đại diện cho một tài nguyên số được phát hành
 * =====================================================================
 *
 * Resource là sản phẩm nội dung của cửa hàng. Model giữ quan hệ với admin
 * đứng tên phát hành, taxonomy (category, tag, technology), các phiên bản
 * và tập slug. Trạng thái và visibility dùng enum nên việc gán sai giá trị
 * sẽ ném exception ngay tại model.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - author(), createdBy(), updatedBy(): admin chịu trách nhiệm
 * - slugs(): tất cả slug của resource, kể cả slug cũ
 * - categories(), tags(), technologies(): taxonomy gắn vào resource
 * - versions(): các phiên bản đã phát hành
 * - scopePublished(): lọc resource đã xuất bản
 * - scopePubliclyVisible(): lọc resource công khai cho guest
 * - primarySlug(): slug hiện hành của locale đang chạy
 * - getActivitylogOptions(): cấu hình audit log cho model
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : dữ liệu từ CreateResourceAction hoặc UpdateResourceAction
 * - OUTPUT: Resource dùng cho admin API, public catalog và download
 * =====================================================================
 */
class Resource extends Model
{
    /** @use HasFactory<\Database\Factories\ResourceFactory> */
    use HasFactory, HasMediaAssets, HasSlug, LogsActivity, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'author_id',
        'created_by',
        'updated_by',
        'type',
        'title',
        'code',
        'short_description',
        'description',
        'status',
        'visibility',
        'is_featured',
        'demo_url',
        'documentation_url',
        'seo_title',
        'seo_description',
        'canonical_url',
        'published_at',
    ];

    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo cast enum, boolean và datetime cho resource
     * =====================================================================
     *
     * OUTPUT:
     * - array<string, string>: type/status/visibility thành enum, các cột
     *   boolean và datetime được cast tương ứng
     */
    protected function casts(): array
    {
        return [
            'type' => ResourceType::class,
            'status' => ResourceStatus::class,
            'visibility' => ResourceVisibility::class,
            'is_featured' => 'boolean',
            'view_count' => 'integer',
            'download_count' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy admin đứng tên phát hành
     * =====================================================================
     *
     * OUTPUT:
     * - BelongsTo: User hoặc null nếu resource được tạo bởi hệ thống
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy admin đã tạo bản ghi
     * =====================================================================
     *
     * OUTPUT:
     * - BelongsTo: User hoặc null
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy admin đã sửa bản ghi gần nhất
     * =====================================================================
     *
     * OUTPUT:
     * - BelongsTo: User hoặc null
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy toàn bộ slug của resource gồm cả slug cũ
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
     * CHỨC NĂNG: Lấy danh mục đã gắn cho resource
     * =====================================================================
     *
     * OUTPUT:
     * - MorphToMany: Category qua pivot categorizables, có thêm sort_order
     */
    public function categories(): MorphToMany
    {
        return $this->morphToMany(Category::class, 'categorizable')
            ->withPivot('sort_order')
            ->withTimestamps();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy tag đã gắn cho resource
     * =====================================================================
     *
     * OUTPUT:
     * - MorphToMany: Tag qua pivot taggables
     */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable')->withTimestamps();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy công nghệ tương thích kèm ràng buộc phiên bản
     * =====================================================================
     *
     * OUTPUT:
     * - BelongsToMany: Technology qua pivot resource_technology
     */
    public function technologies(): BelongsToMany
    {
        return $this->belongsToMany(Technology::class, 'resource_technology')
            ->withPivot('version_constraint')
            ->withTimestamps();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy các phiên bản của resource
     * =====================================================================
     *
     * OUTPUT:
     * - HasMany: ResourceVersion
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ResourceVersion::class);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Giới hạn query chỉ lấy resource đã xuất bản
     * =====================================================================
     *
     * INPUT:
     * - Không có; điều kiện cố định là status = published
     *
     * OUTPUT:
     * - Builder: query đã lọc theo ResourceStatus::Published
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', ResourceStatus::Published);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Giới hạn query chỉ lấy resource guest được phép xem
     * =====================================================================
     *
     * INPUT:
     * - Không có; điều kiện cố định là status = published và
     *   visibility = public
     *
     * OUTPUT:
     * - Builder: query an toàn cho public catalog
     */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query
            ->where('status', ResourceStatus::Published)
            ->where('visibility', ResourceVisibility::Public);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả về giá trị dùng để dựng slug của resource
     * =====================================================================
     *
     * INPUT:
     * - Không có; đọc cột title của bản ghi hiện tại
     *
     * OUTPUT:
     * - string: title, giá trị mà SlugService chuyển thành slug
     */
    public function slugSource(): string
    {
        return (string) $this->title;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy slug hiện hành của resource theo locale đang chạy
     * =====================================================================
     *
     * INPUT:
     * - Không có; locale lấy từ config app
     *
     * OUTPUT:
     * - Slug|null: bản ghi slug primary hoặc null nếu chưa tạo slug
     */
    public function primarySlug(): ?Slug
    {
        return $this->slugs()
            ->where('is_primary', true)
            ->where('locale', config('app.locale'))
            ->first();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Cấu hình activity log cho resource
     * =====================================================================
     *
     * OUTPUT:
     * - LogOptions: chỉ ghi thay đổi, bỏ qua counter và mô tả dài
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('resources')
            ->logOnlyDirty()
            ->logExcept([
                'view_count',
                'download_count',
                'description',
            ])
            ->dontSubmitEmptyLogs();
    }
}
