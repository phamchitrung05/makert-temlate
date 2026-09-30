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
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : dữ liệu import và kết quả pipeline.
 * - OUTPUT: bản ghi AiImport cùng quan hệ người tạo.
 * =====================================================================
 */

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiImport extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['created_by', 'source_url', 'status', 'result_json', 'error_message', 'provider', 'prompt_version', 'expires_at'];

    /** Input: không có. Output: danh sách cast thuộc tính model. */
    protected function casts(): array
    {
        return ['result_json' => 'array', 'expires_at' => 'datetime'];
    }

    /** Input: không có. Output: quan hệ User tạo import. */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
