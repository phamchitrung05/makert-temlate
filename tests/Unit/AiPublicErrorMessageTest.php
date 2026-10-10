<?php

namespace Tests\Unit;

use App\Services\Ai\Errors\AiPublicErrorMessage;
use Tests\TestCase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử allowlist lỗi AI hiển thị cho người dùng.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - test_quality_reason_has_priority_over_generic_code(): ưu tiên lý do cụ thể.
 * - test_known_code_returns_vietnamese_guidance(): diễn giải mã lỗi kỹ thuật.
 * - test_safe_bounded_message_is_preserved(): giữ message domain đã bounded.
 * - test_unknown_code_uses_task_fallback(): fallback theo loại task.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): mã/lý do lỗi -> câu tiếng Việt an toàn.
 * SIDE EFFECT: không gọi provider, không ghi database hoặc mở transaction.
 * EXCEPTION/TRANSACTION: không có exception nghiệp vụ ngoài assertion PHPUnit.
 * =====================================================================
 */
final class AiPublicErrorMessageTest extends TestCase
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Bảo đảm lỗi mất code được giải thích cụ thể.
     * =====================================================================
     * INPUT: quality code chung và validation reason source_code_changed.
     * OUTPUT: thông báo tiếng Việt nói rõ code nguồn bị thay đổi.
     * SIDE EFFECT: không có.
     * EXCEPTION/TRANSACTION: không có.
     * =====================================================================
     */
    public function test_quality_reason_has_priority_over_generic_code(): void
    {
        $message = AiPublicErrorMessage::message('AI_QUALITY_GROUNDING', null, 'article_generation', [
            ['reason' => 'source_code_changed'],
        ]);

        $this->assertStringContainsString('mất hoặc thay đổi đoạn mã', $message);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra allowlist diễn giải mã provider.
     * =====================================================================
     * INPUT: AI_PROVIDER_TIMEOUT.
     * OUTPUT: hướng dẫn thử lại/chọn model bằng tiếng Việt.
     * SIDE EFFECT: không có.
     * EXCEPTION/TRANSACTION: không có.
     * =====================================================================
     */
    public function test_known_code_returns_vietnamese_guidance(): void
    {
        $message = AiPublicErrorMessage::message('AI_PROVIDER_TIMEOUT');

        $this->assertStringContainsString('phản hồi quá lâu', $message);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Giữ lỗi domain đã được worker giới hạn độ dài.
     * =====================================================================
     * INPUT: message bounded không phải mã kỹ thuật.
     * OUTPUT: giữ nguyên câu lỗi để không làm mất ngữ cảnh nghiệp vụ.
     * SIDE EFFECT: không có.
     * EXCEPTION/TRANSACTION: không có.
     * =====================================================================
     */
    public function test_safe_bounded_message_is_preserved(): void
    {
        $this->assertSame(
            'Không đọc được nguồn',
            AiPublicErrorMessage::message('SAFE_FAILURE', 'Không đọc được nguồn')
        );
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chọn fallback khi tracker chỉ có mã lạ.
     * =====================================================================
     * INPUT: mã không nằm trong allowlist và task image_generation.
     * OUTPUT: thông báo lỗi tạo ảnh tiếng Việt.
     * SIDE EFFECT: không có.
     * EXCEPTION/TRANSACTION: không có.
     * =====================================================================
     */
    public function test_unknown_code_uses_task_fallback(): void
    {
        $this->assertSame(
            'Không thể tạo ảnh AI. Hãy kiểm tra model ảnh và thử lại.',
            AiPublicErrorMessage::message('UNKNOWN_PROVIDER_ERROR', null, 'image_generation')
        );
    }
}
