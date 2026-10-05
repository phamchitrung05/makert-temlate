<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Lưu checkpoint đã validate của từng bước tạo bài AI.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - casts(): ép kiểu output, diagnostics và thời điểm bước.
 * - import(): trả quan hệ tới tác vụ kỹ thuật sở hữu checkpoint.
 * =====================================================================
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : bước, hash input và kết quả đã được pipeline kiểm tra.
 * - OUTPUT: checkpoint để resume khi nguồn/profile/prompt/schema không đổi.
 * - SIDE EFFECT: ghi database qua Eloquent; không tự gọi model AI.
 * =====================================================================
 */
class AiImportStep extends Model
{
    protected $fillable = [
        'ai_import_id', 'step_key', 'input_hash', 'status', 'attempt',
        'output_json', 'diagnostics_json', 'started_at', 'completed_at',
    ];

    /**
     * =====================================================================
     * CHỨC NĂNG: Ép kiểu artifacts và thời gian của checkpoint
     * =====================================================================
     * INPUT: thuộc tính database.
     * OUTPUT: JSON thành array, thời gian thành datetime và attempt thành int.
     * SIDE EFFECT: không gọi network hoặc ghi database.
     * =====================================================================
     */
    protected function casts(): array
    {
        return [
            'output_json' => 'array', 'diagnostics_json' => 'array',
            'attempt' => 'integer', 'started_at' => 'datetime', 'completed_at' => 'datetime',
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả tác vụ AI sở hữu checkpoint
     * =====================================================================
     * INPUT: ai_import_id trên checkpoint.
     * OUTPUT: quan hệ BelongsTo AiImport.
     * SIDE EFFECT: chỉ tạo truy vấn quan hệ; không gọi provider.
     * =====================================================================
     */
    public function import(): BelongsTo
    {
        return $this->belongsTo(AiImport::class, 'ai_import_id');
    }
}
