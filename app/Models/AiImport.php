<?php

namespace App\Models;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Lưu trạng thái và kết quả một lần import bài viết bằng AI.
 * =====================================================================
 * Model phục vụ API polling, giữ URL nguồn, trạng thái xử lý và draft JSON.
 * CÁC HÀM/METHOD TRONG FILE:
 * - casts(): ép kiểu kết quả và thời hạn.
 * - createdBy(): liên kết quản trị viên khởi tạo import.
 * - candidates(), steps(): liên kết candidate và checkpoint của run.
 * - advance(), isCancelled(): cập nhật tiến độ an toàn khi run bị hủy.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : dữ liệu import và kết quả pipeline.
 * - OUTPUT: bản ghi AiImport cùng quan hệ người tạo.
 * =====================================================================
 */

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiImport extends Model
{
    use HasUuids;

    public const RUNNING_STATUSES = ['queued', 'fetching', 'extracting', 'rewriting', 'analyzing', 'planning', 'writing', 'editing', 'validating', 'seo', 'thumbnail'];

    public const TERMINAL_STATUSES = ['ready', 'failed', 'cancelled', 'expired'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'created_by', 'source_url', 'source_text', 'normalized_url', 'source_hash', 'status',
        'current_step', 'progress', 'input_json', 'source_meta_json', 'result_json',
        'error_code', 'error_message', 'provider', 'prompt_version', 'started_at',
        'completed_at', 'expires_at',
        'session_id', 'parent_id', 'operation', 'applied_target_id', 'applied_fields',
    ];

    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo cast cho state/result/input của AI run.
     * =====================================================================
     * INPUT: không có.
     * OUTPUT: enum/date/JSON cast của model.
     * SIDE EFFECT: Không ghi database hoặc gọi provider.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    protected function casts(): array
    {
        return [
            'input_json' => 'array',
            'source_meta_json' => 'array',
            'result_json' => 'array',
            'applied_fields' => 'array',
            'applied_target_id' => 'integer',
            'progress' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả quan hệ actor tạo run.
     * =====================================================================
     * INPUT: model hiện tại.
     * OUTPUT: BelongsTo User.
     * SIDE EFFECT: Không ghi database hoặc gọi provider.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả các run cùng session hoặc hậu duệ của run hiện tại.
     * =====================================================================
     * INPUT: import session.
     * OUTPUT: quan hệ candidate AiImport.
     * SIDE EFFECT: Không ghi database hoặc gọi provider.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function candidates(): HasMany
    {
        return $this->hasMany(self::class, 'session_id', 'session_id')->where($this->getKeyName(), '!=', $this->getKey());
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả checkpoint kỹ thuật theo đúng thứ tự tạo
     * =====================================================================
     * INPUT: UUID import hiện tại.
     * OUTPUT: quan hệ HasMany AiImportStep.
     * SIDE EFFECT: chỉ tạo truy vấn, không gọi provider hoặc ghi Post.
     * =====================================================================
     */
    public function steps(): HasMany
    {
        return $this->hasMany(AiImportStep::class)->orderBy('id');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Ghi trạng thái tiến trình để polling nhất quán.
     * =====================================================================
     * INPUT: step/progress pipeline.
     * OUTPUT: persisted polling state.
     * SIDE EFFECT: ghi lifecycle AiImport; không gọi provider.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function advance(string $step, int $progress): void
    {
        if ($this->exists && $this->isCancelled()) {
            return;
        }
        $updates = [
            'status' => $step,
            'current_step' => $step,
            'progress' => max(0, min(100, $progress)),
            'started_at' => $this->started_at ?? now(),
        ];
        if ($this->exists) {
            // Điều kiện trên DB giữ cancellation ngay cả khi request hủy đến sau lần đọc.
            static::query()->whereKey($this->getKey())->whereNotIn('status', self::TERMINAL_STATUSES)->update($updates);
            $this->refresh();
        } else {
            $this->forceFill($updates);
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra run đã được yêu cầu hủy hay chưa.
     * =====================================================================
     * INPUT: model identity hiện tại.
     * OUTPUT: boolean cancellation state.
     * SIDE EFFECT: Chỉ đọc thuộc tính model.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function isCancelled(): bool
    {
        return $this->fresh()?->status === 'cancelled';
    }
}
