<?php

namespace App\Http\Controllers\Admin\Marketing;

use App\Http\Controllers\Controller;
use App\Http\Responses\BaseResponse;
use App\Models\SocialAccount;
use App\Services\Marketing\FacebookOAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: API kết nối/ngắt kết nối Facebook Page accounts.
 * =====================================================================
 * Customer OAuth và Page OAuth dùng route/service riêng; token không trả về
 * frontend và callback chỉ tin state cache server-side.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - index(): list Page metadata.
 * - connect(): trả authorization URL.
 * - callback(): nhận code/state và upsert Pages.
 * - reconnect(): tạo OAuth URL lại.
 * - disconnect(): ngắt một Page.
 * - actor(): lấy admin User.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : admin request hoặc OAuth callback.
 * - OUTPUT: metadata/redirect URL JSON bounded.
 * - SIDE EFFECT: gọi Graph/cache và ghi SocialAccount qua service.
 * =====================================================================
 */
final class SocialAccountController extends Controller
{
    /**
     * Liệt kê Facebook Page account đã kết nối.
     *
     * Input: admin request.
     * Output: list metadata không có token.
     */
    public function index(Request $request): JsonResponse
    {
        $accounts = SocialAccount::query()->where('provider', 'facebook')->orderBy('display_name')->get();

        return BaseResponse::success($accounts->map(fn (SocialAccount $account): array => $this->payload($account))->values(), 'Danh sách Facebook Page.');
    }

    /**
     * Bắt đầu OAuth Page connection.
     *
     * Input: admin request.
     * Output: authorization_url; state nằm cache.
     */
    public function connect(Request $request, FacebookOAuthService $oauth): JsonResponse
    {
        return BaseResponse::success(['authorization_url' => $oauth->authorizationUrl($this->actor($request))], 'Mở Facebook để cấp quyền Page.');
    }

    /**
     * Xử lý callback code/state từ Facebook.
     *
     * Input: query code/state.
     * Output: Page metadata đã upsert.
     */
    public function callback(Request $request, FacebookOAuthService $oauth): JsonResponse
    {
        $request->validate(['code' => ['required', 'string'], 'state' => ['required', 'string', 'max:100']]);

        return BaseResponse::success($oauth->handleCallback((string) $request->string('code'), (string) $request->string('state')), 'Đã kết nối Facebook Page.');
    }

    /**
     * Tạo lại OAuth URL cho admin.
     *
     * Input: admin request/account route binding.
     * Output: authorization_url; account hiện tại không bị xóa.
     */
    public function reconnect(Request $request, SocialAccount $socialAccount, FacebookOAuthService $oauth): JsonResponse
    {
        abort_unless($socialAccount->provider?->value === 'facebook', 404);

        return BaseResponse::success(['authorization_url' => $oauth->authorizationUrl($this->actor($request))], 'Đã tạo URL reconnect Facebook.');
    }

    /**
     * Ngắt Page và giữ publication history.
     *
     * Input: admin request/account.
     * Output: account disconnected metadata.
     */
    public function disconnect(Request $request, SocialAccount $socialAccount, FacebookOAuthService $oauth): JsonResponse
    {
        abort_unless($socialAccount->provider?->value === 'facebook', 404);

        return BaseResponse::success($this->payload($oauth->disconnect($socialAccount)), 'Đã ngắt Facebook Page.');
    }

    /**
     * Serialize account không trả access token/scopes nhạy cảm.
     *
     * Input: SocialAccount.
     * Output: metadata bounded.
     */
    private function payload(SocialAccount $account): array
    {
        return [
            'id' => (int) $account->id,
            'provider' => $account->provider?->value,
            'account_type' => $account->account_type,
            'external_id' => $account->external_id,
            'display_name' => $account->display_name,
            'status' => $account->status?->value,
            'token_expires_at' => $account->token_expires_at?->toIso8601String(),
            'last_checked_at' => $account->last_checked_at?->toIso8601String(),
        ];
    }

    /**
     * Lấy admin actor của request.
     *
     * Input: Request auth:sanctum admin.
     * Output: User; abort nếu callback/request không có actor.
     */
    private function actor(Request $request): \App\Models\User
    {
        $user = $request->user();
        abort_unless($user instanceof \App\Models\User, 401);

        return $user;
    }
}
