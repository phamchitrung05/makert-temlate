<?php

namespace App\Models;

use App\Exceptions\AiArticleArchiveException;
use Illuminate\Database\Eloquent\Model;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Lưu snapshot AI gốc bất biến và metadata biên tập riêng.
 * =====================================================================
 *
 * Service kho chỉ tạo row khi Post AI được duyệt/Apply. Snapshot/hash bất biến; metadata biên tập mutable, JSON private và không FK/cascade theo run/Post/user.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - booted().
 * - casts().
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Snapshot/hash đã validate và metadata lifecycle.
 * - OUTPUT: Bản AI được chọn vẫn đọc được khi run hoặc Post bị xóa.
 * - SIDE EFFECT: Eloquent persist dưới service; callback chặn sửa payload/hash.
 * - EXCEPTION/TRANSACTION: Transaction/lock thuộc service/caller; sửa payload ném AiArticleArchiveException.
 * =====================================================================
 */
class AiArticleArchive extends Model
{
    public const SNAPSHOT_FIELDS = [
        'run_id', 'session_id', 'parent_run_id', 'generation_no', 'snapshot_version',
        'created_by', 'target_type', 'operation', 'generation_status', 'content_origin',
        'has_generated_content', 'source_hash', 'content_hash', 'source_snapshot_json',
        'draft_snapshot_json', 'context_snapshot_json', 'diagnostics_json', 'failure_code',
        'generation_started_at', 'generation_completed_at',
    ];

    protected $fillable = [...self::SNAPSHOT_FIELDS, 'payload_hash', 'lifecycle_json', 'applied_target_id'];

    protected $hidden = ['source_snapshot_json', 'draft_snapshot_json', 'context_snapshot_json', 'diagnostics_json'];

    /**
     * =====================================================================
     * CHỨC NĂNG: Chặn sửa payload và hash của kho bài AI đã lưu
     * =====================================================================
     *
     * INPUT:
     * - Sự kiện Eloquent updating của AiArticleArchive.
     *
     * OUTPUT:
     * - void: đăng ký invariant chỉ cho phép đổi lifecycle/liên kết mutable.
     *
     * SIDE EFFECT:
     * - Đăng ký callback kiểm các thuộc tính dirty trước update; không tự ghi DB.
     *
     * EXCEPTION/TRANSACTION:
     * - Ném AI_ARCHIVE_IMMUTABLE khi snapshot/hash bị đổi; transaction/lock thuộc service gọi save().
     *
     * =====================================================================
     */
    protected static function booted(): void
    {
        static::updating(function (self $archive): void {
            if (array_intersect(array_keys($archive->getDirty()), [...self::SNAPSHOT_FIELDS, 'payload_hash']) !== []) {
                throw new AiArticleArchiveException('AI_ARCHIVE_IMMUTABLE');
            }
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo kiểu JSON, ngày giờ và định danh archive
     * =====================================================================
     *
     * INPUT:
     * - Không có tham số; Eloquent đọc cấu hình cast của model.
     *
     * OUTPUT:
     * - Array cast cho JSON private/lifecycle, datetime, boolean và integer.
     *
     * SIDE EFFECT:
     * - Chỉ trả cấu hình; không query, ghi DB hoặc serialize payload private ra API.
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction hoặc gọi provider.
     *
     * =====================================================================
     */
    protected function casts(): array
    {
        return [
            'generation_no' => 'integer', 'snapshot_version' => 'integer', 'created_by' => 'integer',
            'has_generated_content' => 'boolean', 'applied_target_id' => 'integer',
            'source_snapshot_json' => 'array', 'draft_snapshot_json' => 'array',
            'context_snapshot_json' => 'array', 'diagnostics_json' => 'array', 'lifecycle_json' => 'array',
            'generation_started_at' => 'datetime', 'generation_completed_at' => 'datetime',
        ];
    }
}
