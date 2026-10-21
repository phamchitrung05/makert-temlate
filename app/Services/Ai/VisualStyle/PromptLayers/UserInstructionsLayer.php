<?php

namespace App\Services\Ai\VisualStyle\PromptLayers;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Layer nối ghi chú tự do của admin vào prompt.
 * =====================================================================
 *
 * `VisualPromptContext->instructions` là mảng các dòng ghi chú admin nhập tay
 * (ví dụ "emphasize the gold cap", "seasonal winter theme"). Layer nối chúng
 * lại thành một mệnh đề sau các layer kỹ thuật. Mỗi dòng được bound tối đa
 * 500 ký tự để tránh admin dán khối text lớn làm phình prompt; dòng rỗng bị
 * bỏ. Trả `null` khi không có instruction nào hợp lệ.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - key(): 'user_instructions'.
 * - render(VisualPromptContext): render instructions → câu hoặc null.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : VisualPromptContext->instructions (array<string>).
 * - OUTPUT: chuỗi prompt tiếng Anh hoặc null; không gọi provider/DB.
 * =====================================================================
 */
final class UserInstructionsLayer implements PromptLayerContract
{
    /**
     * Giới hạn ký tự tối đa cho MỘT dòng instruction, tránh prompt bị phình
     * khi admin dán text dài. Cắt cứng ở ranh giới, không cố giữ nguyên câu.
     */
    private const MAX_INSTRUCTION_LENGTH = 500;

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả key registry 'user_instructions'.
     * =====================================================================
     * INPUT: không có. OUTPUT: string 'user_instructions'.
     * SIDE EFFECT/EXCEPTION: không có.
     * =====================================================================
     */
    public function key(): string
    {
        return 'user_instructions';
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Render các dòng instruction thành một câu "Additional notes".
     * =====================================================================
     * INPUT:
     * - $context: VisualPromptContext; đọc `$context->instructions`.
     * OUTPUT:
     * - ?string: "Additional notes: <line 1>; <line 2>."; null khi instructions
     *   rỗng sau lọc.
     * SIDE EFFECT: không gọi provider, không ghi DB.
     * EXCEPTION/TRANSACTION: không có.
     * =====================================================================
     */
    public function render(VisualPromptContext $context): ?string
    {
        $instructions = $context->instructions;
        if (! is_array($instructions) || $instructions === []) {
            return null;
        }

        $lines = [];
        foreach ($instructions as $line) {
            if (! is_string($line)) {
                continue;
            }
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // Bound từng dòng, cắt cứng không cố giữ câu — đơn giản và an toàn.
            if (mb_strlen($line) > self::MAX_INSTRUCTION_LENGTH) {
                $line = mb_substr($line, 0, self::MAX_INSTRUCTION_LENGTH);
            }
            $lines[] = rtrim($line, '.');
        }

        if ($lines === []) {
            return null;
        }

        return 'Additional notes: '.implode('; ', $lines).'.';
    }
}
