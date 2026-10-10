<?php

namespace App\Services\Ai\Providers\Catalog;

use App\Enums\AiCapability;
use App\Models\AiModel;
use App\Services\Ai\Registries\ProviderRegistry;
use App\Services\Ai\Settings\AiSettingsService;
use Illuminate\Validation\ValidationException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Một nguồn resolve model cho session, regenerate, image và defaults.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: __construct(), resolve(), fromModel(), usable(), legacy().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * INPUT: override/default + capability.
 * OUTPUT: snapshot public không có API key.
 * SIDE EFFECT: read catalog/settings; không gọi provider.
 * EXCEPTION/TRANSACTION: invalid override trả ValidationException; không mở transaction.
 * =====================================================================
 */
final class ModelResolver
{
    /**
     * =====================================================================
     * Input: settings service và registry provider đã được container resolve.
     * Output: resolver dùng chung snapshot model; không gọi AI hoặc ghi database.
     * =====================================================================
     */
    public function __construct(
        private readonly AiSettingsService $settings,
        private readonly ProviderRegistry $providers,
    ) {}

    /**
     * =====================================================================
     * CHỨC NĂNG: Chọn model theo override, default hoặc fallback của capability
     * =====================================================================
     * INPUT: Capability và model_id hoặc cặp provider/model tương thích cũ.
     * OUTPUT: Snapshot identity/tuning cho run, không có API key.
     * SIDE EFFECT: Đọc settings/catalog/registry; không ghi DB hoặc gọi provider.
     * EXCEPTION/TRANSACTION: ValidationException khi override sai hoặc default/fallback không khả dụng; không đổi model âm thầm.
     * =====================================================================
     */
    public function resolve(AiCapability $capability, array $selection = []): array
    {
        $settings = $this->settings->all();
        if (! empty($selection['model_id'])) {
            $model = AiModel::query()->with('provider')->find($selection['model_id']);
            if (! $model || ! $this->usable($model, $capability)) {
                throw ValidationException::withMessages(['model_id' => 'Model chưa bật, không khả dụng hoặc không hỗ trợ tác vụ.']);
            }
            if (filled($selection['provider'] ?? null) && $selection['provider'] !== $model->provider->key) {
                throw ValidationException::withMessages(['provider' => 'Model không thuộc provider đã chọn.']);
            }
            if (filled($selection['model'] ?? null) && $selection['model'] !== $model->remote_model_id) {
                throw ValidationException::withMessages(['model' => 'Remote model ID không khớp model đã chọn.']);
            }

            return $this->fromModel($model, $settings, $capability);
        }
        if (filled($selection['provider'] ?? null) || filled($selection['model'] ?? null)) {
            return $this->legacy($capability, $selection, $settings);
        }

        $suffix = $capability === AiCapability::Image ? 'image' : 'text';
        $ids = array_filter([$settings["default_{$suffix}_model_id"], $settings["fallback_{$suffix}_model_id"]]);
        foreach (array_unique($ids) as $id) {
            $model = AiModel::query()->with('provider')->find($id);
            if ($model && $this->usable($model, $capability)) {
                return $this->fromModel($model, $settings, $capability);
            }
        }
        if ($ids !== [] || $capability === AiCapability::Image) {
            throw ValidationException::withMessages(['model_id' => 'Hãy cấu hình model mặc định hoặc fallback khả dụng cho tác vụ này.']);
        }

        $provider = (string) config('ai.providers.default_provider', 'deterministic');
        if ($provider === 'deterministic') {
            throw ValidationException::withMessages(['model_id' => 'Chưa có model AI mặc định. Chọn model hoặc cấu hình model mặc định/fallback trong AI & Content.']);
        }

        $resolved = $this->legacy($capability, ['provider' => $provider], $settings);
        if (! $this->providers->resolveForRun($resolved)->configured()) {
            throw ValidationException::withMessages(['model_id' => 'Model AI mặc định chưa có kết nối hợp lệ. Hãy kiểm tra cấu hình provider.']);
        }

        return $resolved;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chụp identity và tuning server-side vào run snapshot
     * =====================================================================
     * INPUT: AiModel đã load provider, settings/capability tùy chọn.
     * OUTPUT: Mảng provider/model IDs, remote ID và tuning; không chứa key.
     * SIDE EFFECT: Đọc settings nếu chưa truyền; không ghi DB hoặc gọi provider.
     * EXCEPTION/TRANSACTION: Không mở transaction; caller xác minh model usability trước khi dùng.
     * =====================================================================
     */
    public function fromModel(AiModel $model, ?array $settings = null, ?AiCapability $capability = null): array
    {
        $settings ??= $this->settings->all();

        return [
            'provider_id' => $model->ai_provider_id, 'provider' => $model->provider->key,
            'provider_label' => $model->provider->name, 'driver' => $model->provider->driver,
            'base_url' => $model->provider->base_url, 'model_id' => $model->id,
            'model' => $model->remote_model_id, 'capabilities' => $model->capabilities,
            'capability' => $capability?->value,
            'temperature' => (float) $settings['default_temperature'],
            'timeout' => (int) $model->provider->request_timeout,
            'system_prompt' => $settings['default_system_prompt'],
            'min_word_count' => (int) $settings['min_word_count'],
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra model dùng được cho capability của pipeline
     * =====================================================================
     * INPUT: AiModel và capability.
     * OUTPUT: true khi provider/key/model/status/capability đều phù hợp.
     * SIDE EFFECT: Chỉ đọc model/quan hệ; không ghi DB hoặc gọi provider.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function usable(AiModel $model, AiCapability $capability): bool
    {
        return $model->usableFor($capability);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Resolve cặp provider/model từ client cũ qua catalog hoặc config allowlist
     * =====================================================================
     * INPUT: Capability, provider/model strings và settings.
     * OUTPUT: Snapshot của model đã xác minh; giữ nguyên remote ID.
     * SIDE EFFECT: Đọc registry/catalog; không gọi provider.
     * EXCEPTION/TRANSACTION: ValidationException nếu provider/model sai; không fallback sang model khác khi override không hợp lệ.
     * =====================================================================
     */
    private function legacy(AiCapability $capability, array $selection, array $settings): array
    {
        if (empty($selection['provider'])) {
            throw ValidationException::withMessages(['provider' => 'Chọn provider trước khi chọn model.']);
        }
        try {
            $provider = $this->providers->get((string) $selection['provider']);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['provider' => 'Provider chưa được cấu hình hoặc đã tắt.']);
        }
        $modelId = $selection['model'] ?? null;
        if (isset($provider['record'])) {
            $models = $provider['record']->models;
            $model = filled($modelId)
                ? $models->firstWhere('remote_model_id', $modelId)
                : $models->first(fn (AiModel $item): bool => $this->usable($item->setRelation('provider', $provider['record']), $capability));
            if (! $model || ! $this->usable($model->setRelation('provider', $provider['record']), $capability)) {
                throw ValidationException::withMessages(['model' => 'Model không khả dụng hoặc không hỗ trợ tác vụ của provider.']);
            }

            return $this->fromModel($model, $settings, $capability);
        }
        $modelId = filled($modelId) ? $modelId : ($provider['models'][0] ?? null);
        if ($capability !== AiCapability::Text || ! in_array($modelId, $provider['models'] ?? [], true)) {
            throw ValidationException::withMessages(['model' => 'Model không nằm trong allowlist hoặc không hỗ trợ tác vụ.']);
        }

        return [
            'provider_id' => null, 'provider' => $provider['key'], 'provider_label' => $provider['label'],
            'driver' => $provider['driver'], 'model_id' => null, 'model' => $modelId,
            'capabilities' => [AiCapability::Text->value, AiCapability::Structured->value],
            'capability' => $capability->value,
            'temperature' => (float) $settings['default_temperature'], 'timeout' => (int) $settings['request_timeout'],
            'system_prompt' => $settings['default_system_prompt'],
            'min_word_count' => (int) $settings['min_word_count'],
        ];
    }
}
