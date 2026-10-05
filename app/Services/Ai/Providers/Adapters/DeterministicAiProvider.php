<?php

namespace App\Services\Ai\Providers\Adapters;

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
    /**
     * =====================================================================
     * CHỨC NĂNG: false để dùng fallback deterministic.
     * =====================================================================
     * INPUT: không có.
     * OUTPUT: false để dùng fallback deterministic.
     * SIDE EFFECT: Không ghi database hoặc gọi provider.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function configured(): bool
    {
        return false;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: provider identity deterministic.
     * =====================================================================
     * INPUT: không có.
     * OUTPUT: provider identity deterministic.
     * SIDE EFFECT: Không ghi database hoặc gọi provider.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function providerName(): string
    {
        return 'deterministic';
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: model identity deterministic.
     * =====================================================================
     * INPUT: không có.
     * OUTPUT: model identity deterministic.
     * SIDE EFFECT: Không ghi database hoặc gọi provider.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function modelName(): string
    {
        return 'deterministic';
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Bảo vệ boundary structured output của provider deterministic.
     * =====================================================================
     * INPUT: context structured output.
     * OUTPUT: không có vì configured() luôn false.
     * SIDE EFFECT: không gọi network hoặc ghi database.
     * EXCEPTION/TRANSACTION: LogicException nếu boundary bị gọi sai; không mở transaction.
     * =====================================================================
     *
     * @param  array<string, mixed>  $input
     */
    protected function requestPayload(array $input): mixed
    {
        throw new \LogicException('Deterministic provider không hỗ trợ request structured output.');
    }
}
