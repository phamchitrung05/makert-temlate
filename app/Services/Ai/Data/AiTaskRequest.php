<?php

namespace App\Services\Ai\Data;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: DTO yêu cầu AI theo nhiệm vụ, độc lập field nghiệp vụ Post.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: __construct().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : task, chỉ dẫn, dữ liệu, JSON Schema và options đã snapshot.
 * - OUTPUT: request bất biến; không gọi HTTP hoặc ghi database.
 * =====================================================================
 */
final readonly class AiTaskRequest
{
    /**
     * =====================================================================
     * Input: tên task, systemInstructions, input, schema và metadata/options.
     * Output: DTO bất biến; không có side effect.
     * =====================================================================
     */
    public function __construct(
        public string $task,
        public string $systemInstructions,
        public array $input,
        public array $schema,
        public array $options = [],
    ) {}
}
