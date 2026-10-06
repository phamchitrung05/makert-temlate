<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

/** Mail thử có nội dung cố định; input không có secret, output mail tiếng Việt. */
final class SettingsTestMail extends Mailable
{
    /** Render nội dung an toàn để xác nhận cấu hình gửi đã lưu. */
    public function build(): static
    {
        return $this->subject('Kiểm tra cấu hình Email')
            ->html('<p>Email kiểm tra từ trang Cài đặt. Cấu hình gửi đã được hệ thống sử dụng.</p>');
    }
}
