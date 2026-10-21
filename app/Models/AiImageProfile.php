<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Visual Style / prompt ảnh được phân tích và tái sử dụng.
 * =====================================================================
 * Model dùng chung cho hai vai trò: (1) image profile phẳng hiện có của
 * ImageProfileService (prompt/negative_prompt) và (2) Visual Style Engine —
 * Style DNA có cấu trúc trong dna_json kèm nhiều Shot Recipe. Bảng vật lý
 * ai_image_profiles giữ nguyên tên để không phá code hiện tại.
 * CÁC HÀM/METHOD TRONG FILE: casts(), shots(), createdBy().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : ảnh tham chiếu, connection snapshot, DNA đã validate.
 * - OUTPUT: style/prompt có trạng thái lifecycle để analyze/activate/reuse.
 * - SIDE EFFECT: Eloquent lưu database khi caller yêu cầu, không gọi AI.
 * =====================================================================
 */
final class AiImageProfile extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['reference_path', 'connection_snapshot_json', 'analysis_instructions', 'reference_hash'];

    /**
     * =====================================================================
     * CHỨC NĂNG: Ép kiểu dữ liệu profile/style.
     * =====================================================================
     * Input: thuộc tính Eloquent. Output: JSON arrays, boolean và version integer.
     * Side effect: không ghi database.
     * =====================================================================
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'connection_snapshot_json' => 'array',
            'dna_json' => 'array',
            'version' => 'integer',
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Liên kết các Shot Recipe thuộc Visual Style.
     * =====================================================================
     * Input: model hiện tại. Output: quan hệ HasMany VisualStyleShot sắp theo sort_order.
     * Side effect: chỉ query khi nạp quan hệ; xóa style cascade xóa shots ở DB.
     * =====================================================================
     */
    public function shots(): HasMany
    {
        return $this->hasMany(VisualStyleShot::class, 'visual_style_id')->orderBy('sort_order')->orderBy('id');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Liên kết người tạo style.
     * =====================================================================
     * Input: model hiện tại. Output: quan hệ BelongsTo User.
     * Side effect: chỉ query khi nạp quan hệ.
     * =====================================================================
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
