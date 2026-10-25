<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Settings\ProjectSettingsRequest;
use App\Http\Requests\Admin\Settings\SettingsTestMailRequest;
use App\Http\Responses\BaseResponse;
use App\Services\Settings\ProjectMailService;
use App\Services\Settings\ProjectSettingsService;
use App\Services\Settings\SettingsDiagnosticsService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Điều phối các endpoint cấu hình quản trị.
 * =====================================================================
 * Controller gom payload theo nhóm, kiểm quyền qua route/request và ủy
 * quyền ghi hoặc chẩn đoán cho các service chuyên biệt.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - index(): trả nhóm cấu hình và option được phép dùng.
 * - update(): cập nhật một nhóm cấu hình.
 * - preferences(): trả locale và ngôn ngữ an toàn.
 * - cron(): trả lịch đã đăng ký.
 * - webhooks(): trả capability webhook thực tế.
 * - system(): trả chẩn đoán runtime read-only.
 * - testMail(): gửi email thử theo cấu hình đã lưu.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : HTTP request admin, payload settings và các service cấu hình.
 * - OUTPUT: JsonResponse chuẩn BaseResponse; update/testMail có side effect.
 * =====================================================================
 */
final class SettingsController extends Controller
{
    /** Trả sáu nhóm cấu hình/option; không trả password hay dữ liệu provider AI.
     * Input: tham số theo chữ ký `index()` và state đã hydrate của class.
     * Output: giá trị theo kiểu trả về của `index()`; side effect/exception giữ theo implementation.
     */
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

    /** Ghi từng group trong transaction; version stale trả 409.
     * Input: tham số theo chữ ký `update()` và state đã hydrate của class.
     * Output: giá trị theo kiểu trả về của `update()`; side effect/exception giữ theo implementation.
     */
    public function update(ProjectSettingsRequest $request, string $group, ProjectSettingsService $settings): JsonResponse
    {
        return BaseResponse::success($settings->update($group, $request->validated(), $request->user()), 'Đã lưu cài đặt.');
    }

    /** Chỉ locale/preferences an toàn cho mọi admin; không cần quyền Settings.
     * Input: tham số theo chữ ký `preferences()` và state đã hydrate của class.
     * Output: giá trị theo kiểu trả về của `preferences()`; side effect/exception giữ theo implementation.
     */
    public function preferences(ProjectSettingsService $settings): JsonResponse
    {
        return BaseResponse::success([
            'languages' => $settings->section('languages')['values'], 'locales' => $settings->locales(),
        ]);
    }

    /** Đọc lịch đã đăng ký; không có thao tác chạy thủ công hoặc sửa command.
     * Input: tham số theo chữ ký `cron()` và state đã hydrate của class.
     * Output: giá trị theo kiểu trả về của `cron()`; side effect/exception giữ theo implementation.
     */
    public function cron(SettingsDiagnosticsService $diagnostics, Schedule $schedule): JsonResponse
    {
        return BaseResponse::success($diagnostics->cron($schedule));
    }

    /** Webhooks chưa có delivery/subscriber trong project; capability rõ ràng, không fixture.
     * Input: tham số theo chữ ký `webhooks()` và state đã hydrate của class.
     * Output: giá trị theo kiểu trả về của `webhooks()`; side effect/exception giữ theo implementation.
     */
    public function webhooks(): JsonResponse
    {
        return BaseResponse::success(['supported' => false, 'items' => [], 'reason' => 'Project chưa có bộ gửi webhook và sự kiện đăng ký.']);
    }

    /** Đọc diagnostics thực; không thay .env, cache hoặc phiên bản runtime.
     * Input: tham số theo chữ ký `system()` và state đã hydrate của class.
     * Output: giá trị theo kiểu trả về của `system()`; side effect/exception giữ theo implementation.
     */
    public function system(SettingsDiagnosticsService $diagnostics): JsonResponse
    {
        return BaseResponse::success($diagnostics->system());
    }

    /** Chỉ gửi cấu hình đã lưu; thông báo log/array không được diễn đạt là giao thư thành công.
     * Input: tham số theo chữ ký `testMail()` và state đã hydrate của class.
     * Output: giá trị theo kiểu trả về của `testMail()`; side effect/exception giữ theo implementation.
     */
    public function testMail(SettingsTestMailRequest $request, ProjectMailService $mail): JsonResponse
    {
        $driver = $mail->sendTest($request->validated('recipient'));
        activity('settings')->causedBy($request->user())->withProperties(['group' => 'mail', 'mailer' => $driver])->log('mail.tested');

        return BaseResponse::success(['mailer' => $driver], in_array($driver, ['log', 'array'], true)
            ? 'Đã tạo email thử. Chế độ hiện tại không gửi ra hộp thư.'
            : 'Máy chủ gửi đã tiếp nhận email thử.');
    }
}
