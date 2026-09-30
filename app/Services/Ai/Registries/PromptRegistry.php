<?php

namespace App\Services\Ai\Registries;

use InvalidArgumentException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Registry prompt AI tập trung, có version và allowlist.
 * =====================================================================
 *
 * Prompt được cấu hình trong config/ai-agent.php để review bằng Git. Registry
 * không render hoặc gọi provider; nó chỉ đảm bảo task chỉ dùng prompt hợp lệ.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(), get(), all().
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : prompt key, target và operation từ AI request.
 * - OUTPUT: metadata prompt đã kiểm tra allowlist/version.
 * - SIDE EFFECT: chỉ đọc config; không gọi model hoặc ghi database.
 * =====================================================================
 */
final class PromptRegistry
{
    /** INPUT: không có. OUTPUT: registry instance. SIDE EFFECT: không có. EXCEPTION/TRANSACTION: không có. */
    public function __construct() {}

    /**
     * INPUT: prompt key, target, operation. OUTPUT: metadata prompt hợp lệ.
     * SIDE EFFECT: chỉ đọc config. EXCEPTION/TRANSACTION: InvalidArgumentException; không transaction.
     *
     * @return array<string, mixed>
     */
    public function get(string $key, ?string $target = null, ?string $operation = null): array
    {
        $prompt = $this->all()[$key] ?? null;
        if (! is_array($prompt)) {
            throw new InvalidArgumentException("AI prompt [{$key}] chưa được đăng ký.");
        }

        if ($target !== null && ! in_array($target, $prompt['allowed_targets'] ?? [], true)) {
            throw new InvalidArgumentException("AI prompt [{$key}] không hỗ trợ target [{$target}].");
        }
        if ($operation !== null && ! in_array($operation, $prompt['allowed_operations'] ?? [], true)) {
            throw new InvalidArgumentException("AI prompt [{$key}] không hỗ trợ operation [{$operation}].");
        }

        return $prompt + ['key' => $key];
    }

    /** INPUT: không có. OUTPUT: toàn bộ prompt metadata. SIDE EFFECT: đọc config. EXCEPTION/TRANSACTION: không có. @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return (array) config('ai-agent.prompts', []);
    }
}
