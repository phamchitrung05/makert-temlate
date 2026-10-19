<?php

namespace App\Services\Marketing;

use App\Models\SocialPost;
use App\Services\Ai\WritingProfiles\WritingProfileSnapshotService;
use Illuminate\Support\Str;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Dựng context đã giới hạn cho AI Social Post.
 * =====================================================================
 * Context chỉ chứa campaign tùy chọn và profile văn phong snapshot. Social
 * Post không đọc Product, ProductFact hoặc media tham chiếu của Product.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nhận service chụp writing profile.
 * - build(): dựng context và hash ổn định cho một Social Post.
 * - boundText(): giới hạn độ dài chuỗi đưa vào prompt.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : SocialPost cùng campaign/profile tùy chọn.
 * - OUTPUT: context bounded và hash.
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
     * Dựng context bounded cho caption và prompt ảnh.
     *
     * Input: SocialPost có campaign tùy chọn; profile ID tùy chọn.
     * Output: context bounded gồm source/campaign/profile và context_hash.
     * Side effect: chỉ đọc campaign/profile; ValidationException nếu profile sai.
     */
    public function build(SocialPost $post, ?int $writingProfileId = null): array
    {
        $post->loadMissing('campaign');
        $campaign = $post->campaign;
        $profile = $this->profiles->snapshot($writingProfileId);
        $context = [
            'language' => 'vi',
            'campaign' => $campaign ? [
                'id' => (int) $campaign->getKey(),
                'name' => $this->boundText((string) $campaign->name, 160),
                'objective' => $this->boundText((string) $campaign->objective, 1000),
                'message' => $this->boundText((string) $campaign->message, 1500),
            ] : null,
            'writing_profile' => $profile,
            'visual_constraints' => [
                'output' => 'Facebook social image phù hợp với nội dung caption',
                'aspect_ratio' => '4:5',
                'avoid' => ['watermark', 'fake logo', 'unreadable text', 'distorted hands'],
            ],
        ];

        $context['context_hash'] = hash('sha256', json_encode(
            $context,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));

        return $context;
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
