<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AdminLoginRequest;
use App\Http\Responses\BaseResponse;
use App\Models\User;
use App\Services\Settings\ProjectSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Cấp Personal Access Token Sanctum cho admin
 * =====================================================================
 *
 * Controller này là boundary stateless cho admin API. Nó xác thực User bằng
 * email/password và trả Sanctum Bearer token, không tạo admin session và
 * không dùng CSRF cookie. Đây là boundary xác thực duy nhất của admin API.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - login(): xác thực admin active và cấp token có ability `admin`
 * - me(): trả profile, roles và permissions của Bearer token hiện tại
 * =====================================================================
 */
class AdminTokenController extends Controller
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Đăng nhập admin qua Sanctum Personal Access Token
     * =====================================================================
     *
     * INPUT:
     * - AdminLoginRequest: email, password và device_name optional
     *
     * OUTPUT:
     * - JSON 200 theo BaseResponse gồm user, roles, permissions, accessToken, tokenType và expiresAt
     * - ValidationException 422 khi credential sai hoặc admin không active
     *
     * SIDE EFFECT:
     * - INSERT personal_access_tokens với ability `admin`; cập nhật last_login_at
     * - Không tạo admin session, không ghi password hoặc plain text token vào log
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction; caller nhận HTTP response hoặc validation exception
     * =====================================================================
     */
    public function login(AdminLoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();
        $user = User::query()
            ->where('email', $credentials['email'])
            ->where('status', 'active')
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => config('messages.auth.admin.login.invalid_credentials'),
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        // Thời hạn mới chỉ áp dụng token được cấp sau khi đổi Settings.
        $expiresAt = now()->addDays((int) app(ProjectSettingsService::class)->effective('security')['token_expiration_days']);
        $token = $user->createToken(
            $credentials['device_name'] ?? 'admin-web',
            ['admin'],
            $expiresAt,
        );

        activity()
            ->causedBy($user)
            ->performedOn($user)
            ->event('admin_api_logged_in')
            ->useLog('auth')
            ->withProperties(['authentication' => 'sanctum'])
            ->log('Admin logged in with Sanctum.');

        return BaseResponse::success([
            'user' => $user->only(['id', 'name', 'email', 'username', 'status']),
            'roles' => $user->getRoleNames()->values(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
            'accessToken' => $token->plainTextToken,
            'tokenType' => 'Bearer',
            'expiresAt' => $expiresAt->toIso8601String(),
        ], config('messages.auth.admin.login.success'));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả profile admin từ Sanctum Bearer token
     * =====================================================================
     *
     * INPUT:
     * - Request đã qua auth:sanctum, abilities:admin và account.active:sanctum
     *
     * OUTPUT:
     * - JSON 200 theo BaseResponse gồm user, roles và permissions của admin hiện tại
     *
     * SIDE EFFECT:
     * - Không có; middleware đã xác thực token và trạng thái account trước controller
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction và không phát sinh exception nghiệp vụ
     * =====================================================================
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return BaseResponse::success([
            'user' => $user->only(['id', 'name', 'email', 'username', 'status']),
            'roles' => $user->getRoleNames()->values(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
        ]);
    }
}
