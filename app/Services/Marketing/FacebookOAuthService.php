<?php

namespace App\Services\Marketing;

use App\Enums\SocialAccountStatus;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

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
     * Input: code/state callback.
     * Output: list Page metadata không có token.
     * Side effect: 2 Graph calls/cache pull/upsert SocialAccount.
     */
    public function handleCallback(string $code, string $state): array
    {
        $this->assertConfigured();
        $stateData = Cache::pull('facebook-oauth:'.$state);
        if (! is_array($stateData) || empty($stateData['user_id'])) {
            throw new RuntimeException('OAuth state không hợp lệ hoặc đã hết hạn.');
        }
        $tokenResponse = Http::timeout(30)->get($this->graphUrl('/oauth/access_token'), [
            'client_id' => config('social.facebook.app_id'),
            'client_secret' => config('social.facebook.app_secret'),
            'redirect_uri' => config('social.facebook.redirect_uri'),
            'code' => $code,
        ]);
        $tokenBody = $tokenResponse->json();
        if (! $tokenResponse->successful() || blank($tokenBody['access_token'] ?? null)) {
            throw new RuntimeException('Facebook không cấp được user access token.');
        }
        $pagesResponse = Http::timeout(30)->get($this->graphUrl('/me/accounts'), [
            'fields' => 'id,name,access_token,perms',
            'access_token' => $tokenBody['access_token'],
        ]);
        $pages = $pagesResponse->json('data');
        if (! $pagesResponse->successful() || ! is_array($pages)) {
            throw new RuntimeException('Facebook không trả danh sách Page.');
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
     * Output: void hoặc RuntimeException; không side effect.
     */
    private function assertConfigured(): void
    {
        if (blank(config('social.facebook.app_id')) || blank(config('social.facebook.app_secret')) || blank(config('social.facebook.redirect_uri'))) {
            throw new RuntimeException('Facebook OAuth chưa được cấu hình APP_ID/APP_SECRET/REDIRECT_URI.');
        }
    }
}
