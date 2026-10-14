<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Biểu diễn package template không đạt contract xử lý
 * =====================================================================
 *
 * Exception này dùng cho lỗi input blocking đã được parser/manifest validator
 * xác định. Queue worker không retry loại lỗi này; package vẫn giữ nguyên để
 * admin sửa hoặc upload lại, còn thông báo public chỉ dùng error code bounded.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): tạo lỗi input với error code an toàn.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : error code và message nội bộ không chứa secret.
 * - OUTPUT: RuntimeException để phân biệt lỗi input với lỗi vận hành retryable.
 * - SIDE EFFECT: Không ghi database, file hoặc queue.
 * =====================================================================
 */
final class TemplatePackageInputException extends RuntimeException
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Khởi tạo lỗi package input không hợp lệ
     * =====================================================================
     * INPUT: error code và message public-safe.
     * OUTPUT: exception có code được caller dùng để ghi lifecycle.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
