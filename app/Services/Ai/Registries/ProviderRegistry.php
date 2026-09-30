<?php

namespace App\Services\Ai\Registries;

use App\Services\Ai\Contracts\AiProviderContract;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Registry provider/model AI được phép sử dụng.
 * =====================================================================
 *
 * API key và endpoint chỉ được đọc ở config. Registry không expose secret ra
 * response; frontend chỉ nhận label, logo và model đã allowlist.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(), get(), resolve(), publicOptions().
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : provider key và service container.
 * - OUTPUT: adapter nội bộ hoặc metadata public không chứa secret.
 * - SIDE EFFECT: resolve có thể khởi tạo provider; không mở transaction.
 * =====================================================================
 */
final class ProviderRegistry
{
    /** INPUT: container. OUTPUT: registry instance. SIDE EFFECT: giữ dependency. EXCEPTION/TRANSACTION: không có. */
    public function __construct(private readonly Container $container) {}

    /** INPUT: provider key. OUTPUT: metadata không secret. SIDE EFFECT: đọc config. EXCEPTION/TRANSACTION: InvalidArgumentException; không transaction. @return array<string, mixed> */
    public function get(string $key): array
    {
        $provider = config("ai-agent.providers.{$key}");
        if (! is_array($provider) || ($provider['enabled'] ?? false) !== true) {
            throw new InvalidArgumentException("AI provider [{$key}] chưa được bật.");
        }

        return $provider + ['key' => $key];
    }

    /** INPUT: provider key. OUTPUT: provider adapter. SIDE EFFECT: resolve container. EXCEPTION/TRANSACTION: InvalidArgumentException; không transaction. */
    public function resolve(string $key): AiProviderContract
    {
        $class = $this->get($key)['adapter'] ?? null;
        if (! is_string($class) || ! is_a($class, AiProviderContract::class, true)) {
            throw new InvalidArgumentException("AI provider [{$key}] chưa khai báo adapter hợp lệ.");
        }

        return $this->container->make($class);
    }

    /** INPUT: không có. OUTPUT: public options. SIDE EFFECT: loại secret. EXCEPTION/TRANSACTION: không có. @return array<string, array<string, mixed>> */
    public function publicOptions(): array
    {
        return collect((array) config('ai-agent.providers', []))
            ->filter(fn (array $provider): bool => ($provider['enabled'] ?? false) === true)
            ->map(fn (array $provider, string $key): array => [
                'key' => $key,
                'label' => $provider['label'] ?? $key,
                'logo' => $provider['logo'] ?? null,
                'models' => array_values($provider['models'] ?? []),
            ])
            ->values()
            ->all();
    }
}
