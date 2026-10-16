<?php

namespace App\Services\Marketing;

use App\Enums\MediaAssetField;
use App\Models\SocialPost;
use App\Services\Ai\WritingProfiles\WritingProfileSnapshotService;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Dựng context đã giới hạn cho AI Social Post.
 * =====================================================================
 * Context chỉ chứa dữ liệu product/campaign đã được xác minh và profile văn
 * phong snapshot. Dữ liệu được bọc như reference, không cho AI biến thành
 * instruction tùy ý hoặc truy cập token/URL nội bộ.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nhận service chụp writing profile.
 * - build(): dựng context và hash ổn định cho một Social Post.
 * - validateFactIds(): kiểm tra fact IDs provider trả về thuộc allowlist.
 * - boundText(): giới hạn độ dài chuỗi đưa vào prompt.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : SocialPost cùng product/campaign/facts/profile.
 * - OUTPUT: context bounded, fact allowlist, asset IDs và hash.
 * - SIDE EFFECT: chỉ đọc database/profile; không gọi provider hoặc ghi Post.
 * =====================================================================
 */
final class SocialPostContextBuilder
{
    /**
     * Nhận service snapshot profile để context bất biến theo run.
     *
     * Input: WritingProfileSnapshotService từ container.
     * Output: builder sẵn sàng dựng context; không đọc DB lúc khởi tạo.
     */
    public function __construct(private readonly WritingProfileSnapshotService $profiles) {}

    /**
     * Dựng dữ liệu grounding đã allowlist cho caption và prompt ảnh.
     *
     * Input: SocialPost đã có product/campaign; profile ID tùy chọn.
     * Output: context bounded gồm facts/asset IDs/profile và context_hash.
     * Side effect: đọc facts/reference usages; ValidationException nếu profile sai.
     */
    public function build(SocialPost $post, ?int $writingProfileId = null): array
    {
        $post->loadMissing([
            'product.facts' => fn ($query) => $query
                ->where('verified', true)
                ->where('is_allowed_for_ai', true)
                ->orderBy('id'),
            'campaign',
            'product.mediaAssetUsages.mediaAsset',
        ]);

        $product = $post->product;
        $campaign = $post->campaign;
        $facts = $product?->facts?->map(fn ($fact): array => [
            'id' => (int) $fact->getKey(),
            'key' => $this->boundText((string) $fact->fact_key, 80),
            'value' => $this->boundText((string) $fact->fact_value, 1000),
            'source' => $this->boundText((string) $fact->source, 255),
            'version' => (int) $fact->version,
        ])->values()->all() ?? [];

        $profile = $this->profiles->snapshot($writingProfileId);
        $referenceAssetIds = $product?->relationLoaded('mediaAssetUsages')
            ? $product->mediaAssetUsages
                ->filter(fn ($usage): bool => $usage->field === MediaAssetField::ProductReferenceImages
                    && $usage->mediaAsset !== null)
                ->sortBy('sort_order')
                ->pluck('media_asset_id')
                ->map(fn ($id): int => (int) $id)
                ->take(20)
                ->values()
                ->all()
            : [];
        $context = [
            'language' => 'vi',
            'product' => $product ? [
                'id' => (int) $product->getKey(),
                'name' => $this->boundText((string) $product->name, 160),
                'short_description' => $this->boundText((string) $product->short_description, 1200),
                'default_cta' => $this->boundText((string) $product->default_cta, 500),
            ] : null,
            'campaign' => $campaign ? [
                'id' => (int) $campaign->getKey(),
                'name' => $this->boundText((string) $campaign->name, 160),
                'objective' => $this->boundText((string) $campaign->objective, 1000),
                'message' => $this->boundText((string) $campaign->message, 1500),
            ] : null,
            'facts' => $facts,
            'allowed_fact_ids' => array_map('intval', Arr::pluck($facts, 'id')),
            'writing_profile' => $profile,
            'reference_asset_ids' => $referenceAssetIds,
            'visual_constraints' => [
                'output' => 'product-focused social image',
                'aspect_ratio' => '4:5',
                'avoid' => ['watermark', 'fake logo', 'unreadable text', 'extra products', 'distorted hands'],
            ],
        ];

        $context['context_hash'] = hash('sha256', json_encode(
            $context,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));

        return $context;
    }

    /**
     * Chặn provider tham chiếu fact không có trong context hiện tại.
     *
     * Input: danh sách IDs provider trả về và context đã dựng.
     * Output: danh sách integer unique; ValidationException khi có ID lạ.
     * Side effect: không ghi database hoặc gọi provider.
     */
    public function validateFactIds(array $factIds, array $context): array
    {
        $factIds = array_values(array_unique(array_map('intval', $factIds)));
        $allowed = array_map('intval', (array) ($context['allowed_fact_ids'] ?? []));
        if (array_diff($factIds, $allowed) !== []) {
            throw ValidationException::withMessages([
                'ai' => 'AI tham chiếu product fact chưa được xác minh hoặc không thuộc context.',
            ]);
        }

        return $factIds;
    }

    /**
     * Giới hạn chuỗi reference trước khi đưa vào prompt.
     *
     * Input: text chưa tin cậy và giới hạn ký tự.
     * Output: chuỗi UTF-8 đã trim/limit; không side effect.
     */
    private function boundText(string $text, int $limit): string
    {
        return Str::limit(trim($text), $limit, '');
    }
}
