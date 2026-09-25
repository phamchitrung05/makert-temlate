<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Chặn account đã bị suspended khỏi protected routes
 * =====================================================================
 *
 * Middleware nhận guard qua parameter, kiểm tra model đang đăng nhập có
 * method isActive() và từ chối request nếu trạng thái không còn active.
 * Authentication middleware phải chạy trước middleware này.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - handle(): đọc guard, kiểm tra account và chuyển request tiếp tục
 * =====================================================================
 */
class EnsureAccountIsActive
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Chỉ cho account active đi qua protected route
     * =====================================================================
     *
     * INPUT:
     * - $request: HTTP request đã authenticated
     * - $next: middleware kế tiếp
     * - $guard: admin hoặc customer
     *
     * OUTPUT:
     * - Response kế tiếp khi account active
     * - HTTP 403 khi account bị suspended/inactive
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction; abort 403 khi trạng thái không hợp lệ
     * =====================================================================
     */
    public function handle(Request $request, Closure $next, string $guard): Response
    {
        $account = $guard === 'sanctum'
            ? $request->user()
            : Auth::guard($guard)->user();

        abort_unless($account && method_exists($account, 'isActive') && $account->isActive(), 403);

        return $next($request);
    }
}
