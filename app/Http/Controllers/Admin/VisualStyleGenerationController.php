<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\VisualStyle\GenerateImagesRequest;
use App\Http\Resources\VisualStyleGenerationResource;
use App\Http\Responses\BaseResponse;
use App\Models\AiImport;
use App\Services\Ai\VisualStyle\VisualStyleGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: HTTP orchestration cho generation pipeline của
 * Visual Style Engine — queue batch và đọc trạng thái run.
 * =====================================================================
 * Controller chỉ điều phối: FormRequest lo shape + authorize, service xử lý
 * nghiệp vụ (validate style/recipe, tạo AiImport, dispatch job), Resource
 * định hình output. Không chứa provider call, transaction nhiều bước hay
 * rule nghiệp vụ — chúng nằm trong App\Services\Ai\VisualStyle.
 * CÁC HÀM/METHOD TRONG FILE:
 * - store(): queue sinh ảnh, trả 202 + AiImport resource.
 * - show(): đọc import theo id (chặn owner, chặn operation khác).
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : GenerateImagesRequest/Request đã authenticated + permission.
 * - OUTPUT: BaseResponse JSON wrap VisualStyleGenerationResource.
 * =====================================================================
 */
final class VisualStyleGenerationController extends Controller
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Queue batch sinh ảnh theo style + recipes.
     * =====================================================================
     * Input: GenerateImagesRequest (style_id, shot_recipe_ids[1-4], product,
     * aspect_ratio, image_count, instructions, model_id?).
     * Output: 202 BaseResponse wrap VisualStyleGenerationResource của import
     * queued; job xử lý bất đồng bộ.
     * Side effect: INSERT ai_imports + dispatch job sau commit qua service.
     * =====================================================================
     */
    public function store(GenerateImagesRequest $request, VisualStyleGenerationService $service): JsonResponse
    {
        $import = $service->generate($request->validated(), (int) $request->user()->id);

        return BaseResponse::success(
            new VisualStyleGenerationResource($import),
            config('messages.visual_style.generation_queued'),
            202,
        );
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đọc trạng thái + outputs của một generation run.
     * =====================================================================
     * Input: Request authenticated + AiImport route-bound.
     * Output: 200 BaseResponse wrap VisualStyleGenerationResource; 403 khi
     * không phải owner, 404 khi operation khác.
     * Side effect: chỉ đọc import; không query provider.
     * =====================================================================
     */
    public function show(Request $request, string $importId): JsonResponse
    {
        $import = AiImport::query()->whereKey($importId)->first();
        abort_unless($import && $import->operation === 'visual_style_generate', 404);
        abort_unless((int) $import->created_by === (int) $request->user()->id, 403);

        return BaseResponse::success(new VisualStyleGenerationResource($import));
    }
}
