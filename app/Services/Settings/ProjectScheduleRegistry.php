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
            ['command' => 'ai:cleanup-writing-profile-analyses', 'title' => 'Dọn phân tích văn phong hết hạn', 'frequency' => 'daily', 'overlap' => 30],
            ['command' => 'resource-templates:diagnose', 'title' => 'Chẩn đoán template processing', 'frequency' => 'daily', 'overlap' => 30],
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
    }
}
