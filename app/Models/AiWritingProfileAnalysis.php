<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Lưu vòng đời tác vụ phân tích bài mẫu, không phải bài Post.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: casts(), createdBy().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : bài tham khảo, snapshot kết nối không key và output đã validate.
 * - OUTPUT: tác vụ queued/analyzing/ready/failed/cancelled có thời hạn.
 * - SIDE EFFECT: database kỹ thuật; không tự tạo mẫu đã duyệt.
 * =====================================================================
 */
final class AiWritingProfileAnalysis extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'created_by', 'name', 'reference_text', 'source_hash', 'status', 'connection_snapshot_json',
        'prompt_version', 'schema_version', 'result_json', 'diagnostics_json', 'error_code', 'error_message',
        'started_at', 'completed_at', 'expires_at',
    ];

    protected $hidden = ['reference_text', 'connection_snapshot_json'];

    /**
     * =====================================================================
     * CHỨC NĂNG: Ép kiểu snapshot, kết quả và lifecycle.
     * =====================================================================
     * Input: thuộc tính model. Output: array/date có kiểu rõ ràng.
     * Side effect: không ghi database hoặc gọi provider.
     * =====================================================================
     */
    protected function casts(): array
    {
        return [
            'connection_snapshot_json' => 'array', 'result_json' => 'array', 'diagnostics_json' => 'array',
            'started_at' => 'datetime', 'completed_at' => 'datetime', 'expires_at' => 'datetime',
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Liên kết người khởi tạo analysis.
     * =====================================================================
     * Input: model hiện tại. Output: quan hệ BelongsTo User.
     * Side effect: chỉ đọc database khi nạp quan hệ.
     * =====================================================================
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
