<?php

namespace App\Services\Marketing;

use App\Models\Campaign;
use App\Models\DailyContentPlan;
use Carbon\CarbonImmutable;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Lên dàn ý nội dung tuần cho các chiến dịch đang chạy.
 * =====================================================================
 * WeeklyContentKeyPlannerService quét các Campaign active giao với tuần mục
 * tiêu, phân bổ key nội dung (pillar/topic/keywords/angle) theo story arc
 * 3 giai đoạn và nhịp ngày trong tuần; chỉ trả về slot của ngày nằm trong
 * thời lượng chiến dịch.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - planWeek(): sinh bộ slot nội dung cho tuần (Thứ 2 -> Chủ Nhật).
 * - activeCampaigns(): lấy campaign active có thời lượng giao tuần.
 * - pillarForDate(): trụ cột theo nhịp ngày trong tuần.
 * - stageForDate(): giai đoạn chiến dịch theo tiến độ ngày.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : tuần mục tiêu (tuần bắt đầu Thứ 2).
 * - OUTPUT: list<array> slot DailyContentPlan đã phân bổ cho từng campaign.
 * - SIDE EFFECT: chỉ đọc campaign/settings; không ghi database.
 * =====================================================================
 */
final class WeeklyContentKeyPlannerService
{
    /**
     * Nhịp ngày trong tuần → trụ cột mặc định theo plan §4.2.
     * Key: 1=Thứ 2 ... 7=Chủ Nhật (Carbon dayOfWeekIso).
     */
    private const PILLAR_BY_WEEKDAY = [
        1 => 'inspire', 2 => 'inspire', 3 => 'knowledge',
        4 => 'story', 5 => 'sales', 6 => 'sales', 7 => 'engagement',
    ];

    /**
     * Giờ vàng gợi ý theo nhịp ngày (plan §4.2).
     */
    private const GOLDEN_SLOT_BY_WEEKDAY = [
        1 => '08:00', 2 => '08:00', 3 => '12:00',
        4 => '19:30', 5 => '17:30', 6 => '17:30', 7 => '09:00',
    ];

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy các campaign active có thời lượng giao tuần mục tiêu.
     * =====================================================================
     * Input: tuần bắt đầu (CarbonImmutable) và kết thúc.
     * Output: collection<Campaign> đang active và giao khoảng thời gian.
     * Side effect: query DB; không ghi.
     * =====================================================================
     */
    public function activeCampaigns(CarbonImmutable $weekStart, CarbonImmutable $weekEnd)
    {
        return Campaign::query()
            ->active()
            ->where(function ($query) use ($weekStart, $weekEnd): void {
                $query->where(function ($q) use ($weekStart, $weekEnd): void {
                    // starts_at <= weekEnd AND (ends_at null OR ends_at >= weekStart)
                    $q->where('starts_at', '<=', $weekEnd->endOfDay())
                        ->where(function ($qq) use ($weekStart): void {
                            $qq->whereNull('ends_at')->orWhere('ends_at', '>=', $weekStart->startOfDay());
                        });
                });
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Sinh bộ slot nội dung tuần cho một campaign.
     * =====================================================================
     * Input: campaign đã active và tuần bắt đầu/kết thúc.
     * Output: list<array{planned_date, slot, pillar_type, topic_title,
     *   keywords_json, content_angle, subject_media_ids, subject_description,
     *   writing_profile_id, image_profile_id, image_count, scheduled_at}>
     *   chỉ gồm ngày nằm trong thời lượng campaign.
     * Side effect: chỉ đọc cấu hình campaign; không ghi.
     * =====================================================================
     */
    public function planWeekForCampaign(Campaign $campaign, CarbonImmutable $weekStart, CarbonImmutable $weekEnd): array
    {
        $start = $campaign->starts_at?->toImmutable();
        $end = $campaign->ends_at?->toImmutable();
        if (! $start) {
            return [];
        }

        $slots = [];
        $schedule = (array) ($campaign->schedule_config_json ?? []);
        $keywords = (array) ($campaign->keywords_json ?? []);
        $weekdays = (array) data_get($schedule, 'active_weekdays', []);
        $timeSlots = (array) data_get($schedule, 'time_slots', []);
        $imageCount = max(3, min(5, (int) data_get($schedule, 'image_count', 4)));
        $mode = (string) data_get($schedule, 'mode', 'weekdays');
        $dailyCount = max(1, min(5, (int) data_get($schedule, 'daily_post_count', 1)));

        for ($day = $weekStart; $day->lte($weekEnd); $day = $day->addDay()) {
            // Chỉ xử lý ngày nằm trong thời lượng campaign.
            if ($day->lt($start->startOfDay()) || ($end && $day->gt($end->endOfDay()))) {
                continue;
            }

            $weekdayIso = $day->dayOfWeekIso;
            $daySlots = $this->slotsForDay($mode, $weekdayIso, $weekdays, $timeSlots, $dailyCount);

            foreach ($daySlots as $index => $time) {
                $stage = $this->stageForDate($start, $end, $day);
                $pillar = $this->pillarForDate($weekdayIso, $stage);
                $topic = $this->topicFor($campaign, $pillar, $stage, $day);
                $angle = $this->angleFor($campaign, $pillar, $day);

                $slots[] = [
                    'planned_date' => $day->toDateString(),
                    'slot' => $index + 1,
                    'pillar_type' => $pillar,
                    'topic_title' => $topic,
                    'keywords_json' => $keywords,
                    'content_angle' => $angle,
                    'subject_media_ids' => (array) ($campaign->subject_media_ids ?? []),
                    'subject_description' => $campaign->subject_description,
                    'writing_profile_id' => null,
                    'image_profile_id' => null,
                    'image_count' => $imageCount,
                    'scheduled_at' => $this->scheduledAt($day, $time),
                ];
            }
        }

        return $slots;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Gộp slot của tất cả campaign active trong tuần.
     * =====================================================================
     * Input: tuần bắt đầu (Thứ 2) và kết thúc (Chủ Nhật).
     * Output: list<array> slot kèm campaign_id, sẵn sàng ghi DailyContentPlan.
     * Side effect: query campaign; không ghi.
     * =====================================================================
     */
    public function planWeek(CarbonImmutable $weekStart, CarbonImmutable $weekEnd): array
    {
        $campaigns = $this->activeCampaigns($weekStart, $weekEnd);
        if ($campaigns->isEmpty()) {
            return [];
        }

        $all = [];
        foreach ($campaigns as $campaign) {
            foreach ($this->planWeekForCampaign($campaign, $weekStart, $weekEnd) as $slot) {
                $slot['campaign_id'] = (int) $campaign->getKey();
                $all[] = $slot;
            }
        }

        return $all;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Xác định các slot giờ đăng của một ngày theo chế độ campaign.
     * =====================================================================
     * Input: mode (weekdays|daily), iso weekday, cấu hình ngày/khung giờ, đếm ngày.
     * Output: list<string> giờ "HH:MM" cần lên bài trong ngày đó.
     * Side effect: hàm thuần; không đọc/ghi.
     * =====================================================================
     */
    private function slotsForDay(string $mode, int $weekdayIso, array $weekdays, array $timeSlots, int $dailyCount): array
    {
        if ($mode === 'daily') {
            $times = $timeSlots !== [] ? $timeSlots : [self::GOLDEN_SLOT_BY_WEEKDAY[$weekdayIso]];

            return array_slice(array_values($times), 0, $dailyCount);
        }

        // Chế độ weekdays: chỉ ngày nằm trong active_weekdays mới có slot.
        $key = strtolower(CarbonImmutable::create(2024, 1, $weekdayIso)->format('D')); // mon..sun
        $config = collect($weekdays)->first(function ($day) use ($weekdayIso, $key): bool {
            return (int) ($day['iso'] ?? 0) === $weekdayIso
                || in_array(($day['key'] ?? ''), [$key], true);
        });

        if (! $config || ! ($config['enabled'] ?? false)) {
            return [];
        }

        $slots = (array) ($config['slots'] ?? []);

        return $slots !== [] ? array_values($slots) : [self::GOLDEN_SLOT_BY_WEEKDAY[$weekdayIso]];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chọn trụ cột ngày theo nhịp tuần, ưu tiên giai đoạn chiến dịch.
     * =====================================================================
     * Input: iso weekday và giai đoạn (open|mid|close).
     * Output: pillar_type string.
     * Side effect: hàm thuần.
     * =====================================================================
     */
    private function pillarForDate(int $weekdayIso, string $stage): string
    {
        // Ưu tiên trụ cột theo giai đoạn khi có override mạnh.
        if ($stage === 'close') {
            return $weekdayIso === 7 ? 'engagement' : 'sales';
        }
        if ($stage === 'open') {
            return $weekdayIso <= 2 ? 'inspire' : 'story';
        }

        return self::PILLAR_BY_WEEKDAY[$weekdayIso] ?? 'inspire';
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Xác định giai đoạn 25/50/25 của chiến dịch theo ngày.
     * =====================================================================
     * Input: ngày bắt đầu/kết thúc campaign và ngày cần xét.
     * Output: 'open'|'mid'|'close'.
     * Side effect: hàm thuần; nếu ends_at null coi như chạy dài.
     * =====================================================================
     */
    private function stageForDate(CarbonImmutable $start, ?CarbonImmutable $end, CarbonImmutable $date): string
    {
        if (! $end) {
            return 'mid';
        }
        $total = max(1, $start->diffInDays($end) + 1);
        $elapsed = $start->diffInDays($date) + 1;
        $ratio = $elapsed / $total;

        return $ratio <= 0.25 ? 'open' : ($ratio <= 0.75 ? 'mid' : 'close');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Dựng topic_title ngắn gọn theo trụ cột + từ khóa chiến dịch.
     * =====================================================================
     * Input: campaign, pillar, stage, ngày.
     * Output: string tiêu đề chủ đề ngày.
     * Side effect: hàm thuần.
     * =====================================================================
     */
    private function topicFor(Campaign $campaign, string $pillar, string $stage, CarbonImmutable $date): string
    {
        $kw = collect((array) ($campaign->keywords_json ?? []))->first() ?? $campaign->name;
        $prefix = match ($pillar) {
            'inspire' => 'Cảm hứng',
            'knowledge' => 'Kiến thức',
            'story' => 'Câu chuyện',
            'sales' => 'Ưu đãi',
            'engagement' => 'Tương tác',
            default => 'Nội dung',
        };

        return sprintf('%s — %s (tuần %s)', $prefix, $kw, $date->isoWeek());
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Dựng content_angle hướng dẫn AI viết theo trụ cột + thông điệp.
     * =====================================================================
     * Input: campaign, pillar, ngày.
     * Output: string dàn ý gợi ý.
     * Side effect: hàm thuần; đọc message/objective của campaign.
     * =====================================================================
     */
    private function angleFor(Campaign $campaign, string $pillar, CarbonImmutable $date): string
    {
        $message = trim((string) $campaign->message);
        $objective = trim((string) $campaign->objective);
        $kw = collect((array) ($campaign->keywords_json ?? []))->implode(', ');

        return match ($pillar) {
            'inspire' => "Gắn {$kw} với năng lượng đầu tuần và cảm hứng trải nghiệm. {$message}",
            'knowledge' => "Khai thác {$kw} dưới dạng mẹo/chi tiết sản phẩm. {$message}",
            'story' => "Kể câu chuyện hậu trường / trải nghiệm thực tế xoay quanh {$kw}. {$message}",
            'sales' => "Tập trung ưu đãi/lợi ích/CTA chốt đơn của {$kw}. {$objective} {$message}",
            'engagement' => "Biến {$kw} thành câu hỏi/bình chọn/minigame cuối tuần. {$message}",
            default => "Nội dung về {$kw}. {$message}",
        };
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Gộp ngày + giờ "HH:MM" thành timestamp scheduled_at.
     * =====================================================================
     * Input: ngày và chuỗi giờ.
     * Output: CarbonImmutable scheduled_at theo timezone campaign.
     * Side effect: hàm thuần.
     * =====================================================================
     */
    private function scheduledAt(CarbonImmutable $date, string $time): CarbonImmutable
    {
        $t = preg_match('/^\d{1,2}:\d{2}/', $time) ? $time : '09:00';

        return CarbonImmutable::parse($date->toDateString().' '.$t, 'Asia/Ho_Chi_Minh');
    }
}
