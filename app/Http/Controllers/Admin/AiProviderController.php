<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AiImportException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AiModelRequest;
use App\Http\Requests\Admin\AiProviderRequest;
use App\Http\Requests\Admin\AiSettingsRequest;
use App\Http\Resources\AiModelResource;
use App\Http\Resources\AiProviderResource;
use App\Http\Responses\BaseResponse;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Services\Ai\AiProviderCatalogService;
use App\Services\Ai\AiSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: HTTP boundary cho Settings > AI Providers và model catalog.
 * CÁC HÀM/METHOD: index(), store(), update(), disable(), test(), sync(),
 * storeModel(), updateModel(), settings(), updateSettings().
 * INPUT: FormRequest đã whitelist; OUTPUT: BaseResponse/Resource không chứa API key.
 * SIDE EFFECT: ghi encrypted key, gọi provider test/sync, audit qua service.
 * EXCEPTION/TRANSACTION: controller không giữ transaction qua outbound HTTP;
 * service quản lý transaction cho phần ghi catalog/settings.
 * =====================================================================
 */
final class AiProviderController extends Controller
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Trả toàn bộ connection/model options và settings defaults cho trang quản trị.
     * =====================================================================
     * INPUT: AiSettingsService từ container.
     * OUTPUT: catalog, settings typed và preset public qua BaseResponse.
     * SIDE EFFECT: đọc DB/config; không gọi provider.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function index(AiSettingsService $settings): JsonResponse
    {
        return BaseResponse::success([
            'providers' => AiProviderResource::collection(AiProvider::query()->with('models')->orderBy('name')->get()),
            'settings' => $settings->all(),
            'presets' => collect((array) config('ai-providers.presets', []))->map(fn (array $preset, string $key): array => [
                'key' => $key, 'label' => $preset['label'] ?? $key, 'kind' => $preset['kind'] ?? 'custom',
                'driver' => $preset['driver'] ?? $key, 'base_url' => $preset['base_url'] ?? null,
                'image_supported' => (bool) ($preset['image_supported'] ?? false),
                'request_timeout' => (int) config('ai-providers.request_timeout', 120),
            ])->values()->all(),
        ], 'Cấu hình AI.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lưu connection mới; API key chỉ nhận ở request write-only.
     * =====================================================================
     * INPUT: AiProviderRequest đã validate và actor hiện tại.
     * OUTPUT: response 201 chứa provider metadata, không chứa key.
     * SIDE EFFECT: gọi catalog service để lưu encrypted connection và audit.
     * EXCEPTION/TRANSACTION: ValidationException; transaction do catalog service quản lý.
     * =====================================================================
     */
    public function store(AiProviderRequest $request, AiProviderCatalogService $catalog): JsonResponse
    {
        $provider = $catalog->save($request->validated(), (int) $request->user()->getKey());

        return BaseResponse::created(new AiProviderResource($provider), 'Đã thêm provider AI.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Cập nhật metadata/rotate key; key trống giữ nguyên key hiện tại.
     * =====================================================================
     * INPUT: request đã validate, provider route và actor hiện tại.
     * OUTPUT: response metadata provider đã cập nhật, không chứa key.
     * SIDE EFFECT: gọi catalog service để lưu connection và audit.
     * EXCEPTION/TRANSACTION: ValidationException; transaction do service quản lý.
     * =====================================================================
     */
    public function update(AiProviderRequest $request, AiProvider $provider, AiProviderCatalogService $catalog): JsonResponse
    {
        $saved = $catalog->save($request->validated(), (int) $request->user()->getKey(), $provider);

        return BaseResponse::success(new AiProviderResource($saved), 'Đã cập nhật provider AI.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Disable mềm provider để không xóa model/default history.
     * =====================================================================
     * INPUT: provider route và actor hiện tại.
     * OUTPUT: provider metadata inactive.
     * SIDE EFFECT: ghi provider state và activity log; không gọi provider.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function disable(Request $request, AiProvider $provider): JsonResponse
    {
        $provider->forceFill(['is_active' => false, 'test_status' => 'untested'])->save();
        activity('ai-settings')->causedBy($request->user())->withProperties(['provider_id' => $provider->id])->log('provider.disabled');

        return BaseResponse::success(new AiProviderResource($provider->load('models')), 'Đã tắt provider AI.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Test catalog hoặc một model; lỗi upstream được chuyển thành message an toàn.
     * =====================================================================
     * INPUT: provider route, model_id tùy chọn và actor hiện tại.
     * OUTPUT: test status/message hoặc lỗi domain đã redact.
     * SIDE EFFECT: service gọi HTTPS provider và lưu trạng thái test/audit.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function test(Request $request, AiProvider $provider, AiProviderCatalogService $catalog): JsonResponse
    {
        $request->validate(['model_id' => ['nullable', 'integer', 'min:1']]);
        $model = null;
        if ($request->filled('model_id')) {
            $model = $provider->models()->findOrFail((int) $request->input('model_id'));
        }
        try {
            return BaseResponse::success($catalog->test($provider, $model, (int) $request->user()->getKey()), 'Đã kiểm tra kết nối AI.');
        } catch (AiImportException $exception) {
            return BaseResponse::error($exception->getMessage(), 422, ['code' => [$exception->errorCode]]);
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đồng bộ catalog đầy đủ; response rỗng/malformed không làm mất dữ liệu cũ.
     * =====================================================================
     * INPUT: provider route và actor hiện tại.
     * OUTPUT: count imported/unavailable hoặc lỗi domain an toàn.
     * SIDE EFFECT: service gọi GET catalog và upsert DB/audit.
     * EXCEPTION/TRANSACTION: AiImportException trả 422; service quản lý lock/transaction.
     * =====================================================================
     */
    public function sync(Request $request, AiProvider $provider, AiProviderCatalogService $catalog): JsonResponse
    {
        try {
            return BaseResponse::success($catalog->sync($provider, (int) $request->user()->getKey()), 'Đã đồng bộ model.');
        } catch (AiImportException $exception) {
            return BaseResponse::error($exception->getMessage(), 422, ['code' => [$exception->errorCode]]);
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Thêm model thủ công cho endpoint không có /models.
     * =====================================================================
     * INPUT: request model đã validate, provider route và actor.
     * OUTPUT: response 201 chứa model metadata.
     * SIDE EFFECT: service ghi catalog model/audit; không gọi provider.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function storeModel(AiModelRequest $request, AiProvider $provider, AiProviderCatalogService $catalog): JsonResponse
    {
        $model = $catalog->saveModel($provider, $request->validated(), (int) $request->user()->getKey());

        return BaseResponse::created(new AiModelResource($model), 'Đã thêm model AI.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Cập nhật capability/status model; route luôn scope theo provider.
     * =====================================================================
     * INPUT: request model đã validate và provider/model route.
     * OUTPUT: model metadata đã cập nhật.
     * SIDE EFFECT: service ghi model/audit; không gọi provider.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function updateModel(AiModelRequest $request, AiProvider $provider, AiModel $model, AiProviderCatalogService $catalog): JsonResponse
    {
        if ($model->ai_provider_id !== $provider->id) {
            abort(404);
        }
        $saved = $catalog->saveModel($provider, $request->validated(), (int) $request->user()->getKey(), $model);

        return BaseResponse::success(new AiModelResource($saved), 'Đã cập nhật model AI.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đọc settings typed; không trả key.
     * =====================================================================
     * INPUT: AiSettingsService từ container.
     * OUTPUT: settings typed, không chứa API key.
     * SIDE EFFECT: đọc settings/config; không gọi provider.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function settings(AiSettingsService $settings): JsonResponse
    {
        return BaseResponse::success($settings->all(), 'Thiết lập AI.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lưu defaults/fallback và tuning chung; service whitelist key.
     * =====================================================================
     * INPUT: AiSettingsRequest đã validate và actor hiện tại.
     * OUTPUT: settings typed đã lưu hoặc validation response.
     * SIDE EFFECT: service ghi settings/audit; không gọi provider.
     * EXCEPTION/TRANSACTION: ValidationException; transaction do service quản lý.
     * =====================================================================
     */
    public function updateSettings(AiSettingsRequest $request, AiSettingsService $settings): JsonResponse
    {
        try {
            return BaseResponse::success($settings->update($request->validated(), (int) $request->user()->getKey()), 'Đã lưu thiết lập AI.');
        } catch (ValidationException $exception) {
            return BaseResponse::validation($exception->errors());
        }
    }
}
