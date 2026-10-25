<?php

namespace App\Http\Controllers\Admin\Ai\Image;

use App\Http\Controllers\Controller;
use App\Http\Responses\BaseResponse;
use App\Models\AiImageProfile;
use App\Services\Ai\Images\ImageProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

final class AiImageProfileController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $page = AiImageProfile::where('created_by', $request->user()->id)->latest('id')->paginate(20);

        return BaseResponse::success($page);
    }

    public function options(Request $request): JsonResponse
    {
        return BaseResponse::success(AiImageProfile::where('created_by', $request->user()->id)
            ->where('status', 'ready')->where('is_enabled', true)->orderBy('name')->get(['id', 'name']));
    }

    public function store(Request $request, ImageProfileService $service): JsonResponse
    {
        $values = $request->validate([
            'name' => ['required', 'string', 'max:160'], 'model_id' => ['required', 'integer', 'min:1'],
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=8192,max_height=8192'],
        ]);

        return BaseResponse::success($service->queue($values, $request->file('image'), (int) $request->user()->id), 'Đã xếp hàng phân tích ảnh.', 202);
    }

    public function show(Request $request, AiImageProfile $imageProfile): JsonResponse
    {
        $this->owner($request, $imageProfile);

        return BaseResponse::success($imageProfile);
    }

    public function update(Request $request, AiImageProfile $imageProfile): JsonResponse
    {
        $this->owner($request, $imageProfile);
        abort_unless($imageProfile->status === 'ready', 409, 'Chờ phân tích ảnh hoàn tất trước khi sửa prompt.');
        $imageProfile->update($request->validate([
            'name' => ['required', 'string', 'max:160'], 'prompt' => ['required', 'string', 'min:20', 'max:6000'],
            'negative_prompt' => ['nullable', 'string', 'max:2000'], 'is_enabled' => ['required', 'boolean'],
        ]));

        return BaseResponse::success($imageProfile->fresh(), 'Đã lưu prompt ảnh.');
    }

    public function destroy(Request $request, AiImageProfile $imageProfile): JsonResponse
    {
        $this->owner($request, $imageProfile);
        abort_if(in_array($imageProfile->status, ['queued', 'analyzing'], true), 409, 'Chờ phân tích hoàn tất trước khi xóa.');
        $path = $imageProfile->reference_path;
        $imageProfile->delete();
        Storage::disk('local')->delete($path);

        return BaseResponse::success(null, 'Đã xóa prompt ảnh.');
    }

    private function owner(Request $request, AiImageProfile $profile): void
    {
        abort_unless((int) $profile->created_by === (int) $request->user()->id, 403);
    }
}
