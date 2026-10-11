<?php

namespace App\Services\Ai\Contracts;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Khai báo contract metadata an toàn từ provider AI.
 * =====================================================================
 * Metadata chỉ chứa thông tin đã allowlist, tách khỏi output sinh ra và
 * không được chứa raw response hoặc credential.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - responseMetadata(): trả metadata provider đã được lọc.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : trạng thái response nội bộ của provider implement contract.
 * - OUTPUT: array metadata an toàn; không quy định side effect.
 * =====================================================================
 */
interface AiResponseMetadataProvider
{
    /**
     * Trả metadata allowlist của response provider gần nhất.
     *
     * Input: không có tham số; provider đã lưu trạng thái nội bộ.
     * Output: array metadata không chứa raw response hoặc credential.
     */
    public function responseMetadata(): array;
}
