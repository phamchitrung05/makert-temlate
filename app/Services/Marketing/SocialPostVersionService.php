<?php

namespace App\Services\Marketing;

use App\Models\SocialPost;
use App\Models\SocialPostVariant;
use App\Models\SocialPostVersion;
use Illuminate\Support\Facades\DB;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Ghi snapshot bất biến cho từng lần tạo Social Post.
 * =====================================================================
 * Version lưu caption, ảnh, context, prompt/profile/provider snapshot và lý do
 * thay đổi để regenerate vẫn truy nguyên được mà không lộ secret.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - create(): khóa post, tính version_no/hash và ghi snapshot.
 * - assetIds(): đọc danh sách asset đang attach.
 * - boundedProvider(): lọc metadata provider an toàn.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : SocialPost và snapshot AI đã allowlist.
 * - OUTPUT: SocialPostVersion immutable.
 * - SIDE EFFECT: ghi database trong transaction; không gọi provider.
 * =====================================================================
 */
final class SocialPostVersionService
{
    /**
     * Ghi một snapshot version và giữ asset cũ khi regenerate.
     *
     * Input: post, context/prompt/profile/provider và reason/actor.
     * Output: SocialPostVersion đã lưu; provider snapshot được lọc bounded.
     * Side effect: lock post/usage, insert version; không xoá asset.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $promptSnapshot
     * @param  array<string, mixed>|null  $profileSnapshot
     * @param  array<string, mixed>|null  $providerSnapshot
     */
    public function create(
        SocialPost $post,
        array $context,
        array $promptSnapshot,
        ?array $profileSnapshot,
        ?array $providerSnapshot,
        string $reason,
        ?int $actorId = null,
    ): SocialPostVersion {
        return DB::transaction(function () use ($post, $context, $promptSnapshot, $profileSnapshot, $providerSnapshot, $reason, $actorId): SocialPostVersion {
            $locked = SocialPost::query()->lockForUpdate()->findOrFail($post->getKey());
            $variant = $locked->variants()->where('platform', 'facebook')->lockForUpdate()->first();
            $assetIds = $this->assetIds($locked, $variant);
            $content = (array) ($variant?->content_json ?? []);
            $caption = (string) ($locked->caption ?? '');
            $versionNo = $variant
                ? ((int) $variant->versions()->max('version_no')) + 1
                : ((int) $locked->versions()->whereNull('social_post_variant_id')->max('version_no')) + 1;
            $provider = $this->boundedProvider($providerSnapshot);
            $hash = hash('sha256', json_encode([
                'post_id' => (int) $locked->getKey(),
                'version_no' => $versionNo,
                'caption' => $caption,
                'content' => $content,
                'asset_ids' => $assetIds,
                'context_hash' => $context['context_hash'] ?? null,
                'prompt' => $promptSnapshot,
                'provider' => $provider,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return SocialPostVersion::query()->create([
                'social_post_id' => $locked->getKey(),
                'social_post_variant_id' => $variant?->getKey(),
                'version_no' => $versionNo,
                'caption' => $caption !== '' ? $caption : null,
                'content_json' => $content !== [] ? $content : null,
                'context_json' => $this->boundedContext($context),
                'asset_ids_json' => $assetIds,
                'prompt_snapshot_json' => $promptSnapshot,
                'writing_profile_snapshot_json' => $profileSnapshot,
                'provider_snapshot_json' => $provider,
                'version_hash' => $hash,
                'reason' => substr($reason, 0, 80),
                'created_by' => $actorId,
            ]);
            if ($variant) {
                $variant->forceFill(['current_version_no' => $versionNo])->save();
            }
        });
    }

    /**
     * Đọc asset IDs của field Social Post image theo thứ tự usage.
     *
     * Input: SocialPost đã khóa.
     * Output: list<int> asset IDs; không sửa usage.
     */
    public function assetIds(SocialPost $post, ?SocialPostVariant $variant = null): array
    {
        $usages = $variant
            ? $variant->mediaAssetUsagesForField(\App\Enums\MediaAssetField::SocialPostFacebookImages)
            : $post->mediaAssetUsagesForField(\App\Enums\MediaAssetField::SocialPostImage);

        return $usages
            ->orderBy('sort_order')
            ->pluck('media_asset_id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Giới hạn context trước khi lưu audit snapshot.
     *
     * Input: context AI đã allowlist.
     * Output: context không chứa cờ nội bộ/secret; không query/ghi DB.
     */
    private function boundedContext(array $context): array
    {
        return [
            'context_hash' => $context['context_hash'] ?? null,
            'language' => $context['language'] ?? 'vi',
            'campaign' => $context['campaign'] ?? null,
            'visual_constraints' => $context['visual_constraints'] ?? [],
        ];
    }

    /**
     * Chỉ lưu metadata provider cần cho audit, không lưu connection/key/raw.
     *
     * Input: provider diagnostics tùy chọn.
     * Output: provider/model/prompt schema metadata bounded.
     */
    private function boundedProvider(?array $provider): ?array
    {
        if ($provider === null) {
            return null;
        }

        return array_intersect_key($provider, array_flip([
            'provider', 'model', 'prompt_key', 'prompt_version', 'schema_version',
            'component', 'generation_no', 'diagnostics',
        ]));
    }
}
