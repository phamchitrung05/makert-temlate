<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Phân biệt lỗi lưu lịch sử với lỗi tạo nội dung AI.
 * =====================================================================
 *
 * Boundary kho/checkpoint dùng exception riêng để lỗi bảo toàn dữ liệu không bị coi là lỗi provider. Thông báo không mang SQL, payload hoặc credential.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct().
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Mã reason nội bộ.
 * - OUTPUT: Exception an toàn cho worker, thao tác duyệt và cleanup.
 * - SIDE EFFECT: Chỉ tạo exception; không I/O hoặc log dữ liệu riêng.
 * - EXCEPTION/TRANSACTION: Không mở transaction; caller giữ checkpoint/rollback theo boundary nghiệp vụ.
 * =====================================================================
 */
class AiArticleArchiveException extends RuntimeException
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo lỗi bảo toàn bản gốc AI không chứa dữ liệu riêng tư
     * =====================================================================
     *
     * INPUT:
     * - Mã reason nội bộ, mặc định AI_ARCHIVE_WRITE_FAILED.
     *
     * OUTPUT:
     * - Exception có reason và thông báo an toàn cho boundary xử lý.
     *
     * SIDE EFFECT:
     * - Chỉ khởi tạo exception; không log SQL/payload hoặc giữ previous exception.
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction; caller quyết định retry hoặc rollback và giữ checkpoint.
     *
     * =====================================================================
     */
    public function __construct(public readonly string $reason = 'AI_ARCHIVE_WRITE_FAILED')
    {
        parent::__construct('Chưa thể bảo toàn bản gốc AI. Dữ liệu run được giữ để phục hồi.');
    }
}
