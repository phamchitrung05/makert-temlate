<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Bản ghi theo dõi dùng chung cho mọi tác vụ AI bất đồng bộ.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - casts(): ép progress, metadata và các mốc lifecycle về kiểu an toàn.
 * - user(): liên kết actor sở hữu tracker.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : metadata lifecycle do service tracker ghi.
 * - OUTPUT: task summary an toàn cho popup và API detail.
 * - SIDE EFFECT: Eloquent đọc/ghi ai_task_runs; không gọi provider.
 * =====================================================================
 */
final class AiTaskRun extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'dedupe_key', 'task_type', 'source', 'model', 'provider', 'status', 'progress',
        'user_id', 'job_id', 'taskable_type', 'taskable_id', 'error_code', 'error_message',
        'metadata_json', 'started_at', 'completed_at', 'expires_at',
    ];

    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo kiểu dữ liệu lifecycle và metadata tracker.
     * =====================================================================
     * INPUT: thuộc tính Eloquent của ai_task_runs. OUTPUT: array/json/datetime đã cast.
     * SIDE EFFECT: không ghi database hoặc gọi provider.
     * EXCEPTION/TRANSACTION: không mở transaction.
     * =====================================================================
     */
    protected function casts(): array
    {
        // JSON chỉ chứa metadata hiển thị bounded; không lưu source/payload/secret.
        return [
            'progress' => 'integer',
            'metadata_json' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả actor sở hữu tracker.
     * =====================================================================
     * INPUT: model tracker hiện tại. OUTPUT: BelongsTo User.
     * SIDE EFFECT: chỉ đọc database khi relation được nạp.
     * EXCEPTION/TRANSACTION: không mở transaction hoặc gọi provider.
     * =====================================================================
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
