<?php

namespace App\Services\Settings;

use App\Mail\SettingsTestMail;
use Illuminate\Contracts\Mail\Factory;

/**
 * Áp dụng cấu hình mail đã lưu tại boundary gửi, không mutate .env.
 * Input: Settings; Output: config runtime. sendTest gửi mail thật theo thao tác admin.
 */
final class ProjectMailService
{
    public function __construct(private ProjectSettingsService $settings, private Factory $mail) {}

    /** Refresh transport trước mỗi lần gửi để worker không giữ SMTP cũ. */
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

    /** Input: recipient. Output: driver để UI phân biệt gửi SMTP và ghi log; không trả secret. */
    public function sendTest(string $recipient): string
    {
        $values = $this->configure();
        $this->mail->to($recipient)->send(new SettingsTestMail);

        return $values['mailer'];
    }
}
