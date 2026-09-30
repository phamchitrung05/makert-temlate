<?php

namespace App\Services\Ai\Registries;

use InvalidArgumentException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Registry schema output chuẩn hóa của AI Agent.
 * =====================================================================
 *
 * Schema được version hóa để kết quả cũ vẫn truy nguyên được khi prompt hoặc
 * adapter thay đổi. Registry chỉ trả metadata; validation chi tiết vẫn thuộc
 * provider/target adapter trước khi apply.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - get(), all().
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : schema key từ prompt registry hoặc request.
 * - OUTPUT: fields/version metadata đã đăng ký.
 * - SIDE EFFECT: chỉ đọc config; không ghi database hoặc gọi provider.
 * =====================================================================
 */
final class SchemaRegistry
{
    /** INPUT: schema key. OUTPUT: schema metadata versioned. SIDE EFFECT: đọc config. EXCEPTION/TRANSACTION: InvalidArgumentException; không transaction. @return array<string, mixed> */
    public function get(string $key): array
    {
        $schema = $this->all()[$key] ?? null;
        if (! is_array($schema)) {
            throw new InvalidArgumentException("AI schema [{$key}] chưa được đăng ký.");
        }

        return $schema + ['key' => $key];
    }

    /** INPUT: không có. OUTPUT: toàn bộ schema metadata. SIDE EFFECT: đọc config. EXCEPTION/TRANSACTION: không có. @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return (array) config('ai-agent.schemas', []);
    }
}
