<?php

namespace App\Services\Marketing\Publishing;

use App\Models\MediaAsset;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\SocialPostVariant;
use App\Models\SocialPublication;
use App\Services\Marketing\FacebookContent;
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
 * - publish(): upload 3--4 unpublished photos rồi tạo feed post có attached_media.
 * - imageUrl(): lấy URL public của MediaAsset.
 * - graphUrl(): dựng URL theo graph version.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : SocialPost, Facebook Page target, public MediaAsset.
 * - OUTPUT: external post ID/url và request metadata bounded.
 * - SIDE EFFECT: gọi Meta Graph API; lưu journal upload/feed trước khi publish.
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
     * Input: SocialPost, target có Page token, public image và publication đã claim.
     * Output: external_id/external_url/request_id/metadata bounded.
     * Side effect: HTTP POST Graph API; RuntimeException khi token/API/image lỗi.
     */
    public function publish(SocialPost $post, SocialPostTarget $target, ?SocialPostVariant $variant, array $images, SocialPublication $publication): array
    {
        if ($publication->requiresConfirmation()) {
            throw new RuntimeException('Chưa xác định kết quả tạo bài Facebook. Hãy kiểm tra Page trước khi xử lý lại.');
        }
        $account = $target->socialAccount;
        if (! $account || $account->provider?->value !== 'facebook' || blank($account->access_token)) {
            throw new RuntimeException('Facebook Page chưa có access token hợp lệ.');
        }
        if ($account->token_expires_at?->isPast()) {
            throw new RuntimeException('Facebook Page access token đã hết hạn.');
        }
        if ($images === []) {
            throw new RuntimeException('Facebook variant chưa có ảnh để publish.');
        }
        $images = array_slice($images, 0, 5);
        $metadata = (array) $publication->response_metadata_json;
        $gallery = array_map(fn (MediaAsset $image): int => (int) $image->getKey(), $images);
        $mediaIds = ($metadata['gallery_asset_ids'] ?? []) === $gallery ? ($metadata['media_ids'] ?? []) : [];
        foreach ($images as $index => $image) {
            if (isset($mediaIds[$index])) {
                continue;
            }
            $url = $this->imageUrl($image);
            if ($url === null) {
                throw new RuntimeException('Ảnh Social Post chưa có public URL để publish Facebook.');
            }
            $response = Http::asForm()
                ->timeout((int) config('ai.import.timeout', 30))
                ->post($this->graphUrl('/'.rawurlencode($account->external_id).'/photos'), [
                    'url' => $url,
                    'published' => 'false',
                    'access_token' => $account->access_token,
                ]);
            $body = $response->json();
            if (! $response->successful() || ! is_array($body) || blank($body['id'] ?? null)) {
                $message = is_array($body) ? data_get($body, 'error.message') : null;
                throw new RuntimeException('Facebook Graph API lỗi '.$response->status().': '.($message ?: 'request không thành công').'.');
            }
            $mediaIds[] = (string) $body['id'];
            $metadata['gallery_asset_ids'] = $gallery;
            $metadata['media_ids'] = $mediaIds;
            $publication->forceFill(['response_metadata_json' => $metadata])->save();
        }
        $content = (array) ($variant?->content_json ?? []);
        $message = $content === [] ? (string) $post->caption : FacebookContent::caption($content);
        $attachedMedia = json_encode(array_map(fn (string $id): array => ['media_fbid' => $id], $mediaIds), JSON_THROW_ON_ERROR);
        // Ghi trước HTTP: timeout hoặc worker bị dừng không được tạo feed lần hai.
        $metadata['feed_request_started_at'] = now()->toIso8601String();
        $publication->forceFill(['response_metadata_json' => $metadata])->save();
        $response = Http::asForm()
            ->timeout((int) config('ai.import.timeout', 30))
            ->post($this->graphUrl('/'.rawurlencode($account->external_id).'/feed'), [
                'message' => $message,
                'attached_media' => $attachedMedia,
                'access_token' => $account->access_token,
            ]);
        $body = $response->json();
        if (! $response->successful() || ! is_array($body) || blank($body['id'] ?? null)) {
            // Phản hồi 4xx xác nhận yêu cầu bị từ chối; 5xx/mất phản hồi vẫn chưa rõ kết quả.
            if ($response->clientError()) {
                unset($metadata['feed_request_started_at']);
                $publication->forceFill(['response_metadata_json' => $metadata])->save();
            }
            $message = is_array($body) ? data_get($body, 'error.message') : null;
            throw new RuntimeException('Facebook Graph API lỗi '.$response->status().': '.($message ?: 'request không thành công').'.');
        }
        $externalId = (string) $body['id'];
        $metadata = array_merge($metadata, array_intersect_key($body, array_flip(['id', 'post_id'])));
        $publication->forceFill([
            'external_post_id' => $externalId,
            'external_post_url' => 'https://www.facebook.com/'.rawurlencode($externalId),
            'provider_request_id' => $response->header('x-fb-trace-id'),
            'response_metadata_json' => $metadata,
        ])->save();

        return [
            'external_id' => $externalId,
            'external_url' => 'https://www.facebook.com/'.rawurlencode($externalId),
            'request_id' => $response->header('x-fb-trace-id'),
            'metadata' => $metadata,
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
