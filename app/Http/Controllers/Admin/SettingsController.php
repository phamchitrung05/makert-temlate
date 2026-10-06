<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProjectSettingsRequest;
use App\Http\Requests\Admin\SettingsTestMailRequest;
use App\Http\Responses\BaseResponse;
use App\Services\Settings\ProjectMailService;
use App\Services\Settings\ProjectSettingsService;
use App\Services\Settings\SettingsDiagnosticsService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Điều phối HTTP Settings, quyền được route và request bảo vệ.
 * Input: admin/payload. Output: BaseResponse DTO; writes thuộc service.
 */
final class SettingsController extends Controller
{
    /** Trả sáu nhóm cấu hình/option; không trả password hay dữ liệu provider AI. */
    public function index(Request $request, ProjectSettingsService $settings): JsonResponse
    {
        $sections = [];
        foreach (array_keys(ProjectSettingsService::CLASSES) as $group) {
            $sections[$group] = $settings->section($group);
        }

        return BaseResponse::success([
            'sections' => $sections,
            'can_manage' => $request->user()->can('settings.manage'),
            'options' => [
                'timezones' => \DateTimeZone::listIdentifiers(),
                'locales' => $settings->locales(),
                'extensions' => $settings->defaults('media')['allowed_extensions'],
                'mailers' => array_keys(config('mail.mailers')),
                'media_disks' => config('media-library.asset_disks'),
                'media_max_size_kb' => (int) (config('media-library.max_file_size') / 1024),
            ],
        ]);
    }

    /** Ghi từng group trong transaction; version stale trả 409. */
    public function update(ProjectSettingsRequest $request, string $group, ProjectSettingsService $settings): JsonResponse
    {
        return BaseResponse::success($settings->update($group, $request->validated(), $request->user()), 'Đã lưu cài đặt.');
    }

    /** Chỉ locale/preferences an toàn cho mọi admin; không cần quyền Settings. */
    public function preferences(ProjectSettingsService $settings): JsonResponse
    {
        return BaseResponse::success([
            'languages' => $settings->section('languages')['values'], 'locales' => $settings->locales(),
        ]);
    }

    /** Đọc lịch đã đăng ký; không có thao tác chạy thủ công hoặc sửa command. */
    public function cron(SettingsDiagnosticsService $diagnostics, Schedule $schedule): JsonResponse
    {
        return BaseResponse::success($diagnostics->cron($schedule));
    }

    /** Webhooks chưa có delivery/subscriber trong project; capability rõ ràng, không fixture. */
    public function webhooks(): JsonResponse
    {
        return BaseResponse::success(['supported' => false, 'items' => [], 'reason' => 'Project chưa có bộ gửi webhook và sự kiện đăng ký.']);
    }

    /** Đọc diagnostics thực; không thay .env, cache hoặc phiên bản runtime. */
    public function system(SettingsDiagnosticsService $diagnostics): JsonResponse
    {
        return BaseResponse::success($diagnostics->system());
    }

    /** Chỉ gửi cấu hình đã lưu; thông báo log/array không được diễn đạt là giao thư thành công. */
    public function testMail(SettingsTestMailRequest $request, ProjectMailService $mail): JsonResponse
    {
        $driver = $mail->sendTest($request->validated('recipient'));
        activity('settings')->causedBy($request->user())->withProperties(['group' => 'mail', 'mailer' => $driver])->log('mail.tested');

        return BaseResponse::success(['mailer' => $driver], in_array($driver, ['log', 'array'], true)
            ? 'Đã tạo email thử. Chế độ hiện tại không gửi ra hộp thư.'
            : 'Máy chủ gửi đã tiếp nhận email thử.');
    }
}
