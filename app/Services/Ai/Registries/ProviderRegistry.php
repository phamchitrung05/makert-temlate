<?php

namespace App\Services\Ai\Registries;

use App\Enums\AiCapability;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Services\Ai\AiConnection;
use App\Services\Ai\Contracts\AiProviderContract;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Registry connection/model cho text, image và client legacy.
 * =====================================================================
 * CÁC HÀM/METHOD: get(), resolve(), resolveForRun(), connectionForRun(),
 * publicOptions(), configuredProvider(), modelOptions(), catalogAvailable().
 * INPUT: provider key, capability hoặc snapshot đã lưu khi tạo run.
 * OUTPUT: adapter/connection nội bộ hoặc metadata công khai không có key/endpoint.
 * SIDE EFFECT: đọc catalog/config và resolve container; không ghi DB/gọi HTTP.
 * EXCEPTION/TRANSACTION: InvalidArgumentException cho connection/model bị tắt
 * hoặc thay đổi; không mở transaction.
 * =====================================================================
 */
final class ProviderRegistry
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Nhận container để resolve adapter theo driver
     * =====================================================================
     * INPUT: container.
     * OUTPUT: registry có dependency container.
     * SIDE EFFECT: chỉ giữ dependency; không ghi database hoặc gọi provider.
     * EXCEPTION/TRANSACTION: không mở transaction.
     * =====================================================================
     */
    public function __construct(private readonly Container $container) {}

    /**
     * =====================================================================
     * CHỨC NĂNG: Đọc provider metadata và model options nội bộ
     * =====================================================================
     * INPUT: provider key.
     * OUTPUT: metadata connection/model; không trả API key ra ngoài registry.
     * SIDE EFFECT: đọc DB/config; không gọi HTTP.
     * EXCEPTION/TRANSACTION: InvalidArgumentException nếu provider không hợp lệ.
     * =====================================================================
     */
    public function get(string $key): array
    {
        $record = $this->catalogAvailable()
            ? AiProvider::query()->with('models')->where('key', $key)->first()
            : null;
        if ($record) {
            if (! $record->is_active || ! filled($record->api_key)) {
                throw new InvalidArgumentException('AI provider chưa được bật hoặc chưa có API key.');
            }
            $models = $this->modelOptions($record);

            return [
                'key' => $record->key, 'label' => $record->name, 'logo' => $record->driver,
                'driver' => $record->driver, 'base_url' => $record->base_url, 'record' => $record,
                'models' => array_column($models, 'value'), 'model_options' => $models,
            ];
        }
        return $this->configuredProvider($key);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Resolve text adapter theo provider driver
     * =====================================================================
     * INPUT: provider key.
     * OUTPUT: AiProviderContract đã được container tạo.
     * SIDE EFFECT: resolve container; không gọi provider khi resolve.
     * EXCEPTION/TRANSACTION: InvalidArgumentException nếu adapter không hợp lệ.
     * =====================================================================
     */
    public function resolve(string $key): AiProviderContract
    {
        $metadata = $this->get($key);
        $class = isset($metadata['record'])
            ? config('ai-providers.presets.'.$metadata['driver'].'.adapter')
            : ($metadata['adapter'] ?? null);
        if (! is_string($class) || ! is_a($class, AiProviderContract::class, true)) {
            throw new InvalidArgumentException('AI provider chưa khai báo adapter hợp lệ.');
        }

        return $this->container->make($class);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Resolve text adapter với snapshot immutable của run
     * =====================================================================
     * INPUT: frozen run snapshot.
     * OUTPUT: adapter có connection/model đúng snapshot.
     * SIDE EFFECT: đọc key hiện tại ở server; không đổi defaults.
     * EXCEPTION/TRANSACTION: InvalidArgumentException nếu snapshot không còn dùng được; không mở transaction.
     * =====================================================================
     */
    public function resolveForRun(array $snapshot): AiProviderContract
    {
        $provider = $this->resolve((string) ($snapshot['provider'] ?? ''));
        if (method_exists($provider, 'withRunSettings')) {
            $provider = $provider->withRunSettings($snapshot);
        }
        if (! empty($snapshot['provider_id']) && method_exists($provider, 'withConnection')) {
            $provider = $provider->withConnection($this->connectionForRun($snapshot, AiCapability::Text));
        }
        if (method_exists($provider, 'withModel')) {
            $provider = $provider->withModel($snapshot['model'] ?? null);
        }

        return $provider;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Khôi phục connection, kiểm tra lại quyền chạy snapshot
     * =====================================================================
     * INPUT: snapshot/capability của run; refreshTimeout=true khi retry thủ công.
     * OUTPUT: AiConnection có secret chỉ nằm trong memory backend.
     * SIDE EFFECT: đọc provider/model; key rotation được nhận ở lần chạy tiếp,
     * timeout chỉ cập nhật theo provider khi caller yêu cầu retry thủ công.
     * EXCEPTION/TRANSACTION: InvalidArgumentException khi endpoint/identity hoặc
     * trạng thái/capability đã đổi; không tự chọn model thay thế hay mở transaction.
     * =====================================================================
     */
    public function connectionForRun(array $snapshot, AiCapability $capability, bool $refreshTimeout = false): AiConnection
    {
        $record = AiProvider::query()->with('models')->find((int) ($snapshot['provider_id'] ?? 0));
        if (! $record || ! $record->is_active || ! filled($record->api_key)
            || (string) $record->key !== (string) ($snapshot['provider'] ?? '')
            || (string) $record->driver !== (string) ($snapshot['driver'] ?? '')
            || rtrim((string) $record->base_url, '/') !== rtrim((string) ($snapshot['base_url'] ?? ''), '/')) {
            throw new InvalidArgumentException('AI connection đã bị tắt hoặc thay đổi; hãy chọn lại model.');
        }
        $model = $record->models->firstWhere('id', (int) ($snapshot['model_id'] ?? 0));
        if (! $model || (string) $model->remote_model_id !== (string) ($snapshot['model'] ?? '')
            || ! $model->setRelation('provider', $record)->usableFor($capability)) {
            throw new InvalidArgumentException('AI model đã bị tắt, thay đổi hoặc không hỗ trợ tác vụ.');
        }

        if ($refreshTimeout) {
            $snapshot['timeout'] = (int) $record->request_timeout;
        }

        return new AiConnection($snapshot, (string) $record->api_key);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo danh sách provider/model public theo capability
     * =====================================================================
     * INPUT: capability.
     * OUTPUT: selector metadata không expose record/secret/endpoint.
     * SIDE EFFECT: đọc metadata; không expose DB record/secret/endpoint.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function publicOptions(AiCapability $capability = AiCapability::Text): array
    {
        $records = $this->catalogAvailable()
            ? AiProvider::query()->with('models')->orderBy('name')->get()
            : collect();
        $catalog = $records->filter(fn (AiProvider $provider): bool => $provider->is_active)
            ->map(function (AiProvider $provider) use ($capability): array {
                $models = $this->modelOptions($provider, $capability);

                return [
                    'key' => $provider->key, 'label' => $provider->name, 'logo' => $provider->driver,
                    'models' => array_column($models, 'value'), 'model_options' => $models,
                ];
            })->values()->all();
        // Bản ghi DB cùng key luôn thắng, kể cả khi bị tắt hoặc không có model hợp lệ.
        $storedKeys = $records->pluck('key')->all();
        $environment = $capability === AiCapability::Image ? [] : collect((array) config('ai-providers.connections', []))
            ->filter(fn (array $connection, string $key): bool => ($connection['enabled'] ?? false) === true && ! in_array($key, $storedKeys, true))
            ->map(fn (array $connection, string $key): array => $this->configuredProvider($key))
            ->map(fn (array $provider): array => [
                'key' => $provider['key'], 'label' => $provider['label'], 'logo' => $provider['logo'],
                'models' => $provider['models'],
                'model_options' => collect($provider['models'])->map(fn (string $model): array => [
                    'id' => null, 'value' => $model, 'label' => $model,
                    'capabilities' => [AiCapability::Text->value, AiCapability::Structured->value],
                ])->values()->all(),
            ])->values()->all();

        return collect(array_merge($catalog, $environment))
            ->filter(fn (array $provider): bool => $provider['models'] !== [])
            ->unique('key')->values()->all();
    }

    /** Resolve metadata từ driver; giữ thông số kết nối và API key ở config server-side. */
    private function configuredProvider(string $key): array
    {
        $connection = config('ai-providers.connections.'.$key);
        $driver = is_array($connection) ? (string) ($connection['driver'] ?? '') : '';
        $definition = config('ai-providers.presets.'.$driver) ?? config('ai-providers.internal.'.$driver);
        if (! is_array($connection) || ($connection['enabled'] ?? false) !== true || ! is_array($definition)) {
            throw new InvalidArgumentException('AI provider chưa được cấu hình hoặc đã tắt.');
        }

        return [
            'key' => $key, 'driver' => $driver,
            'label' => $definition['label'] ?? $key, 'logo' => $definition['logo'] ?? null,
            'adapter' => $definition['adapter'] ?? null,
            'models' => filled($connection['model'] ?? null) ? [(string) $connection['model']] : [],
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lọc model enabled/available theo capability
     * =====================================================================
     * INPUT: provider/capability.
     * OUTPUT: model options có remote ID, label và capability.
     * SIDE EFFECT: set inverse relation trong memory; không ghi database.
     * EXCEPTION/TRANSACTION: không mở transaction.
     * =====================================================================
     */
    private function modelOptions(AiProvider $provider, ?AiCapability $capability = null): array
    {
        return $provider->models
            ->filter(function (AiModel $model) use ($provider, $capability): bool {
                $model->setRelation('provider', $provider);

                return $capability ? $model->usableFor($capability)
                    : $model->is_enabled && $model->is_available;
            })
            ->map(fn (AiModel $model): array => [
                'id' => $model->id, 'value' => $model->remote_model_id,
                'label' => $model->label ?: $model->remote_model_id,
                'capabilities' => $model->capabilities ?: [],
            ])->values()->all();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra catalog đã migrate để bật database-backed registry
     * =====================================================================
     * INPUT: schema state.
     * OUTPUT: true khi bảng ai_providers tồn tại; false để dùng config fallback.
     * SIDE EFFECT: đọc schema; không ghi database hoặc gọi provider.
     * EXCEPTION/TRANSACTION: bắt lỗi schema an toàn; không mở transaction.
     * =====================================================================
     */
    private function catalogAvailable(): bool
    {
        try {
            return Schema::hasTable('ai_providers');
        } catch (\Throwable) {
            return false;
        }
    }
}
