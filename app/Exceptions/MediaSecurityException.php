<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Biểu diễn file vi phạm policy bảo mật Media Library
 * =====================================================================
 *
 * Exception này dùng cho filename, MIME, archive traversal, symlink và giới
 * hạn archive. Đây là lỗi do input nên action có thể trả về validation error;
 * job scan phân biệt exception này với lỗi kỹ thuật để không retry vô hạn.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): tạo lỗi với message an toàn cho nội bộ
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : message policy không chứa path nội bộ hoặc secret
 * - OUTPUT: RuntimeException dùng tại validator/scanner/job
 * =====================================================================
 */
class MediaSecurityException extends RuntimeException {}
