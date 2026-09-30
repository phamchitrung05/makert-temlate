<?php

namespace App\Services\Ai\Registries;

use App\Services\Ai\Contracts\AiTargetAdapterContract;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Registry target được phép sử dụng AI.
 * =====================================================================
 *
 * Registry là nguồn capability duy nhất cho backend và frontend. Adapter chỉ
 * được resolve sau khi target đã được bật trong config, tránh gọi tùy ý class.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(), get(), adapter(), all().
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : target key và service container.
 * - OUTPUT: capability hoặc adapter đã allowlist.
 * - SIDE EFFECT: resolve có thể khởi tạo adapter; không mở transaction.
 * =====================================================================
 */
final class TargetRegistry
{
    /** INPUT: container. OUTPUT: registry instance. SIDE EFFECT: giữ dependency. EXCEPTION/TRANSACTION: không có. */
    public function __construct(private readonly Container $container) {}

    /** INPUT: target key. OUTPUT: capability. SIDE EFFECT: đọc config. EXCEPTION/TRANSACTION: InvalidArgumentException; không transaction. @return array<string, mixed> */
    public function get(string $key): array
    {
        $target = config("ai-agent.targets.{$key}");
        if (! is_array($target) || ($target['enabled'] ?? false) !== true) {
            throw new InvalidArgumentException("AI target [{$key}] chưa được bật.");
        }

        return $target + ['key' => $key];
    }

    /** INPUT: target key. OUTPUT: target adapter. SIDE EFFECT: resolve container. EXCEPTION/TRANSACTION: InvalidArgumentException; không transaction. */
    public function adapter(string $key): AiTargetAdapterContract
    {
        $class = $this->get($key)['adapter'] ?? null;
        if (! is_string($class) || ! is_a($class, AiTargetAdapterContract::class, true)) {
            throw new InvalidArgumentException("AI target [{$key}] chưa khai báo adapter hợp lệ.");
        }

        return $this->container->make($class);
    }

    /** INPUT: không có. OUTPUT: target đang bật. SIDE EFFECT: đọc config. EXCEPTION/TRANSACTION: không có. @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return collect((array) config('ai-agent.targets', []))
            ->filter(fn (array $target): bool => ($target['enabled'] ?? false) === true)
            ->map(fn (array $target, string $key): array => $target + ['key' => $key])
            ->all();
    }
}
