<?php

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Khởi tạo Laravel application và chuẩn hóa exception API
 * =====================================================================
 *
 * File đăng ký route, middleware alias và quy tắc render exception. Các request
 * API hoặc request yêu cầu JSON dùng BaseResponse để giữ cùng envelope lỗi;
 * request web vẫn được Laravel xử lý theo cơ chế mặc định.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - withSchedule(): dọn run/checkpoint/phân tích hết hạn và đồng bộ model tùy chọn
 * - withMiddleware(): đăng ký middleware alias và quy tắc redirect guest
 * - withExceptions(): chọn JSON response và chuyển exception API qua BaseResponse
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : cấu hình môi trường, request HTTP và exception trong vòng đời app
 * - OUTPUT: Application đã cấu hình route, middleware và exception renderer
 * =====================================================================
 */

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Responses\BaseResponse;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        // =====================================================================
        // INPUT: scheduler Laravel. OUTPUT: cleanup định kỳ, khóa chống chạy trùng.
        // =====================================================================
        $schedule->command('ai-import:cleanup')->daily()->withoutOverlapping(30);
        $schedule->command('ai:cleanup-writing-profile-analyses')->daily()->withoutOverlapping(30);
        if (config('ai-providers.sync_enabled', false)) {
            $schedule->command('ai-providers:sync-models')->hourly()->withoutOverlapping(60);
        }
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'account.active' => EnsureAccountIsActive::class,
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
        $middleware->redirectGuestsTo(
            fn (Request $request): ?string => $request->is('api/*') ? null : '/admin/login',
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (\Throwable $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return BaseResponse::fromException($exception);
        });
    })->create();
