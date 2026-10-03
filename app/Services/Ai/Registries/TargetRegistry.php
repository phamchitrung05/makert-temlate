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
 * - __construct(), get(), adapter(), all(), outputOptions().
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : target key và service container.
 * - OUTPUT: capability hoặc adapter đã allowlist.
 * - SIDE EFFECT: resolve có thể khởi tạo adapter; không mở transaction.
 * =====================================================================
 */
final class TargetRegistry
{
    /**
     * =====================================================================
     * CHỨC NĂNG: registry instance.
     * =====================================================================
     * INPUT: container.
     * OUTPUT: registry instance.
     * SIDE EFFECT: giữ dependency.
     * EXCEPTION/TRANSACTION: không có.
     * =====================================================================
     */
    public function __construct(private readonly Container $container) {}

    /**
     * =====================================================================
     * CHỨC NĂNG: Resolve metadata target đã allowlist.
     * =====================================================================
     * INPUT: target key.
     * OUTPUT: capability metadata.
     * SIDE EFFECT: đọc config; không gọi provider.
     * EXCEPTION/TRANSACTION: InvalidArgumentException; không mở transaction.
     *
     * @return array<string, mixed>
     * =====================================================================
     */
    public function get(string $key): array
    {
        $target = config("ai-agent.targets.{$key}");
        if (! is_array($target) || ($target['enabled'] ?? false) !== true) {
            throw new InvalidArgumentException("AI target [{$key}] chưa được bật.");
        }

        return $target + ['key' => $key];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: target adapter.
     * =====================================================================
     * INPUT: target key.
     * OUTPUT: target adapter.
     * SIDE EFFECT: resolve container.
     * EXCEPTION/TRANSACTION: InvalidArgumentException; không transaction.
     * =====================================================================
     */
    public function adapter(string $key): AiTargetAdapterContract
    {
        $class = $this->get($key)['adapter'] ?? null;
        if (! is_string($class) || ! is_a($class, AiTargetAdapterContract::class, true)) {
            throw new InvalidArgumentException("AI target [{$key}] chưa khai báo adapter hợp lệ.");
        }

        return $this->container->make($class);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả toàn bộ target đang bật.
     * =====================================================================
     * INPUT: không có.
     * OUTPUT: target metadata theo key.
     * SIDE EFFECT: đọc config; không gọi provider.
     * EXCEPTION/TRANSACTION: không có; không mở transaction.
     *
     * @return array<string, array<string, mixed>>
     * =====================================================================
     */
    public function all(): array
    {
        return collect((array) config('ai-agent.targets', []))
            ->filter(fn (array $target): bool => ($target['enabled'] ?? false) === true)
            ->map(fn (array $target, string $key): array => $target + ['key' => $key])
            ->all();
    }

    /** Nhãn select lấy từ config; chỉ trả nhóm đầu ra tài nguyên cho phép. */
    public function outputOptions(string $key): array
    {
        $target = $this->get($key);
        $definitions = (array) config('ai-agent.output_definitions', []);

        return collect($target['outputs'] ?? [])
            ->filter(fn (string $output): bool => isset($definitions[$output]))
            ->map(fn (string $output): array => [
                'value' => $output, 'title' => $definitions[$output]['label'] ?? $output,
                'source_types' => array_values($definitions[$output]['source_types'] ?? []),
            ])->values()->all();
    }
}
