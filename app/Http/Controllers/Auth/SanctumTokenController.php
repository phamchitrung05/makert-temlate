<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SanctumTokenRequest;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Issue và revoke Laravel Sanctum personal access token
 * =====================================================================
 *
 * Controller này là boundary duy nhất cho token exchange/revoke. Plain text
 * token chỉ trả trong response tạo token; database chỉ lưu token hash do
 * Sanctum quản lý. Client không được tự gửi abilities.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - issueCustomerToken(): đổi customer session sau OAuth thành Bearer token
 * - revokeCurrentToken(): xóa token đang dùng trong Authorization header
 * - revokeAllTokens(): xóa toàn bộ token của account hiện tại
 * =====================================================================
 */
class SanctumTokenController extends Controller
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Issue token cho customer đã có customer session
     * =====================================================================
     *
     * INPUT:
     * - SanctumTokenRequest: device_name optional
     * - customer session từ auth:customer middleware
     *
     * OUTPUT:
     * - JSON 201 gồm accessToken plain text, tokenType và expiresAt
     *
     * SIDE EFFECT:
     * - INSERT personal_access_tokens với abilities `customer`
     * =====================================================================
     */
    public function issueCustomerToken(SanctumTokenRequest $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = Auth::guard('customer')->user();
        $deviceName = $request->string('device_name', 'customer-web')->toString();
        $expiresAt = now()->addDays((int) config('sanctum.token_expiration_days', 30));
        $token = $customer->createToken($deviceName, ['customer'], $expiresAt);

        return response()->json([
            'accessToken' => $token->plainTextToken,
            'tokenType' => 'Bearer',
            'expiresAt' => $expiresAt->toIso8601String(),
        ], 201);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Revoke Sanctum token đang dùng
     * =====================================================================
     *
     * INPUT:
     * - Request authenticated bởi auth:sanctum
     *
     * OUTPUT:
     * - HTTP 204 sau khi token bị xóa
     *
     * SIDE EFFECT:
     * - DELETE current personal_access_tokens row
     * =====================================================================
     */
    public function revokeCurrentToken(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();
        $token?->delete();

        return response()->json(status: 204);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Revoke toàn bộ token của account hiện tại
     * =====================================================================
     *
     * INPUT:
     * - Request authenticated bởi auth:sanctum
     *
     * OUTPUT:
     * - HTTP 204 sau khi toàn bộ token bị xóa
     *
     * SIDE EFFECT:
     * - DELETE mọi personal_access_tokens của tokenable hiện tại
     * =====================================================================
     */
    public function revokeAllTokens(Request $request): JsonResponse
    {
        $request->user()?->tokens()->delete();

        return response()->json(status: 204);
    }
}
