<?php

namespace App\Services\Marketing;

use Illuminate\Support\Str;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Chuẩn hóa và ghép nội dung Facebook tại một nơi dùng chung.
 * =====================================================================
 * Lifecycle, AI worker và publisher dùng cùng cách giới hạn field và dựng
 * caption để nội dung đã lưu khớp với nội dung được gửi lên Page.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - normalize(): chuẩn hóa content blocks, giữ tương thích caption cũ.
 * - caption(): ghép body, bullet, CTA, contact và hashtag thành caption.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): content array -> content/caption bounded.
 * Không ghi database, gọi provider hoặc thay đổi input.
 * =====================================================================
 */
final class FacebookContent
{
    /** Input: content Facebook hoặc caption legacy. Output: các field hợp lệ, số phần tử bị giới hạn. */
    public static function normalize(array $content): array
    {
        return [
            'title' => $content['title'] ?? null,
            'body' => trim((string) ($content['body'] ?? $content['caption'] ?? '')),
            'bullet_points' => array_values(array_slice(array_map('strval', (array) ($content['bullet_points'] ?? [])), 0, 8)),
            'cta' => $content['cta'] ?? null,
            'contact' => [
                'address' => data_get($content, 'contact.address'),
                'phone' => data_get($content, 'contact.phone'),
                'website' => data_get($content, 'contact.website'),
            ],
            'hashtags' => array_values(array_slice(array_map('strval', (array) ($content['hashtags'] ?? [])), 0, 12)),
            'link_url' => $content['link_url'] ?? null,
        ];
    }

    /** Input: content đã lưu hoặc output AI. Output: caption tối đa 5.000 ký tự, không có đoạn rỗng. */
    public static function caption(array $content): string
    {
        $content = self::normalize($content);
        $parts = [$content['body']];
        $parts[] = collect($content['bullet_points'])->map(fn (string $item): string => trim($item))
            ->filter()->map(fn (string $item): string => '✅ '.$item)->implode("\n");
        $parts[] = trim((string) $content['cta']);
        $parts[] = collect($content['contact'])
            ->map(fn ($value, $key): string => filled($value) ? ucfirst($key).': '.trim((string) $value) : '')
            ->filter()->implode("\n");
        $parts[] = collect($content['hashtags'])->map(fn (string $item): string => trim($item))->filter()
            ->map(fn (string $item): string => str_starts_with($item, '#') ? $item : '#'.$item)->implode(' ');

        return Str::limit(implode("\n\n", array_filter($parts)), 5000, '');
    }
}
