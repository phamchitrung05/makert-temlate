<?php

namespace App\Models;

use App\Models\Concerns\HasMediaAssets;
use Database\Factories\ResourceVersionTemplateScreenFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Lưu một màn hình trong ResourceVersionTemplate
 * =====================================================================
 *
 * Screen là HTML path đã được AI chuẩn hóa trong template.json. Model giữ
 * slug/title/path/thứ tự và metadata bounded; screenshot preview dùng trait
 * HasMediaAssets để attach qua field template_screen.preview ở task sau.
 * Không lưu nội dung HTML hoặc tạo file riêng trong model.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - resourceVersionTemplate(): template metadata sở hữu screen
 * - template(): alias ngắn cho resourceVersionTemplate()
 * - scopeOrdered(): query screen theo thứ tự ổn định
 * - casts(): cast metadata/sort_order
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : screen DTO từ manifest v1 và processing job
 * - OUTPUT: screen row có thể eager-load media usage cho preview
 * - SIDE EFFECT: Eloquent persistence khi caller save; model không tự transaction
 * - EXCEPTION/TRANSACTION: unique/FK lỗi do database; caller chịu transaction
 * =====================================================================
 */
class ResourceVersionTemplateScreen extends Model
{
    /** @use HasFactory<ResourceVersionTemplateScreenFactory> */
    use HasFactory, HasMediaAssets;

    /** @var list<string> */
    protected $fillable = [
        'resource_version_template_id',
        'slug',
        'title',
        'source_path',
        'sort_order',
        'metadata',
    ];

    /**
     * =====================================================================
     * CHỨC NĂNG: Cast metadata và sort order của screen
     * =====================================================================
     * INPUT: State hydrate từ database.
     * OUTPUT: array cast JSON thành array và sort_order thành integer.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy template metadata sở hữu screen
     * =====================================================================
     * INPUT: Không có; dùng resource_version_template_id.
     * OUTPUT: BelongsTo ResourceVersionTemplate.
     * SIDE EFFECT: Query khi relation được gọi.
     * EXCEPTION/TRANSACTION: Không tự mở transaction.
     * =====================================================================
     */
    public function resourceVersionTemplate(): BelongsTo
    {
        return $this->belongsTo(ResourceVersionTemplate::class, 'resource_version_template_id');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Cung cấp alias ngắn cho relation template
     * =====================================================================
     * INPUT: Không có; dùng relation resourceVersionTemplate().
     * OUTPUT: BelongsTo ResourceVersionTemplate.
     * SIDE EFFECT: Query khi relation được gọi.
     * EXCEPTION/TRANSACTION: Không tự mở transaction.
     * =====================================================================
     */
    public function template(): BelongsTo
    {
        return $this->resourceVersionTemplate();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lọc screen theo thứ tự menu ổn định
     * =====================================================================
     * INPUT: Builder ResourceVersionTemplateScreen.
     * OUTPUT: Builder sort_order rồi id tăng dần.
     * SIDE EFFECT: Không có; chưa thực thi query.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
