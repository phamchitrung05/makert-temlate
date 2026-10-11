<?php

namespace App\Services\Settings;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Đọc diagnostics scheduler, runtime, database và disk.
 * =====================================================================
 * Chỉ probe trạng thái thật, không chạy command, không sửa dữ liệu và không
 * trả đường dẫn/credential nhạy cảm ra HTTP.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nhận registry lịch dùng chung.
 * - cron(): trả event scheduler đã đăng ký.
 * - system(): trả trạng thái runtime, DB, queue và disk.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Schedule và runtime Laravel.
 * - OUTPUT: array diagnostics read-only; lỗi probe thành trạng thái unavailable.
 * =====================================================================
 */
final class SettingsDiagnosticsService
{
    /**
     * Khởi tạo service với registry lịch.
     *
     * Input: ProjectScheduleRegistry.
     * Output: instance diagnostics; không có side effect.
     */
    public function __construct(private ProjectScheduleRegistry $registry) {}

    /**
     * Trả danh sách event scheduler đã được đăng ký.
     *
     * Input: Schedule hiện tại.
     * Output: array event và health metadata; chỉ mutate Schedule trong memory.
     */
    public function cron(Schedule $schedule): array
    {
        $this->registry->register($schedule);

        return [
            'items' => array_map(fn ($event) => [
                'name' => $event->description ?: preg_replace('/^.*artisan[\x27\x22]?\s+/', '', $event->command ?? 'Callback'),
                'command' => preg_replace('/^.*artisan[\x27\x22]?\s+/', '', $event->command ?? 'Callback'),
                'expression' => $event->expression,
                'timezone' => $event->timezone ?: config('app.timezone'),
                'next_run_at' => $event->nextRunDate()->format(DATE_ATOM),
                'without_overlapping' => $event->withoutOverlapping,
            ], $schedule->events()),
            'history_available' => false,
            'scheduler_health' => 'unknown',
        ];
    }

    /**
     * Probe phiên bản runtime, database, queue và dung lượng disk.
     *
     * Input: không có tham số; đọc config/runtime hiện tại.
     * Output: array diagnostics; bắt Throwable và không làm lộ lỗi kết nối.
     */
    public function system(): array
    {
        $db = ['driver' => config('database.connections.'.config('database.default').'.driver'), 'status' => 'unavailable', 'version' => null];
        $queue = ['connection' => config('queue.default'), 'pending' => null, 'failed' => null, 'worker_health' => 'unknown'];
        try {
            DB::select('select 1');
            $db['status'] = 'connected';
            $db['version'] = match ($db['driver']) {
                'sqlite' => DB::selectOne('select sqlite_version() as version')->version,
                'mysql', 'mariadb' => DB::selectOne('select version() as version')->version,
                'pgsql' => DB::selectOne('show server_version')->server_version,
                default => null,
            };
            if ($queue['connection'] === 'database' && Schema::hasTable(config('queue.connections.database.table', 'jobs'))) {
                $queue['pending'] = DB::table(config('queue.connections.database.table', 'jobs'))->count();
            }
            if (config('queue.failed.driver') === 'database-uuids' && Schema::hasTable(config('queue.failed.table', 'failed_jobs'))) {
                $queue['failed'] = DB::table(config('queue.failed.table', 'failed_jobs'))->count();
            }
        } catch (Throwable) {
            // Không đưa exception, thông tin kết nối hoặc credential ra HTTP.
        }
        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());

        return [
            'checked_at' => now()->toIso8601String(),
            'runtime' => [
                'php' => PHP_VERSION, 'laravel' => app()->version(),
                'os' => PHP_OS_FAMILY, 'memory_limit' => ini_get('memory_limit'),
                'upload_max_filesize' => ini_get('upload_max_filesize'), 'post_max_size' => ini_get('post_max_size'),
                'environment' => app()->environment(), 'debug' => (bool) config('app.debug'),
            ],
            'database' => $db, 'queue' => $queue,
            'disk' => ['free_bytes' => $free === false ? null : $free, 'total_bytes' => $total === false ? null : $total],
        ];
    }
}
