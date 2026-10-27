<?php

namespace App\Services\Marketing;

use App\Models\AiImageProfile;
use App\Models\AiWritingProfile;
use App\Models\Campaign;
use Illuminate\Support\Collection;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Ghép cặp Pool văn phong/visual style/ảnh tham chiếu.
 * =====================================================================
 * SocialSchedulePairingService lọc các profile/media trong Pool của chiến
 * dịch và chấm điểm ghép cặp 4 tầng (trụ cột, tương thích giọng-ảnh, xoay
 * vòng chống lặp, chọn tổ hợp cao nhất). Pool trống fallback về toàn bộ kho.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - pairForSlot(): chọn bộ (writing_profile_id, image_profile_id,
 *   subject_media_ids) cho một slot content.
 * - writingPool()/imagePool()/subjectPool(): resolve Pool của campaign.
 * - scoreWriting()/scoreImage(): chấm điểm ghép cặp.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Campaign + slot content (pillar_type, keywords, ngày).
 * - OUTPUT: array pairing gán vào DailyContentPlan slot.
 * - SIDE EFFECT: chỉ đọc profile/media; không ghi database.
 * =====================================================================
 */
final class SocialSchedulePairingService
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Chọn tổ hợp văn phong + style + ảnh tham chiếu cho một slot.
     * =====================================================================
     * Input: campaign, slot content (pillar_type, keywords_json), danh sách
     *   đã dùng trong tuần (usedToneIds, usedStyleIds, usedSubjectIds) và
     *   lịch sử đã ghép trong campaign (previousCombos).
     * Output: array{writing_profile_id, image_profile_id, subject_media_ids}
     * Side effect: chỉ đọc DB; không ghi.
     * =====================================================================
     */
    public function pairForSlot(
        Campaign $campaign,
        array $slot,
        array $usedToneIds = [],
        array $usedStyleIds = [],
        array $usedSubjectIds = [],
        array $previousCombos = [],
    ): array {
        $pillar = (string) ($slot['pillar_type'] ?? 'inspire');
        $keywords = collect((array) ($slot['keywords_json'] ?? []))->map(fn ($k) => mb_strtolower((string) $k));

        $writingPool = $this->writingPool($campaign);
        $imagePool = $this->imagePool($campaign);
        $subjectPool = collect((array) ($campaign->subject_media_ids ?? []));

        $tone = $this->pickBest($writingPool, $usedToneIds, $pillar, $keywords, 'writing');
        $style = $this->pickBest($imagePool, $usedStyleIds, $pillar, $keywords, 'image');
        $subject = $this->pickSubject($subjectPool, $usedSubjectIds);

        return [
            'writing_profile_id' => $tone?->id,
            'image_profile_id' => $style?->id,
            'subject_media_ids' => $subject->values()->all(),
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Resolve Pool văn phong của campaign (fallback toàn kho).
     * =====================================================================
     * Input: campaign.
     * Output: Collection<AiWritingProfile> enabled, trong pool hoặc toàn bộ.
     * Side effect: query DB; không ghi.
     * =====================================================================
     */
    public function writingPool(Campaign $campaign): Collection
    {
        $ids = collect((array) ($campaign->writing_profile_ids ?? []))->map('intval')->filter();
        $query = AiWritingProfile::query()->where('is_enabled', true);

        return $ids->isNotEmpty()
            ? $query->whereIn('id', $ids->all())->get()
            : $query->get();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Resolve Pool visual style của campaign (fallback toàn kho).
     * =====================================================================
     * Input: campaign.
     * Output: Collection<AiImageProfile> enabled, trong pool hoặc toàn bộ.
     * Side effect: query DB; không ghi.
     * =====================================================================
     */
    public function imagePool(Campaign $campaign): Collection
    {
        $ids = collect((array) ($campaign->image_profile_ids ?? []))->map('intval')->filter();
        $query = AiImageProfile::query()->where('is_enabled', true);

        return $ids->isNotEmpty()
            ? $query->whereIn('id', $ids->all())->get()
            : $query->get();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chọn profile điểm cao nhất, tránh trùng đã dùng trong tuần.
     * =====================================================================
     * Input: collection profile, ids đã dùng, pillar, keywords, loại.
     * Output: model tốt nhất hoặc null khi pool rỗng.
     * Side effect: chỉ đọc thuộc tính model; không query.
     * =====================================================================
     */
    private function pickBest(Collection $pool, array $usedIds, string $pillar, Collection $keywords, string $kind)
    {
        if ($pool->isEmpty()) {
            return null;
        }

        return $pool->map(function ($profile) use ($usedIds, $pillar, $keywords, $kind): array {
            $score = 0;
            $score += $this->pillarScore($profile, $pillar, $kind);          // Tầng 1: trụ cột (40)
            $score += $this->affinityScore($profile, $keywords, $kind);     // Tầng 2: tương thích (30)
            $score += $this->rotationScore($profile, $usedIds);            // Tầng 3: xoay vòng (30)

            return ['profile' => $profile, 'score' => $score];
        })
            ->sortByDesc('score')
            ->first()['profile'] ?? null;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tầng 1 — chấm điểm khớp trụ cột (tối đa 40).
     * =====================================================================
     * Input: profile, pillar, loại ('writing'|'image').
     * Output: int điểm.
     * Side effect: đọc taxonomy/category của profile.
     * =====================================================================
     */
    private function pillarScore($profile, string $pillar, string $kind): int
    {
        $map = $kind === 'writing' ? $this->writingPillarMap() : $this->imagePillarMap();
        $preferred = collect($map[$pillar] ?? []);

        $names = collect($profile->categories ?? [])->pluck('name')->map(fn ($n) => mb_strtolower((string) $n));
        if ($profile->relationLoaded('categories') === false && method_exists($profile, 'categories')) {
            $names = $profile->categories()->pluck('name')->map(fn ($n) => mb_strtolower((string) $n));
        }
        $category = mb_strtolower((string) ($profile->category ?? ''));

        foreach ($preferred as $pref) {
            if ($names->contains($pref) || $category === $pref) {
                return 40;
            }
        }

        return $preferred->isEmpty() ? 20 : 10;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tầng 2 — tương thích giọng/ảnh với keyword (tối đa 30).
     * =====================================================================
     * Input: profile, keywords lowercase, loại.
     * Output: int điểm theo số keyword trùng tag/keyword.
     * Side effect: đọc rules_json/dna_json/tags.
     * =====================================================================
     */
    private function affinityScore($profile, Collection $keywords, string $kind): int
    {
        $haystack = collect();
        $haystack = $haystack->merge(collect($profile->tags ?? [])->pluck('name'))
            ->merge(collect((array) data_get($profile, 'dna_json.keywords', [])))
            ->merge(collect((array) data_get($profile, 'rules_json.keywords', [])))
            ->push((string) ($profile->name ?? ''))
            ->map(fn ($v) => mb_strtolower((string) $v));

        $matched = $keywords->filter(fn ($k) => $haystack->contains(fn ($h) => str_contains($h, $k) || str_contains($k, $h)))->count();

        return min(30, $matched * 10);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tầng 3 — xoay vòng chống lặp (tối đa 30).
     * =====================================================================
     * Input: profile và ids đã dùng trong tuần.
     * Output: 30 nếu chưa dùng, 0 nếu đã dùng.
     * Side effect: hàm thuần.
     * =====================================================================
     */
    private function rotationScore($profile, array $usedIds): int
    {
        return in_array((int) $profile->id, array_map('intval', $usedIds), true) ? 0 : 30;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chọn 1–3 ảnh tham chiếu chủ thể, xoay vòng chưa dùng.
     * =====================================================================
     * Input: pool id ảnh của campaign và ids đã dùng.
     * Output: Collection<int> tối đa 3 id ưu tiên chưa dùng.
     * Side effect: hàm thuần.
     * =====================================================================
     */
    private function pickSubject(Collection $subjectPool, array $usedSubjectIds): Collection
    {
        if ($subjectPool->isEmpty()) {
            return collect();
        }
        $used = array_map('intval', $usedSubjectIds);
        $fresh = $subjectPool->reject(fn ($id) => in_array((int) $id, $used, true))->values();
        $chosen = $fresh->isNotEmpty() ? $fresh->take(3) : $subjectPool->take(3);

        return $chosen->map(fn ($id) => (int) $id);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Bản đồ trụ cột → category/tag ưu tiên cho văn phong.
     * =====================================================================
     * Input: không. Output: array<string, list<string>>.
     * =====================================================================
     */
    private function writingPillarMap(): array
    {
        return [
            'inspire' => ['cảm hứng', 'truyền cảm hứng', 'inspire', 'hài hước', 'thân thiện'],
            'knowledge' => ['kiến thức', 'chia sẻ kiến thức', 'chuyên nghiệp', 'knowledge'],
            'story' => ['câu chuyện', 'kể chuyện', 'story', 'thân thiện'],
            'sales' => ['bán hàng', 'sales', 'ưu đãi', 'khuyến mãi'],
            'engagement' => ['tương tác', 'engagement', 'minigame', 'hài hước'],
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Bản đồ trụ cột → category ưu tiên cho visual style.
     * =====================================================================
     * Input: không. Output: array<string, list<string>>.
     * =====================================================================
     */
    private function imagePillarMap(): array
    {
        return [
            'inspire' => ['sản phẩm', 'lifestyle', 'photorealistic', 'đời sống'],
            'knowledge' => ['hiện đại', 'minimal', 'studio', 'infographic'],
            'story' => ['nghệ thuật', 'cinematic', 'warm tone', 'hậu trường'],
            'sales' => ['đồ ăn', 'sản phẩm', 'thương mại', 'food'],
            'engagement' => ['nhân vật', 'vivid', 'lifestyle', 'minigame'],
        ];
    }
}
