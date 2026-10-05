<?php

namespace App\Services\Ai\Data;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: DTO phản hồi AI đã kiểm contract của nhiệm vụ.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: __construct().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : output canonical và diagnostics an toàn.
 * - OUTPUT: response bất biến, không trộn metadata vào candidate Post.
 * =====================================================================
 */
final readonly class AiTaskResponse
{
    /**
     * =====================================================================
     * Input: output đã validate và diagnostics theo allowlist.
     * Output: DTO bất biến; không có side effect.
     * =====================================================================
     */
    public function __construct(public array $output, public array $diagnostics = []) {}
}
