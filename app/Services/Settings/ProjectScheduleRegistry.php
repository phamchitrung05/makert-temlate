<?php

namespace App\Services\Settings;

use Illuminate\Console\Scheduling\Schedule;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Đăng ký các lịch command được project hỗ trợ.
 * =====================================================================
 * Dùng chung cho console và endpoint diagnostics; chỉ đăng ký command cố
 * định, idempotent và không nhận command tùy ý từ HTTP.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - register(): thêm các task vào Schedule nếu chưa tồn tại.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Illuminate Console Schedule.
 * - OUTPUT: Schedule đã đăng ký; không query DB hoặc chạy command ngay.
 * =====================================================================
 */
final class ProjectScheduleRegistry
{
    /**
     * Đăng ký lịch cleanup/sync đã allowlist.
     *
     * Input: Schedule cần bổ sung event.
     * Output: void; mutate Schedule trong memory theo cách idempotent.
     */
    public function register(Schedule $schedule): void
    {
        $tasks = [
            ['command' => 'ai-import:cleanup', 'title' => 'Dọn tác vụ AI Content hết hạn', 'frequency' => 'daily', 'overlap' => 30],
            ['command' => 'media:prune-deleted', 'title' => 'Dọn file media đã xóa mềm quá 30 ngày', 'frequency' => 'weekly', 'overlap' => 60],
            ['command' => 'ai:cleanup-writing-profile-analyses', 'title' => 'Dọn phân tích văn phong hết hạn', 'frequency' => 'daily', 'overlap' => 30],
            ['command' => 'resource-templates:diagnose', 'title' => 'Chẩn đoán template processing', 'frequency' => 'daily', 'overlap' => 30],
            ['command' => 'marketing:social-post-drafts', 'title' => 'Tạo Social Post draft hằng ngày', 'frequency' => 'daily', 'overlap' => 30],
            ['command' => 'marketing:publish-scheduled-posts', 'title' => 'Đăng bài Social đã duyệt đến giờ hẹn', 'frequency' => 'everyFiveMinutes', 'overlap' => 10],
        ];
        if (config('ai.providers.sync_enabled', false)) {
            $tasks[] = ['command' => 'ai-providers:sync-models', 'title' => 'Đồng bộ danh sách model AI', 'frequency' => 'hourly', 'overlap' => 60];
        }
        foreach ($tasks as $task) {
            if (collect($schedule->events())->contains(fn ($event) => str_contains($event->command ?? '', $task['command']))) {
                continue;
            }
            $event = $schedule->command($task['command'])->name($task['title'])->withoutOverlapping($task['overlap']);
            $event->{$task['frequency']}();
        }

        // Batch sáng Chủ Nhật 07:00: tạo trọn bộ slot tuần tới (chỉ khi campaign active).
        $weeklyEnabled = (bool) (app(ProjectSettingsService::class)->effective('marketing_schedule')['sunday_batch_enabled'] ?? true);
        if ($weeklyEnabled && ! collect($schedule->events())->contains(fn ($event) => str_contains($event->command ?? '', 'marketing:social-post-drafts --weekly-batch'))) {
            $time = (string) (app(ProjectSettingsService::class)->effective('marketing_schedule')['sunday_batch_time'] ?? '07:00');
            $schedule->command('marketing:social-post-drafts --weekly-batch')
                ->name('Batch sáng Chủ Nhật tạo bài tuần tới')
                ->weeklyOn(0, $time)
                ->withoutOverlapping(60);
        }
    }
}
