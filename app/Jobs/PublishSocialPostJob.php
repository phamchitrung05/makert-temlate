<?php

namespace App\Jobs;

use App\Enums\MediaAssetField;
use App\Enums\SocialPostPublicationStatus;
use App\Enums\SocialPublicationStatus;
use App\Models\MediaAsset;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\SocialPublication;
use App\Services\Marketing\Publishing\FacebookPublisher;
use App\Services\Marketing\Publishing\SocialPublisherContract;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Worker publish một Social Post target.
 * =====================================================================
 * Mỗi Page chạy độc lập; lỗi một Page không làm mất publication Page khác.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nhận publication ID.
 * - uniqueId(): tạo khóa publisher per publication.
 * - handle(): gọi adapter và cập nhật publication/target/post.
 * - fail(): ghi lỗi bounded/retryable.
 * - complete(): ghi kết quả đã xác nhận mà không tạo bài bên ngoài lần nữa.
 * - isRetryable(): nhận biết lỗi an toàn để thử lại trước khi gửi feed.
 * - recomputePostStatus(): tính aggregate publication status.
 * - publisher(): resolve adapter theo platform.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : SocialPublication queued.
 * - OUTPUT: published/failed publication với external ID.
 * - SIDE EFFECT: gọi Graph API/DB; không tạo publication mới khi retry.
 * =====================================================================
 */
final class PublishSocialPostJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    /**
     * Khởi tạo publisher job.
     *
     * Input: publication ID.
     * Output: queue payload không chứa token.
     */
    public function __construct(public readonly int $publicationId) {}

    /**
     * Tạo unique key publisher cho một publication.
     *
     * Input: publication ID.
     * Output: key social-publication:<id>; không side effect.
     */
    public function uniqueId(): string
    {
        return 'social-publication:'.$this->publicationId;
    }

    /**
     * Gọi publisher và cập nhật kết quả từng Page.
     *
     * Input: SocialPublisherContract/FacebookPublisher.
     * Output: không trả giá trị; publication/target/post persisted.
     * Side effect: HTTP Graph API; không giữ transaction qua HTTP.
     */
    public function handle(FacebookPublisher $facebook): void
    {
        $publication = SocialPublication::query()
            ->with(['socialPostTarget.socialPost.variants.mediaAssetUsages.mediaAsset.media', 'socialPostTarget.socialPostVariant', 'socialPostTarget.socialPost', 'socialPostTarget.socialAccount'])
            ->find($this->publicationId);
        if (! $publication || $publication->status === SocialPublicationStatus::Published) {
            return;
        }
        if ($publication->requiresConfirmation()) {
            $this->fail($publication, 'SOCIAL_PUBLISH_UNCERTAIN', 'Chưa xác định kết quả tạo bài Facebook. Hãy kiểm tra Page; hệ thống đã chặn đăng lại để tránh trùng bài.', false);

            return;
        }
        $target = $publication->socialPostTarget;
        $post = $target?->socialPost;
        if (! $target || ! $post || $post->review_status?->value !== 'approved') {
            $this->fail($publication, 'SOCIAL_NOT_APPROVED', 'Social Post không còn đủ điều kiện publish.', false);

            return;
        }
        if (filled($publication->external_post_id)) {
            $this->complete($publication, $target, [
                'external_id' => $publication->external_post_id,
                'external_url' => $publication->external_post_url,
                'request_id' => $publication->provider_request_id,
                'metadata' => $publication->response_metadata_json,
            ]);

            return;
        }
        // Chỉ một worker được claim publication; job giao lại không chạy song song.
        $claimed = SocialPublication::query()->whereKey($publication->getKey())
            ->whereIn('status', [SocialPublicationStatus::Queued, SocialPublicationStatus::Retryable])
            ->update([
                'status' => SocialPublicationStatus::Publishing->value,
                'attempts' => DB::raw('attempts + 1'),
                'started_at' => now(), 'completed_at' => null,
                'error_code' => null, 'error_message' => null,
            ]);
        if ($claimed !== 1) {
            if ($publication->status === SocialPublicationStatus::Publishing) {
                $this->fail($publication, 'SOCIAL_PUBLISH_UNCERTAIN', 'Worker đăng bài đã bị gián đoạn hoặc còn đang chạy. Hãy kiểm tra Page trước khi xử lý lại.', false);
            }

            return;
        }
        $publication->refresh();
        $variant = $target->socialPostVariant
            ?? $post->variants()->where('platform', 'facebook')->first();
        $images = $variant
            ? $variant->mediaAssetUsagesForField(MediaAssetField::SocialPostFacebookImages)
                ->with('mediaAsset.media')->orderBy('sort_order')->get()->map(fn ($usage) => $usage->mediaAsset)->filter()->values()->all()
            : [];
        if ($images === []) {
            $assetId = $post->mediaAssetUsagesForField(MediaAssetField::SocialPostImage)->value('media_asset_id');
            $legacy = $assetId ? MediaAsset::query()->find($assetId) : null;
            $images = $legacy ? [$legacy] : [];
        }
        if ($images === []) {
            $this->fail($publication, 'SOCIAL_IMAGE_MISSING', 'Social Post không có gallery ảnh sẵn sàng.', false);

            return;
        }
        $target->forceFill(['status' => SocialPublicationStatus::Publishing])->save();
        $post->forceFill(['publication_status' => SocialPostPublicationStatus::Publishing])->save();

        try {
            $result = $this->publisher($target)->publish($post, $target, $variant, $images, $publication);
            $this->complete($publication, $target, $result);
        } catch (Throwable $exception) {
            if ($publication->refresh()->requiresConfirmation()) {
                $this->fail($publication, 'SOCIAL_PUBLISH_UNCERTAIN', 'Chưa xác định kết quả tạo bài Facebook. Hãy kiểm tra Page; hệ thống đã chặn đăng lại để tránh trùng bài.', false);

                return;
            }
            $retryable = $this->isRetryable($exception);
            $this->fail($publication, 'SOCIAL_PUBLISH_FAILED', $exception->getMessage(), $retryable);
            if ($retryable && $this->attempts() < $this->tries) {
                throw $exception;
            }
        }
    }

    /**
     * Ghi kết quả đã được provider xác nhận và cập nhật aggregate status.
     * Input: publication, target và external reference đã xác nhận.
     * Output: publication/target/post published trong một transaction, không gọi API.
     */
    private function complete(SocialPublication $publication, SocialPostTarget $target, array $result): void
    {
        DB::transaction(function () use ($publication, $target, $result): void {
            $publication->forceFill([
                'status' => SocialPublicationStatus::Published,
                'provider_request_id' => $result['request_id'] ?? null,
                'external_post_id' => $result['external_id'] ?? null,
                'external_post_url' => $result['external_url'] ?? null,
                'response_metadata_json' => $result['metadata'] ?? [],
                'completed_at' => now(), 'error_code' => null, 'error_message' => null,
            ])->save();
            $target->forceFill(['status' => SocialPublicationStatus::Published, 'published_at' => now(), 'last_error_code' => null, 'last_error_message' => null])->save();
            $this->recomputePostStatus($target->socialPost()->firstOrFail());
        });
    }

    /**
     * Ghi publication/target failure với message giới hạn.
     *
     * Input: publication, error code/message, retryable flag.
     * Output: publication failed/retryable.
     * Side effect: cập nhật target/post aggregate; không ghi raw response.
     */
    private function fail(SocialPublication $publication, string $code, string $message, bool $retryable): void
    {
        $status = $retryable ? SocialPublicationStatus::Retryable : SocialPublicationStatus::Failed;
        $publication->forceFill([
            'status' => $status,
            'error_code' => substr($code, 0, 120),
            'error_message' => substr($message, 0, 500),
            'completed_at' => now(),
        ])->save();
        $target = $publication->socialPostTarget()->first();
        if ($target) {
            $target->forceFill([
                'status' => $status,
                'last_error_code' => substr($code, 0, 120),
                'last_error_message' => substr($message, 0, 500),
            ])->save();
            $post = $target->socialPost()->first();
            if ($post) {
                $this->recomputePostStatus($post);
            }
        }
    }

    /**
     * Tính aggregate publication status theo target enabled.
     *
     * Input: SocialPost.
     * Output: post publication_status; không gọi provider.
     */
    private function recomputePostStatus(SocialPost $post): void
    {
        $statuses = $post->targets()->where('enabled', true)->pluck('status')->map(fn ($status): string => $status instanceof SocialPublicationStatus ? $status->value : (string) $status);
        if ($statuses->isNotEmpty() && $statuses->every(fn (string $status): bool => $status === SocialPublicationStatus::Published->value)) {
            $post->forceFill(['publication_status' => SocialPostPublicationStatus::Published])->save();
        } elseif ($statuses->contains(fn (string $status): bool => in_array($status, [SocialPublicationStatus::Failed->value, SocialPublicationStatus::Retryable->value], true))) {
            $post->forceFill(['publication_status' => SocialPostPublicationStatus::Failed])->save();
        } else {
            $post->forceFill(['publication_status' => SocialPostPublicationStatus::Publishing])->save();
        }
    }

    /**
     * Resolve publisher theo platform allowlist.
     *
     * Input: target platform.
     * Output: SocialPublisherContract; exception nếu platform chưa hỗ trợ.
     */
    private function publisher(SocialPostTarget $target): SocialPublisherContract
    {
        return match ($target->platform?->value ?? (string) $target->platform) {
            'facebook' => app(FacebookPublisher::class),
            default => throw new \RuntimeException('Social platform chưa có publisher adapter.'),
        };
    }

    /**
     * Phân loại lỗi Graph API/network cho retry.
     *
     * Input: Throwable.
     * Output: true với timeout/5xx/429; false với lỗi token/validation.
     */
    private function isRetryable(Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return $exception instanceof ConnectionException
            || str_contains($message, 'timeout') || str_contains($message, 'timed out') || str_contains($message, 'tempor')
            || str_contains($message, '429') || preg_match('/\b5\d{2}\b/', $message) === 1;
    }
}
