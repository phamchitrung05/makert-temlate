<?php

namespace App\Services\Settings;

use Illuminate\Console\Scheduling\Schedule;

/**
 * Nguồn khai báo lịch dùng chung console/web; không query DB hay chạy command.
 * Input: Schedule. Output: registry thật, đăng ký idempotent để HTTP không rỗng.
 */
final class ProjectScheduleRegistry
{
    /** Tạo lịch đã được project hỗ trợ; không nhận command tùy ý từ Settings. */
    public function register(Schedule $schedule): void
    {
        $tasks = [
            ['command' => 'ai-import:cleanup', 'title' => 'Dọn tác vụ AI Content hết hạn', 'frequency' => 'daily', 'overlap' => 30],
            ['command' => 'ai:cleanup-writing-profile-analyses', 'title' => 'Dọn phân tích văn phong hết hạn', 'frequency' => 'daily', 'overlap' => 30],
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
