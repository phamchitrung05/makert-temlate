<?php

namespace Tests\Unit;

use App\Services\Marketing\FacebookContent;
use PHPUnit\Framework\TestCase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm contract nội dung chung cho lifecycle/worker/publisher.
 * CÁC HÀM/METHOD TRONG FILE:
 * - test_caption_includes_contact_and_ignores_blank_blocks(): kiểm nội dung hiển thị.
 * - test_legacy_input_and_output_limits_are_preserved(): kiểm tương thích và giới hạn.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): content blocks -> caption đầy đủ, bounded.
 * =====================================================================
 */
final class FacebookContentTest extends TestCase
{
    /** Input: content có contact và các mục rỗng. Output: caption giữ thứ tự, không sinh bullet/hashtag rỗng. */
    public function test_caption_includes_contact_and_ignores_blank_blocks(): void
    {
        $caption = FacebookContent::caption([
            'body' => ' Bài viết ', 'bullet_points' => [' ', 'Điểm nổi bật'], 'cta' => 'Liên hệ ngay',
            'contact' => ['phone' => ' 0901234 '], 'hashtags' => [' ', 'demo', '#test'],
        ]);

        $this->assertSame("Bài viết\n\n✅ Điểm nổi bật\n\nLiên hệ ngay\n\nPhone: 0901234\n\n#demo #test", $caption);
    }

    /** Input: caption cũ và content quá giới hạn. Output: tương thích legacy, tối đa 8 bullet/12 hashtag/5.000 ký tự. */
    public function test_legacy_input_and_output_limits_are_preserved(): void
    {
        $content = FacebookContent::normalize([
            'caption' => 'Legacy caption', 'bullet_points' => range(1, 20), 'hashtags' => range(1, 20),
        ]);

        $this->assertSame('Legacy caption', $content['body']);
        $this->assertCount(8, $content['bullet_points']);
        $this->assertCount(12, $content['hashtags']);
        $this->assertSame(5000, mb_strlen(FacebookContent::caption(['body' => str_repeat('á', 6000)])));
        $this->assertSame('', FacebookContent::caption([]));
    }
}
