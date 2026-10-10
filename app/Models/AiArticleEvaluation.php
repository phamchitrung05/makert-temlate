<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Model điểm tạm của một candidate bài AI.
 * =====================================================================
 * Model giữ kết quả evaluator theo generation và content hash. Điểm ready
 * được dùng làm cổng duyệt; điểm chưa chọn sẽ hết hạn cùng candidate.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - casts(): khai báo kiểu JSON, điểm và thời gian.
 * - run(): liên kết candidate AiImport.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : lifecycle evaluator, snapshot/hash, điểm và dẫn chứng đã validate.
 * - OUTPUT: bản ghi evaluation scoped theo candidate.
 * =====================================================================
 */
final class AiArticleEvaluation extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'run_id', 'session_id', 'generation_no', 'candidate_hash', 'source_hash',
        'rubric_version', 'prompt_version', 'schema_version', 'status', 'score_total',
        'scores_json', 'evidence_json', 'source_references_json', 'eligibility_json',
        'connection_snapshot_json', 'diagnostics_json', 'usage_json', 'attempt',
        'error_code', 'error_message', 'started_at', 'completed_at', 'expires_at',
    ];

    /**
     * Ép kiểu các snapshot evaluator trước khi trả API hoặc tính cổng duyệt.
     *
     * Input: thuộc tính Eloquent.
     * Output: JSON thành array, điểm thành float và mốc thời gian thành Carbon.
     * Side effect: không query hoặc ghi database.
     */
    protected function casts(): array
    {
        return [
            'generation_no' => 'integer', 'score_total' => 'float', 'attempt' => 'integer',
            'scores_json' => 'array', 'evidence_json' => 'array',
            'source_references_json' => 'array', 'eligibility_json' => 'array',
            'connection_snapshot_json' => 'array', 'diagnostics_json' => 'array',
            'usage_json' => 'array', 'started_at' => 'datetime',
            'completed_at' => 'datetime', 'expires_at' => 'datetime',
        ];
    }

    /**
     * Trả candidate nguồn của evaluation.
     *
     * Input: run_id UUID.
     * Output: quan hệ BelongsTo AiImport.
     * Side effect: chỉ query khi caller eager-load hoặc truy cập relation.
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AiImport::class, 'run_id');
    }
}
