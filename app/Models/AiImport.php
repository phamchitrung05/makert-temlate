<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Lưu trạng thái và kết quả một lần import bài viết bằng AI.
 * =====================================================================
 *
 * Model phục vụ polling và danh sách Content AI, giữ nguồn/lifecycle/candidate cùng số generation. Checkpoint v1 tạm được service quản lý và hidden khỏi serialize API; chỉ bản approved có kho lâu dài riêng.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - casts().
 * - createdBy().
 * - candidates().
 * - steps().
 * - advance().
 * - isCancelled().
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Dữ liệu run, input/result JSON, thời hạn và actor.
 * - OUTPUT: AiImport cùng các quan hệ owner/candidate/step và trạng thái tiến trình.
 * - SIDE EFFECT: Eloquent đọc/ghi lifecycle; advance() không ghi đè trạng thái terminal.
 * - EXCEPTION/TRANSACTION: Mutation nghiệp vụ/lock thuộc controller, worker hoặc service; các relation/cast không tự mở transaction.
 * =====================================================================
 */
class AiImport extends Model
{
    use HasUuids;

    public const RUNNING_STATUSES = ['queued', 'fetching', 'extracting', 'rewriting', 'analyzing', 'planning', 'writing', 'editing', 'validating', 'seo', 'thumbnail'];

    public const TERMINAL_STATUSES = ['ready', 'failed', 'cancelled', 'expired'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $attributes = ['generation_no' => 1];

    protected $hidden = ['archive_pending_json'];

    protected $fillable = [
        'created_by', 'source_url', 'source_text', 'normalized_url', 'source_hash', 'status',
        'current_step', 'progress', 'input_json', 'source_meta_json', 'result_json',
        'error_code', 'error_message', 'provider', 'prompt_version', 'started_at',
        'completed_at', 'expires_at',
        'session_id', 'parent_id', 'operation', 'applied_target_id', 'applied_fields',
        'archive_version', 'generation_no',
    ];

    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo cast cho state/result/input của AI run.
     * =====================================================================
     * INPUT: không có.
     * OUTPUT: map cast JSON/integer/datetime của model.
     * SIDE EFFECT: Không ghi database hoặc gọi provider.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    protected function casts(): array
    {
        return [
            'input_json' => 'array',
            'archive_pending_json' => 'array',
            'archive_version' => 'integer',
            'generation_no' => 'integer',
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
     * CHỨC NĂNG: Trả quan hệ checkpoint kỹ thuật của run theo thứ tự tạo
     * =====================================================================
     *
     * INPUT:
     * - UUID AiImport hiện tại.
     *
     * OUTPUT:
     * - HasMany AiImportStep, sắp tăng dần theo id, chưa paginate hoặc eager load.
     *
     * SIDE EFFECT:
     * - Chỉ dựng query quan hệ; DB chỉ được đọc khi caller thực thi query, không gọi AI hoặc ghi Post.
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction/lock; caller chịu trách nhiệm quyền xem run và xử lý lỗi query.
     *
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
