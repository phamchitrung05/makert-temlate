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
     * - __construct(), get(), resolve(), adapter(), all(), platforms(), outputOptions().
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
     *                              =====================================================================
     */
    public function get(string $key): array
    {
        $target = config("ai.agent.targets.{$key}");
        if (! is_array($target) || ($target['enabled'] ?? false) !== true) {
            throw new InvalidArgumentException("AI target [{$key}] chưa được bật.");
        }

        return $target + ['key' => $key];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Resolve capability theo platform và output profile.
     * =====================================================================
     * INPUT: target key, platform tùy chọn.
     * OUTPUT: capability đã hợp nhất với profile platform; không trả config
     * nội bộ không cần cho frontend.
     * SIDE EFFECT: chỉ đọc config; không gọi provider hoặc ghi database.
     * EXCEPTION/TRANSACTION: InvalidArgumentException khi platform chưa bật.
     * =====================================================================
     */
    public function resolve(string $key, ?string $platform = null): array
    {
        $target = $this->get($key);
        $profiles = (array) ($target['platform_profiles'] ?? []);
        if ($profiles === []) {
            return $target + ['platform' => $platform];
        }

        $platform ??= (string) ($target['default_platform'] ?? array_key_first($profiles));
        $profile = $profiles[$platform] ?? null;
        if (! is_array($profile) || ($profile['enabled'] ?? false) !== true) {
            throw new InvalidArgumentException("AI platform [{$platform}] của target [{$key}] chưa được bật.");
        }

        return array_replace($target, $profile, [
            'key' => $key,
            'platform' => $platform,
            'profile_key' => $platform,
            'base_outputs' => $target['outputs'] ?? [],
            'base_editor_fields' => $target['editor_fields'] ?? [],
        ]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả danh sách platform profile public của target.
     * =====================================================================
     * INPUT: target key.
     * OUTPUT: key/label/enabled của từng profile; không lộ prompt/schema nội bộ.
     * SIDE EFFECT: chỉ đọc config; không gọi provider hoặc ghi database.
     * EXCEPTION/TRANSACTION: InvalidArgumentException khi target không hợp lệ.
     * =====================================================================
     */
    public function platforms(string $key): array
    {
        $target = $this->get($key);
        $profiles = (array) ($target['platform_profiles'] ?? []);

        if ($profiles === []) {
            return collect((array) ($target['platforms'] ?? []))
                ->map(fn (string $platform): array => [
                    'key' => $platform,
                    'label' => ucfirst($platform),
                    'enabled' => true,
                    'outputs' => array_values($target['outputs'] ?? []),
                    'editor_fields' => array_values($target['editor_fields'] ?? []),
                ])->values()->all();
        }

        return collect($profiles)
            ->map(fn (array $profile, string $platform): array => [
                'key' => $platform,
                'label' => $profile['label'] ?? ucfirst($platform),
                'enabled' => ($profile['enabled'] ?? false) === true,
                'outputs' => array_values($profile['outputs'] ?? []),
                'editor_fields' => array_values($profile['editor_fields'] ?? []),
                'pipeline' => $profile['pipeline'] ?? [],
                'output_options' => ($profile['enabled'] ?? false) === true
                    ? $this->outputOptions($key, $platform)
                    : [],
            ])->values()->all();
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
     *                                             =====================================================================
     */
    public function all(): array
    {
        return collect((array) config('ai.agent.targets', []))
            ->filter(fn (array $target): bool => ($target['enabled'] ?? false) === true)
            ->map(fn (array $target, string $key): array => $target + ['key' => $key])
            ->all();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả lựa chọn nhóm AI theo target và config hiện tại
     * =====================================================================
     * INPUT: target key. OUTPUT: value/title/source_types đã allowlist, không AI taxonomy.
     * SIDE EFFECT: chỉ đọc cấu hình; không gọi provider hoặc ghi DB.
     * EXCEPTION/TRANSACTION: InvalidArgumentException khi target không hợp lệ.
     * =====================================================================
     */
    public function outputOptions(string $key, ?string $platform = null): array
    {
        $target = $this->resolve($key, $platform);
        $definitions = (array) config('ai.agent.output_definitions', []);

        return collect($target['outputs'] ?? [])
            ->filter(fn (string $output): bool => isset($definitions[$output])
                && (($definitions[$output]['target_types'] ?? []) === []
                    || in_array($key, (array) $definitions[$output]['target_types'], true)))
            ->map(fn (string $output): array => [
                'value' => $output, 'title' => $definitions[$output]['label'] ?? $output,
                'source_types' => array_values($definitions[$output]['source_types'] ?? []),
                'fields' => array_values($definitions[$output]['fields'] ?? []),
            ])->values()->all();
    }
}
