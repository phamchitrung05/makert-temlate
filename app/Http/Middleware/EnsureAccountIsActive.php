<?php

namespace App\Http\Middleware;

use App\Models\User;
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
 * Boundary admin yêu cầu thêm model User, không chỉ tin ability của token.
 * Authentication middleware phải chạy trước middleware này.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - handle(): đọc guard, kiểm tra account và chuyển request tiếp tục
 * =====================================================================
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : dữ liệu theo contract và method chính của file.
 * - OUTPUT: kết quả public của file; side effect/exception theo implementation.
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
     * - $guard: sanctum hoặc customer
     * - $accountType: admin nếu route yêu cầu model User quản trị
     *
     * OUTPUT:
     * - Response kế tiếp khi account active
     * - HTTP 403 khi account bị suspended/inactive
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction; abort 403 khi trạng thái không hợp lệ
     * =====================================================================
     */
    public function handle(Request $request, Closure $next, string $guard, string $accountType = ''): Response
    {
        $account = $guard === 'sanctum'
            ? $request->user()
            : Auth::guard($guard)->user();

        abort_unless($account && method_exists($account, 'isActive') && $account->isActive()
            && ($accountType !== 'admin' || $account instanceof User), 403);

        return $next($request);
    }
}
