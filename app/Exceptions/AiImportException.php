<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Lỗi miền của pipeline AI import và chính sách retry.
 * =====================================================================
 *
 * Exception giữ mã lỗi an toàn để lưu vào ai_imports và cờ retryable để job
 * phân biệt lỗi tạm thời (provider/HTTP) với lỗi input hoặc schema cố định.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): giữ message, mã lỗi, cờ retry và exception nguyên nhân.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : message, error code, retryable và previous throwable.
 * - OUTPUT: RuntimeException có metadata để job quyết định retry.
 * - SIDE EFFECT: không ghi database; caller quyết định cập nhật lifecycle.
 */
class AiImportException extends RuntimeException
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Khởi tạo lỗi AI có mã và chính sách retry
     * =====================================================================
     * INPUT:
     * - message: thông báo an toàn cho admin.
     * - errorCode/retryable: metadata để queue job phân loại lỗi.
     * - previous: exception gốc nếu có.
     * OUTPUT:
     * - Không trả giá trị; tạo RuntimeException có code nghiệp vụ.
     * SIDE EFFECT:
     * - Không ghi database hoặc gọi provider.
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction; exception được ném cho caller.
     * =====================================================================
     */
    public function __construct(
        string $message,
        public readonly string $errorCode = 'AI_IMPORT_FAILED',
        public readonly bool $retryable = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
