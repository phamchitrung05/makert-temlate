<?php

namespace App\Services\Marketing\Publishing;

use App\Models\MediaAsset;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Đăng Social Post ảnh + caption qua Meta Graph API.
 * =====================================================================
 * Adapter dùng Page access token đã encrypted trong SocialAccount, không dùng
 * Facebook PHP SDK và không trả token/raw response ra application payload.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - platform(): trả facebook.
 * - publish(): POST /{page-id}/photos với caption/url.
 * - imageUrl(): lấy URL public của MediaAsset.
 * - graphUrl(): dựng URL theo graph version.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : SocialPost, Facebook Page target, public MediaAsset.
 * - OUTPUT: external post ID/url và request metadata bounded.
 * - SIDE EFFECT: gọi Meta Graph API; không ghi database.
 * =====================================================================
 */
final class FacebookPublisher implements SocialPublisherContract
{
    /**
     * Trả platform key của adapter.
     *
     * Input: không có.
     * Output: facebook; không side effect.
     */
    public function platform(): string
    {
        return 'facebook';
    }

    /**
     * Đăng ảnh và canonical caption lên Facebook Page.
     *
     * Input: SocialPost, target có Page token và public image.
     * Output: external_id/external_url/request_id/metadata bounded.
     * Side effect: HTTP POST Graph API; RuntimeException khi token/API/image lỗi.
     */
    public function publish(SocialPost $post, SocialPostTarget $target, MediaAsset $image): array
    {
        $account = $target->socialAccount;
        if (! $account || $account->provider?->value !== 'facebook' || blank($account->access_token)) {
            throw new RuntimeException('Facebook Page chưa có access token hợp lệ.');
        }
        if ($account->token_expires_at?->isPast()) {
            throw new RuntimeException('Facebook Page access token đã hết hạn.');
        }
        $url = $this->imageUrl($image);
        if ($url === null) {
            throw new RuntimeException('Ảnh Social Post chưa có public URL để publish Facebook.');
        }
        $response = Http::asForm()
            ->timeout((int) config('ai.import.timeout', 30))
            ->post($this->graphUrl('/'.rawurlencode($account->external_id).'/photos'), [
                'caption' => (string) $post->caption,
                'url' => $url,
                'published' => 'true',
                'access_token' => $account->access_token,
            ]);
        $body = $response->json();
        if (! $response->successful() || ! is_array($body) || blank($body['id'] ?? null)) {
            $message = is_array($body) ? data_get($body, 'error.message') : null;
            throw new RuntimeException('Facebook Graph API lỗi: '.($message ?: 'request không thành công').'.');
        }
        $externalId = (string) $body['id'];

        return [
            'external_id' => $externalId,
            'external_url' => 'https://www.facebook.com/'.rawurlencode($externalId),
            'request_id' => $response->header('x-fb-trace-id'),
            'metadata' => array_intersect_key($body, array_flip(['id', 'post_id'])),
        ];
    }

    /**
     * Lấy public URL image qua Spatie Media Library.
     *
     * Input: MediaAsset có collection library.
     * Output: URL string/null; không lộ path private.
     */
    private function imageUrl(MediaAsset $image): ?string
    {
        $media = $image->getFirstMedia('library');
        if (! $media || $image->visibility?->value !== 'public') {
            return null;
        }

        try {
            return $media->getUrl();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Dựng URL Graph API theo version server-side.
     *
     * Input: path bắt đầu bằng slash.
     * Output: https URL; không gọi mạng.
     */
    private function graphUrl(string $path): string
    {
        return 'https://graph.facebook.com/'.trim((string) config('social.facebook.graph_version', 'v20.0'), '/').$path;
    }
}
