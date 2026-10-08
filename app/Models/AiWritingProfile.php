<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Mẫu văn phong được người dùng duyệt và tái sử dụng.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: casts(), createdBy().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : tên, rules, hướng dẫn và bằng chứng được duyệt.
 * - OUTPUT: profile có phiên bản dùng để snapshot vào article run.
 * - SIDE EFFECT: Eloquent lưu database khi caller yêu cầu, không gọi AI.
 * =====================================================================
 */
final class AiWritingProfile extends Model
{
    protected $fillable = [
        'name', 'description', 'rules_json', 'evidence_json', 'style_instructions',
        'version', 'origin', 'status', 'source_hash', 'analysis_metadata_json', 'is_enabled', 'created_by',
    ];

    /**
     * =====================================================================
     * CHỨC NĂNG: Ép kiểu dữ liệu profile.
     * =====================================================================
     * Input: thuộc tính Eloquent. Output: JSON arrays, boolean và version integer.
     * Side effect: không ghi database.
     * =====================================================================
     */
    protected function casts(): array
    {
        return ['rules_json' => 'array', 'evidence_json' => 'array', 'analysis_metadata_json' => 'array', 'is_enabled' => 'boolean', 'version' => 'integer'];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Liên kết người tạo profile.
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
