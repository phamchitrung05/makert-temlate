<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Tạo email kiểm tra cấu hình mail của hệ thống.
 * =====================================================================
 * Nội dung cố định để xác nhận mailer đã được cấu hình; không đưa secret
 * hoặc payload quản trị vào email.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - build(): dựng subject và HTML body của email kiểm tra.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : không có dữ liệu người dùng ngoài recipient do mailer quản lý.
 * - OUTPUT: Mailable đã cấu hình subject/body.
 * =====================================================================
 */
final class SettingsTestMail extends Mailable
{
    /** Render nội dung an toàn để xác nhận cấu hình gửi đã lưu.
     * Input: tham số theo chữ ký `build()` và state đã hydrate của class.
     * Output: giá trị theo kiểu trả về của `build()`; side effect/exception giữ theo implementation.
     */
    public function build(): static
    {
        return $this->subject('Kiểm tra cấu hình Email')
            ->html('<p>Email kiểm tra từ trang Cài đặt. Cấu hình gửi đã được hệ thống sử dụng.</p>');
    }
}
