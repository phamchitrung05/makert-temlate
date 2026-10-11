<?php

namespace App\Services\Settings;

use App\Mail\SettingsTestMail;
use Illuminate\Contracts\Mail\Factory;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Áp dụng cấu hình mail và gửi email kiểm tra.
 * =====================================================================
 * Refresh mail transport từ settings đã lưu tại boundary gửi, không mutate
 * .env; email kiểm tra chỉ được gọi sau khi request đã authorize.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nhận settings service và mail factory.
 * - configure(): nạp cấu hình mail runtime và quên mailer cũ.
 * - sendTest(): gửi SettingsTestMail tới recipient.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : ProjectSettingsService, mail Factory và recipient.
 * - OUTPUT: config runtime; sendTest trả driver và có side effect gửi mail.
 * =====================================================================
 */
final class ProjectMailService
{
    /**
     * Khởi tạo service với nguồn settings và mail factory.
     *
     * Input: ProjectSettingsService và Illuminate mail Factory.
     * Output: instance sẵn sàng configure/sendTest; không ghi database.
     */
    public function __construct(private ProjectSettingsService $settings, private Factory $mail) {}

    /**
     * Nạp settings mail vào config runtime và reset mailer cache.
     *
     * Input: settings mail đã lưu.
     * Output: array settings hiệu lực; thay đổi config runtime, không đổi .env.
     */
    public function configure(): array
    {
        $values = $this->settings->effective('mail');
        config()->set('mail.default', $values['mailer']);
        config()->set('mail.from', ['name' => $values['from_name'], 'address' => $values['from_address']]);
        config()->set('mail.mailers.smtp', array_replace(config('mail.mailers.smtp'), [
            'host' => $values['host'], 'port' => $values['port'], 'scheme' => $values['scheme'],
            'username' => $values['username'], 'password' => $values['password'],
            'url' => null, 'timeout' => 10,
        ]));
        $this->mail->forgetMailers();

        return $values;
    }

    /**
     * Gửi email kiểm tra theo mailer hiện tại.
     *
     * Input: recipient email đã validate.
     * Output: tên driver; gửi mail và không trả password/credential.
     */
    public function sendTest(string $recipient): string
    {
        $values = $this->configure();
        $this->mail->to($recipient)->send(new SettingsTestMail);

        return $values['mailer'];
    }
}
