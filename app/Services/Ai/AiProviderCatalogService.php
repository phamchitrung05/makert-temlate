<?php

namespace App\Services\Ai;

use App\Enums\AiCapability;
use App\Exceptions\AiImportException;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Connection CRUD, model discovery/manual và connection/model test.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: __construct(), save(), saveModel(), sync(), test(), discover(), capabilities(), audit().
 * INPUT: validated admin data.
 * OUTPUT: catalog/result không secret.
 * SIDE EFFECT: HTTPS + atomic catalog update; sync lỗi giữ nguyên catalog đã lưu.
 * EXCEPTION/TRANSACTION: ValidationException/AiImportException an toàn; sync mutate trong transaction.
 * =====================================================================
 */
final class AiProviderCatalogService
{
    public function __construct(private readonly AiProviderClient $client) {}

    /**
     * =====================================================================
     * CHỨC NĂNG: Lưu provider và rotate API key write-only.
     * =====================================================================
     * INPUT: metadata + key write-only.
     * OUTPUT: saved provider; key trống khi edit giữ key hiện tại.
     * SIDE EFFECT: validate URL/DNS, ghi provider và activity log.
     * EXCEPTION/TRANSACTION: ValidationException; ghi metadata trong transaction atomic.
     * =====================================================================
     */
    public function save(array $data, int $actorId, ?AiProvider $provider = null): AiProvider
    {
        $provider ??= new AiProvider(['key' => 'connection-'.Str::uuid()]);
        $driver = $provider->exists ? $provider->driver : $data['driver'];
        if ($provider->exists && isset($data['driver']) && $data['driver'] !== $driver) {
            throw ValidationException::withMessages(['driver' => 'Tạo connection mới để đổi loại provider.']);
        }
        $preset = config('ai-providers.presets.'.$driver);
        if (! is_array($preset)) {
            throw ValidationException::withMessages(['driver' => 'Driver AI chưa được khai báo.']);
        }
        $url = $preset['kind'] === 'official' ? $preset['base_url'] : ($data['base_url'] ?? $provider->base_url);
        try {
            $url = $this->client->normalizeBaseUrl((string) $url, $driver);
        } catch (AiImportException $exception) {
            throw ValidationException::withMessages(['base_url' => $exception->getMessage()]);
        }
        if (! $provider->exists && ! filled($data['api_key'] ?? null)) {
            throw ValidationException::withMessages(['api_key' => 'API key là bắt buộc khi tạo provider.']);
        }
        $baseChanged = $provider->exists && rtrim((string) $provider->base_url, '/') !== rtrim($url, '/');
        $changed = $provider->exists && (filled($data['api_key'] ?? null) || $baseChanged);

        return DB::transaction(function () use ($data, $actorId, $provider, $driver, $preset, $url, $changed, $baseChanged): AiProvider {
            $provider->fill([
                'name' => $data['name'], 'kind' => $preset['kind'], 'driver' => $driver,
                'base_url' => $url, 'is_active' => $data['is_active'] ?? $provider->is_active ?? true,
                'discovery_mode' => $data['discovery_mode'] ?? $provider->discovery_mode ?? 'models_endpoint',
                'request_timeout' => $data['request_timeout'] ?? ($provider->exists ? $provider->request_timeout : config('ai-providers.request_timeout', 120)),
            ]);
            if (filled($data['api_key'] ?? null)) {
                $provider->api_key = $data['api_key'];
            }
            if ($changed) {
                $provider->test_status = 'untested';
                $provider->test_message = null;
            }
            $provider->save();
            if ($baseChanged) {
                $provider->models()->update(['is_available' => false]);
            }
            $this->audit($provider, $actorId, $changed ? 'provider.rotated_or_updated' : 'provider.saved');

            return $provider->load('models');
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lưu model thủ công hoặc cập nhật capability do admin xác nhận
     * =====================================================================
     * INPUT: Provider, model data đã validate và actor ID.
     * OUTPUT: AiModel đã lưu, remote ID của model cũ được giữ nguyên.
     * SIDE EFFECT: Ghi ai_models và activity log; không gọi provider.
     * EXCEPTION/TRANSACTION: ValidationException/404 cho identity hoặc capability sai; không mở transaction riêng.
     * =====================================================================
     */
    public function saveModel(AiProvider $provider, array $data, int $actorId, ?AiModel $model = null): AiModel
    {
        $model ??= new AiModel(['ai_provider_id' => $provider->id, 'discovery_source' => 'manual']);
        if ($model->exists && $model->ai_provider_id !== $provider->id) {
            abort(404);
        }
        if ($model->exists && array_key_exists('remote_model_id', $data)
            && (string) $data['remote_model_id'] !== (string) $model->remote_model_id) {
            throw ValidationException::withMessages(['remote_model_id' => 'Remote model ID không thể đổi; thêm model mới để giữ provenance.']);
        }
        if (in_array(AiCapability::Image->value, $data['capabilities'], true)
            && ! config('ai-providers.presets.'.$provider->driver.'.image_supported', false)) {
            throw ValidationException::withMessages(['capabilities' => 'Driver này chưa hỗ trợ tạo ảnh.']);
        }
        $model->fill($data);
        if (! $model->exists) {
            $model->is_enabled = $data['is_enabled'] ?? true;
            $model->is_available = $data['is_available'] ?? true;
        }
        $model->label ??= $model->remote_model_id;
        $model->capability_source = 'admin';
        $model->save();
        $this->audit($provider, $actorId, 'model.saved', ['model_id' => $model->id]);

        return $model;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đồng bộ toàn bộ model catalog sau khi đọc hết provider response.
     * =====================================================================
     * INPUT: provider connection.
     * OUTPUT: count imported/missing after complete discovery.
     * SIDE EFFECT: GET network ngoài transaction, atomic upsert + mark unavailable trong DB.
     * EXCEPTION/TRANSACTION: Lock chống sync đồng thời; partial/malformed/empty response không phá model/default cũ.
     * =====================================================================
     */
    public function sync(AiProvider $provider, ?int $actorId = null): array
    {
        if ($provider->discovery_mode === 'manual') {
            throw new AiImportException('Provider đang dùng catalog thủ công; thêm model bằng model ID.', 'AI_DISCOVERY_MANUAL');
        }
        try {
            return Cache::lock('ai-model-sync-'.$provider->id, max(180, (int) $provider->request_timeout * 3 + 60))->block(1, function () use ($provider, $actorId): array {
                $fingerprint = [$provider->driver, rtrim((string) $provider->base_url, '/'), hash('sha256', (string) $provider->api_key)];
                $items = $this->discover($provider);

                return DB::transaction(function () use ($provider, $items, $actorId, $fingerprint): array {
                    $provider->refresh();
                    $currentFingerprint = [$provider->driver, rtrim((string) $provider->base_url, '/'), hash('sha256', (string) $provider->api_key)];
                    if ($currentFingerprint !== $fingerprint) {
                        throw new AiImportException('Connection đã thay đổi trong lúc đồng bộ; catalog cũ được giữ nguyên.', 'AI_DISCOVERY_CONNECTION_CHANGED');
                    }
                    $seen = [];
                    foreach ($items as $item) {
                        $id = $item['id'];
                        $seen[] = $id;
                        $model = $provider->models()->firstOrNew(['remote_model_id' => $id]);
                        [$capabilities, $source] = $this->capabilities($provider, $item);
                        if ($model->capability_source !== 'admin') {
                            $model->capabilities = $capabilities;
                            $model->capability_source = $source;
                        }
                        if (! $model->exists) {
                            $model->is_enabled = true;
                            $model->discovery_source = 'remote';
                        }
                        $model->label = $model->exists ? $model->label : ($item['label'] ?? $id);
                        $model->metadata = array_intersect_key($item, array_flip(['owned_by', 'supportedGenerationMethods']));
                        $model->is_available = true;
                        $model->last_seen_at = now();
                        $model->save();
                    }
                    $missing = $provider->models()->where('discovery_source', 'remote')
                        ->whereNotIn('remote_model_id', $seen)->update(['is_available' => false]);
                    $provider->update(['last_synced_at' => now()]);
                    $this->audit($provider, $actorId, 'models.synced', ['count' => count($seen), 'unavailable' => $missing]);

                    return ['count' => count($seen), 'unavailable' => $missing];
                });
            });
        } catch (LockTimeoutException) {
            throw new AiImportException('Provider đang được đồng bộ bởi tác vụ khác.', 'AI_DISCOVERY_BUSY', true);
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra kết nối catalog hoặc quyền gọi một model
     * =====================================================================
     * INPUT: Provider, model tùy chọn và actor ID.
     * OUTPUT: Test status/message đã loại bỏ thông tin secret.
     * SIDE EFFECT: Gọi HTTPS catalog hoặc generation tối thiểu; lưu test status/audit.
     * EXCEPTION/TRANSACTION: AiImportException an toàn; generation test có thể tính phí, không giữ transaction khi gọi HTTP.
     * =====================================================================
     */
    public function test(AiProvider $provider, ?AiModel $model, int $actorId): array
    {
        if (! $provider->is_active || ! filled($provider->api_key)) {
            throw new AiImportException('Provider chưa bật hoặc chưa có API key.', 'AI_PROVIDER_NOT_CONFIGURED');
        }
        try {
            $connection = new AiConnection([
                'provider' => $provider->key, 'driver' => $provider->driver,
                'base_url' => $provider->base_url, 'model' => $model?->remote_model_id, 'timeout' => (int) $provider->request_timeout,
            ], $provider->api_key);
            if ($model) {
                $path = $provider->driver === 'gemini' ? 'models/'.rawurlencode($model->remote_model_id).':generateContent' : 'chat/completions';
                $payload = $provider->driver === 'gemini'
                    ? ['contents' => [['parts' => [['text' => 'Reply OK.']]]], 'generationConfig' => ['maxOutputTokens' => 16]]
                    : ['model' => $model->remote_model_id, 'messages' => [['role' => 'user', 'content' => 'Reply OK.']], 'max_tokens' => 16];
                $result = $this->client->send($connection, 'POST', $path, $payload);
                if (! data_get($result, 'choices.0.message.content') && ! data_get($result, 'candidates.0.content.parts.0.text')) {
                    throw new AiImportException('Provider không trả nội dung kiểm tra model hợp lệ.', 'AI_TEST_INVALID_RESPONSE');
                }
                $message = 'Model đã phản hồi request thử. Quota và quyền có thể thay đổi ở lần gọi sau.';
            } else {
                if ($provider->discovery_mode === 'manual') {
                    throw new AiImportException('Provider dùng catalog thủ công. Hãy test một model hoặc tạo ảnh thử trong Post.', 'AI_TEST_MODEL_REQUIRED');
                }
                $this->discover($provider);
                $message = 'API catalog phản hồi thành công; chưa xác nhận quyền/quota tạo nội dung hoặc ảnh.';
            }
            $provider->update(['test_status' => 'success', 'test_message' => $message, 'last_tested_at' => now()]);
        } catch (AiImportException $exception) {
            $provider->update(['test_status' => 'failed', 'test_message' => $exception->getMessage(), 'last_tested_at' => now()]);
            $this->audit($provider, $actorId, 'provider.test_failed', ['code' => $exception->errorCode]);
            throw $exception;
        }
        $this->audit($provider, $actorId, 'provider.tested', ['model_id' => $model?->id]);

        return ['status' => 'success', 'message' => $message];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đọc và chuẩn hóa catalog đầy đủ trước khi thay đổi DB
     * =====================================================================
     * INPUT: Provider connection; Gemini có thể trả nhiều trang.
     * OUTPUT: Danh sách model remote hợp lệ và deduplicated.
     * SIDE EFFECT: Gọi HTTPS GET qua client chung; không ghi catalog.
     * EXCEPTION/TRANSACTION: AiImportException cho response rỗng/sai/oversized hoặc pagination lặp; không mở transaction.
     * =====================================================================
     */
    private function discover(AiProvider $provider): array
    {
        $connection = new AiConnection([
            'driver' => $provider->driver, 'base_url' => $provider->base_url, 'timeout' => (int) $provider->request_timeout,
        ], $provider->api_key);
        $items = [];
        $pageToken = null;
        $seenTokens = [];
        do {
            $query = $provider->driver === 'gemini' ? array_filter(['pageSize' => 1000, 'pageToken' => $pageToken]) : [];
            $response = $this->client->send($connection, 'GET', 'models', $query);
            $page = $response[$provider->driver === 'gemini' ? 'models' : 'data'] ?? null;
            if (! is_array($page) || ! array_is_list($page)) {
                throw new AiImportException('Endpoint không trả catalog model đúng chuẩn; có thể dùng chế độ thủ công.', 'AI_MODELS_INVALID');
            }
            foreach ($page as $row) {
                if (! is_array($row)) {
                    throw new AiImportException('Catalog chứa model không hợp lệ.', 'AI_MODELS_INVALID');
                }
                $id = $provider->driver === 'gemini' ? preg_replace('#^models/#', '', (string) ($row['name'] ?? '')) : ($row['id'] ?? null);
                if (! is_string($id) || trim($id) === '' || strlen($id) > 190 || preg_match('/[\x00-\x1f]/', $id)) {
                    throw new AiImportException('Catalog chứa model ID không hợp lệ.', 'AI_MODELS_INVALID');
                }
                $items[$id] = ['id' => $id, 'label' => Str::limit((string) ($row['displayName'] ?? $id), 190, '')] + $row;
            }
            $pageToken = $provider->driver === 'gemini' ? ($response['nextPageToken'] ?? null) : null;
            if ($pageToken !== null && (! is_string($pageToken) || strlen($pageToken) > 2048)) {
                throw new AiImportException('Catalog trả pagination token không hợp lệ.', 'AI_MODELS_INVALID');
            }
            if (count($items) > config('ai-providers.max_models') || ($pageToken && in_array($pageToken, $seenTokens, true))) {
                throw new AiImportException('Catalog vượt giới hạn hoặc pagination không hợp lệ.', 'AI_MODELS_INVALID');
            }
            $seenTokens[] = $pageToken;
        } while ($pageToken);
        if ($items === []) {
            throw new AiImportException('Catalog rỗng; giữ nguyên danh sách model hiện tại.', 'AI_MODELS_EMPTY');
        }

        return array_values($items);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Xác định capability từ preset hoặc metadata rõ ràng
     * =====================================================================
     * INPUT: Provider và model metadata remote.
     * OUTPUT: Capability list cùng nguồn preset/provider/unknown; không đoán từ tên model.
     * SIDE EFFECT: Chỉ đọc config và metadata.
     * EXCEPTION/TRANSACTION: Không gọi provider hoặc mở transaction.
     * =====================================================================
     */
    private function capabilities(AiProvider $provider, array $item): array
    {
        /**
         * =====================================================================
         * GHI CHÚ: Lookup trực tiếp để model ID có dấu chấm không bị dot notation.
         * =====================================================================
         * Không dùng data_get cho key remote do provider có thể trả ID chứa dấu chấm.
         * =====================================================================
         */
        $known = config('ai-providers.presets.'.$provider->driver.'.models', [])[$item['id']] ?? null;
        if (is_array($known)) {
            return [$known, 'preset'];
        }
        $allowed = array_column(AiCapability::cases(), 'value');
        $remote = $item['capabilities'] ?? [];
        if (is_array($remote) && array_is_list($remote)) {
            $valid = array_values(array_intersect($allowed, array_filter($remote, 'is_string')));
            if ($valid !== []) {
                return [$valid, 'provider'];
            }
        }
        if (in_array('embedContent', $item['supportedGenerationMethods'] ?? [], true)) {
            return [[AiCapability::Embedding->value], 'provider'];
        }

        return [[], 'unknown'];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Ghi activity log bằng metadata an toàn
     * =====================================================================
     * INPUT: Provider, actor nullable, event và ID/count/code nội bộ.
     * OUTPUT: Activity log; không trả giá trị.
     * SIDE EFFECT: Đọc actor và ghi activity_log; không nhận payload chứa API key.
     * EXCEPTION/TRANSACTION: Dùng transaction của caller nếu có; không mở transaction riêng.
     * =====================================================================
     */
    private function audit(AiProvider $provider, ?int $actorId, string $event, array $metadata = []): void
    {
        $logger = activity('ai-settings')->withProperties(['provider_id' => $provider->id] + $metadata);
        if ($actorId && ($actor = User::query()->find($actorId))) {
            $logger->causedBy($actor);
        }
        $logger->log($event);
    }
}
