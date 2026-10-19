<?php

namespace App\Services\Marketing;

use App\Enums\SocialAccountStatus;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Quản lý OAuth Page Facebook tách khỏi customer login.
 * =====================================================================
 * Service đổi code lấy user/page token, lưu page token encrypted qua model và
 * chỉ trả metadata Page. State ngắn hạn nằm cache, không lưu access token vào log.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - authorizationUrl(): tạo URL OAuth và state cache.
 * - handleCallback(): đổi code, lấy Pages và upsert account.
 * - disconnect(): disable account và giữ publication history.
 * - graphUrl(): dựng Graph URL.
 * - assertConfigured(): kiểm app credentials server-side.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : actor/code/state Facebook OAuth.
 * - OUTPUT: URL hoặc Page metadata bounded.
 * - SIDE EFFECT: gọi Graph API/cache/ghi SocialAccount; không trả token.
 * =====================================================================
 */
final class FacebookOAuthService
{
    /**
     * Tạo Facebook authorization URL và lưu state theo actor.
     *
     * Input: admin User.
     * Output: URL redirect Facebook; state không chứa actor/token.
     * Side effect: cache state 10 phút.
     */
    public function authorizationUrl(User $actor): string
    {
        $this->assertConfigured();
        $state = (string) Str::uuid();
        Cache::put('facebook-oauth:'.$state, ['user_id' => $actor->getKey()], now()->addMinutes(10));
        $query = http_build_query([
            'client_id' => config('social.facebook.app_id'),
            'redirect_uri' => config('social.facebook.redirect_uri'),
            'state' => $state,
            'scope' => implode(',', (array) config('social.facebook.scopes', [])),
            'response_type' => 'code',
        ]);

        return 'https://www.facebook.com/'.trim((string) config('social.facebook.graph_version', 'v20.0'), '/').'/dialog/oauth?'.$query;
    }

    /**
     * Đổi OAuth code lấy Page accounts và lưu token encrypted.
     *
     * Input: code/state callback gắn với actor khởi tạo.
     * Output: list Page metadata; state sai/hết hạn trả 400, mất quyền trả 403, provider lỗi trả 422/502.
     * Side effect: 2 Graph calls/cache pull/upsert SocialAccount.
     */
    public function handleCallback(string $code, string $state): array
    {
        $stateData = Cache::lock('facebook-oauth-consume:'.$state, 10)
            ->get(fn () => Cache::pull('facebook-oauth:'.$state));
        if (! is_array($stateData) || empty($stateData['user_id'])) {
            abort(400, 'Phiên kết nối Facebook không hợp lệ hoặc đã hết hạn. Hãy mở lại Kết nối Facebook Page.');
        }
        $actor = User::query()->find($stateData['user_id']);
        abort_unless($actor?->isActive() && ($actor->can('marketing.manage') || $actor->can('social_accounts.manage')), 403, 'Tài khoản không còn quyền kết nối Facebook Page.');
        $this->assertConfigured();
        try {
            $tokenResponse = Http::timeout(30)->get($this->graphUrl('/oauth/access_token'), [
                'client_id' => config('social.facebook.app_id'),
                'client_secret' => config('social.facebook.app_secret'),
                'redirect_uri' => config('social.facebook.redirect_uri'),
                'code' => $code,
            ]);
        } catch (ConnectionException $exception) {
            throw new HttpException(502, 'Không thể kết nối Facebook. Hãy bắt đầu kết nối lại.', $exception);
        }
        $tokenBody = $tokenResponse->json();
        if (! $tokenResponse->successful() || blank($tokenBody['access_token'] ?? null)) {
            abort($tokenResponse->serverError() ? 502 : 422, 'Facebook chưa cấp quyền kết nối. Hãy bắt đầu kết nối lại.');
        }
        try {
            $pagesResponse = Http::timeout(30)->get($this->graphUrl('/me/accounts'), [
                'fields' => 'id,name,access_token,perms',
                'access_token' => $tokenBody['access_token'],
            ]);
        } catch (ConnectionException $exception) {
            throw new HttpException(502, 'Không thể tải danh sách Facebook Page. Hãy kết nối lại.', $exception);
        }
        $pages = $pagesResponse->json('data');
        if (! $pagesResponse->successful() || ! is_array($pages)) {
            abort($pagesResponse->serverError() ? 502 : 422, 'Facebook chưa trả danh sách Page. Hãy kiểm tra quyền Page và kết nối lại.');
        }
        $result = [];
        foreach ($pages as $page) {
            if (! is_array($page) || blank($page['id'] ?? null) || blank($page['access_token'] ?? null)) {
                continue;
            }
            $account = SocialAccount::query()->withTrashed()->firstOrNew([
                'provider' => 'facebook', 'account_type' => 'page', 'external_id' => (string) $page['id'],
            ]);
            if ($account->trashed()) {
                $account->restore();
            }
            $account->forceFill([
                'display_name' => (string) ($page['name'] ?? $page['id']),
                'access_token' => (string) $page['access_token'],
                'token_expires_at' => now()->addDays(60),
                'scopes_json' => array_values((array) ($page['perms'] ?? config('social.facebook.scopes', []))),
                'status' => SocialAccountStatus::Active,
                'metadata_json' => ['source' => 'facebook_oauth'],
                'last_checked_at' => now(),
                'created_by' => $account->created_by ?: $stateData['user_id'],
                'updated_by' => $stateData['user_id'],
            ])->save();
            $result[] = [
                'id' => (int) $account->getKey(),
                'external_id' => $account->external_id,
                'display_name' => $account->display_name,
                'provider' => 'facebook',
                'status' => SocialAccountStatus::Active->value,
            ];
        }

        return $result;
    }

    /**
     * Ngắt kết nối Page mà không xóa lịch sử publication.
     *
     * Input: SocialAccount.
     * Output: account disabled/disconnected không trả token.
     * Side effect: xóa token encrypted và cập nhật status.
     */
    public function disconnect(SocialAccount $account): SocialAccount
    {
        $account->forceFill(['access_token' => null, 'status' => SocialAccountStatus::Disconnected, 'updated_by' => auth()->id()])->save();

        return $account->refresh();
    }

    /**
     * Dựng Graph URL theo version cấu hình.
     *
     * Input: path Graph.
     * Output: https URL; không gọi mạng.
     */
    private function graphUrl(string $path): string
    {
        return 'https://graph.facebook.com/'.trim((string) config('social.facebook.graph_version', 'v20.0'), '/').$path;
    }

    /**
     * Kiểm tra đủ app ID/secret/redirect trước khi bắt đầu OAuth.
     *
     * Input: config server-side.
     * Output: void hoặc HTTP 503 khi thiếu cấu hình; không side effect.
     */
    private function assertConfigured(): void
    {
        if (blank(config('social.facebook.app_id')) || blank(config('social.facebook.app_secret')) || blank(config('social.facebook.redirect_uri'))) {
            abort(503, 'Facebook OAuth chưa được cấu hình APP_ID/APP_SECRET/REDIRECT_URI.');
        }
    }
}
