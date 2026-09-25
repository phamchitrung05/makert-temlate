<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AdminForgotPasswordRequest;
use App\Http\Requests\Auth\AdminLoginRequest;
use App\Http\Requests\Auth\AdminResetPasswordRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Điều phối session authentication cho admin
 * =====================================================================
 *
 * Controller này chỉ dùng admin guard và users provider. Customer OAuth,
 * customer session và customer identity được xử lý bởi CustomerOAuthController.
 * Các mutation session đều chạy qua web middleware để có CSRF protection.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - login(): xác thực admin active bằng email/password và regenerate session
 * - logout(): đăng xuất admin, invalidate session và regenerate CSRF token
 * - me(): trả thông tin admin hiện tại
 * - forgotPassword(): gửi reset link qua users password broker
 * - resetPassword(): đổi password, login lại và invalidate token cũ
 * =====================================================================
 */
class AdminSessionController extends Controller
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Đăng nhập admin bằng email/password
     * =====================================================================
     *
     * INPUT:
     * - AdminLoginRequest: email, password, remember
     *
     * OUTPUT:
     * - JSON 200 gồm admin data khi credential hợp lệ
     * - ValidationException 422 khi credential sai hoặc admin không active
     *
     * SIDE EFFECT:
     * - Tạo admin session, regenerate session id, cập nhật last_login_at và activity log
     * - Route caller chịu trách nhiệm throttle login
     * =====================================================================
     */
    public function login(AdminLoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();
        $remember = (bool) ($credentials['remember'] ?? false);

        $authenticated = Auth::guard('admin')->attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
            'status' => 'active',
        ], $remember);

        if (! $authenticated) {
            throw ValidationException::withMessages([
                'email' => 'Thông tin đăng nhập không hợp lệ.',
            ]);
        }

        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::guard('admin')->user();
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        activity()
            ->causedBy($user)
            ->performedOn($user)
            ->event('admin_logged_in')
            ->useLog('auth')
            ->log('Admin logged in.');

        return response()->json([
            'user' => $user->only(['id', 'name', 'email', 'username', 'status']),
        ]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đăng xuất admin
     * =====================================================================
     *
     * INPUT:
     * - Request hiện tại có admin session
     *
     * OUTPUT:
     * - JSON 204 khi session đã bị hủy
     *
     * SIDE EFFECT:
     * - Logout admin guard, invalidate session và regenerate CSRF token
     * =====================================================================
     */
    public function logout(Request $request): JsonResponse
    {
        $admin = Auth::guard('admin')->user();

        if ($admin) {
            activity()
                ->causedBy($admin)
                ->performedOn($admin)
                ->event('admin_logged_out')
                ->useLog('auth')
                ->log('Admin logged out.');
        }

        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(status: 204);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả admin đang đăng nhập
     * =====================================================================
     *
     * INPUT:
     * - Request đã qua auth:admin middleware
     *
     * OUTPUT:
     * - JSON 200 gồm profile tối thiểu của admin
     * =====================================================================
     */
    public function me(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::guard('admin')->user();

        return response()->json([
            'user' => $user->only(['id', 'name', 'email', 'username', 'status']),
            'roles' => $user->getRoleNames()->values(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
        ]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Gửi email reset password cho admin
     * =====================================================================
     *
     * INPUT:
     * - AdminForgotPasswordRequest: email admin
     *
     * OUTPUT:
     * - JSON 200 nếu broker tạo reset link thành công
     * - ValidationException 422 nếu broker từ chối
     *
     * SIDE EFFECT:
     * - Gửi notification reset password qua users provider
     * =====================================================================
     */
    public function forgotPassword(AdminForgotPasswordRequest $request): JsonResponse
    {
        $status = Password::broker('users')->sendResetLink($request->validated());

        if ($status !== Password::RESET_LINK_SENT) {
            throw ValidationException::withMessages([
                'email' => __($status),
            ]);
        }

        return response()->json(['message' => __($status)]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đặt password mới và đăng nhập admin
     * =====================================================================
     *
     * INPUT:
     * - AdminResetPasswordRequest: token, email, password, password_confirmation
     *
     * OUTPUT:
     * - JSON 200 khi reset thành công
     * - ValidationException 422 nếu token hoặc password không hợp lệ
     *
     * SIDE EFFECT:
     * - Password Broker xóa token, cập nhật password, remember token và tạo session mới
     * =====================================================================
     */
    public function resetPassword(AdminResetPasswordRequest $request): JsonResponse
    {
        $status = Password::broker('users')->reset(
            $request->validated(),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                    'status' => 'active',
                ])->saveQuietly();
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => __($status),
            ]);
        }

        $user = User::query()->where('email', $request->string('email'))->firstOrFail();
        Auth::guard('admin')->login($user);
        $request->session()->regenerate();

        return response()->json(['message' => __($status)]);
    }
}
