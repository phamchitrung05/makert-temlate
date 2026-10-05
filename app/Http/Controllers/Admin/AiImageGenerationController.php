<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AiCapability;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AiImageGenerationRequest;
use App\Http\Resources\MediaAssetResource;
use App\Http\Responses\BaseResponse;
use App\Models\AiImport;
use App\Models\MediaAsset;
use App\Services\Ai\Providers\Catalog\ModelResolver;
use App\Services\Ai\Runs\AiRunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: API tạo/poll ảnh độc lập với luồng text Post.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: store(), show().
 * - payload(): trả trạng thái ảnh và asset qua envelope an toàn.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * INPUT: request prompt/model và actor admin.
 * OUTPUT: AiImport/MediaAssetResource theo trạng thái queue.
 * SIDE EFFECT: ghi run metadata và dispatch image job.
 * EXCEPTION/TRANSACTION: ValidationException/HTTP error an toàn; không gọi provider trong request.
 * =====================================================================
 */
final class AiImageGenerationController extends Controller
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Xếp hàng image run với snapshot model theo capability
     * =====================================================================
     * INPUT: Prompt và optional provider/model đã validate.
     * OUTPUT: 202 chứa image run queued hoặc run idempotent.
     * SIDE EFFECT: Resolver đọc catalog; AiRunService ghi run và dispatch image job.
     * EXCEPTION/TRANSACTION: Validation/quota error an toàn; transaction thuộc run service, không gọi provider ở request.
     * =====================================================================
     */
    public function store(AiImageGenerationRequest $request, ModelResolver $resolver, AiRunService $runs): JsonResponse
    {
        $data = $request->validated();
        try {
            $connection = $resolver->resolve(AiCapability::Image, array_filter([
                'provider' => $data['provider'] ?? null,
                'model' => $data['model'] ?? null,
                'model_id' => $data['model_id'] ?? null,
            ], static fn (mixed $value): bool => filled($value)));
        } catch (ValidationException $exception) {
            return BaseResponse::validation($exception->errors());
        }
        $hash = hash('sha256', 'image|'.json_encode([$data, $connection], JSON_UNESCAPED_UNICODE));
        $import = $runs->create((int) $request->user()->getKey(), [
            'source_url' => '',
            'source_hash' => $hash, 'status' => 'queued', 'current_step' => 'queued', 'progress' => 0,
            'input_json' => ['prompt' => $data['prompt'], 'title' => $data['title'] ?? 'AI generated image', 'alt_text' => $data['alt_text'] ?? null, 'provider' => $connection['provider'], 'model' => $connection['model'], 'model_id' => $connection['model_id'], 'ai_connection' => $connection],
            'provider' => $connection['provider'], 'operation' => 'image', 'prompt_version' => 'image-v1',
        ], (bool) ($data['regenerate'] ?? false));

        return BaseResponse::success($this->payload($import, $request), 'Đã xếp hàng tạo ảnh.', 202);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đọc trạng thái image run thuộc admin hiện tại
     * =====================================================================
     * INPUT: Request xác thực và AiImport route-bound.
     * OUTPUT: Public lifecycle và asset metadata nếu ready.
     * SIDE EFFECT: Đọc run/asset; không ghi DB hoặc gọi provider.
     * EXCEPTION/TRANSACTION: Abort 404 nếu sai owner/operation; không mở transaction.
     * =====================================================================
     */
    public function show(Request $request, AiImport $aiImport): JsonResponse
    {
        abort_unless((int) $aiImport->created_by === (int) $request->user()->getKey(), 404);
        abort_unless($aiImport->operation === 'image', 404);

        return BaseResponse::success($this->payload($aiImport->fresh(), $request), 'Trạng thái tạo ảnh.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn hóa image lifecycle và asset metadata cho API
     * =====================================================================
     * INPUT: AiImport và request hiện tại.
     * OUTPUT: Status/progress/error cùng MediaAssetResource public nếu có ảnh.
     * SIDE EFFECT: Đọc MediaAsset/media; không trả connection/key hoặc ghi DB.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    private function payload(AiImport $import, ?Request $request = null): array
    {
        $image = (array) data_get($import->result_json, 'image', []);
        $assetId = $image['media_asset_id'] ?? null;
        $assetModel = $assetId ? MediaAsset::query()->with('media')->find($assetId) : null;
        $asset = $assetModel
            ? MediaAssetResource::make($assetModel)->resolve($request ?? request())
            : null;

        return [
            'job_id' => $import->id, 'status' => $import->status, 'current_step' => $import->current_step,
            'progress' => (int) $import->progress, 'error_code' => $import->error_code, 'error' => $import->error_message,
            'provider' => $import->provider, 'model' => data_get($import->input_json, 'model'),
            'image' => $assetId ? [...$image, 'asset' => $asset] : null,
        ];
    }
}
