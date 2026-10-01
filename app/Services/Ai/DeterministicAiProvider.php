<?php

namespace App\Services\Ai;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Adapter deterministic không gọi provider bên ngoài.
 * =====================================================================
 *
 * Adapter này là lựa chọn rõ ràng cho pipeline extract/sanitize. Nó giữ
 * provenance `deterministic` và bảo đảm chọn provider deterministic không vô
 * tình gửi request tới endpoint AI_IMPORT đang cấu hình cho provider khác.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - configured(): luôn false để ArticleImportService giữ draft fallback.
 * - providerName(): trả identity deterministic.
 * - modelName(): trả model deterministic.
 * - requestPayload(): contract bắt buộc, không được gọi.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : không có network input.
 * - OUTPUT: trạng thái provider deterministic; không tạo structured output.
 * - SIDE EFFECT: không gọi network, không ghi database.
 * =====================================================================
 */
final class DeterministicAiProvider extends AbstractStructuredAiProvider
{
    /** Input: không có. Output: false để dùng fallback deterministic. */
    public function configured(): bool
    {
        return false;
    }

    /** Input: không có. Output: provider identity deterministic. */
    public function providerName(): string
    {
        return 'deterministic';
    }

    /** Input: không có. Output: model identity deterministic. */
    public function modelName(): string
    {
        return 'deterministic';
    }

    /**
     * Input: context structured output.
     * Output: không có vì configured() luôn false.
     * Exception: LogicException nếu boundary bị gọi sai.
     *
     * @param  array<string, mixed>  $input
     */
    protected function requestPayload(array $input): mixed
    {
        throw new \LogicException('Deterministic provider không hỗ trợ request structured output.');
    }
}
