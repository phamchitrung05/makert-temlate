<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\BaseResponse;
use App\Services\SlugService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Preview slug đa model qua allowlist và quyền model cấu hình.
 * CÁC HÀM/METHOD TRONG FILE: __invoke(): validate, authorize, gọi SlugService;
 * canPreview(): kiểm tra permission create/update trực tiếp từ Spatie.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): title/model_type/model_id -> JSON slug.
 * Chỉ đọc DB, không giữ chỗ slug hoặc thay đổi model.
 * =====================================================================
 */
class SlugPreviewController extends Controller
{
    /**
     * Input: request admin có title/model_type/model_id và SlugService.
     * Output: JSON hoặc 403/404/422; không instantiate class từ input.
     * Quyền: permission `create` trong config đủ để preview slug, kể cả edit có
     * model_id; permission `update` cũng được chấp nhận cho luồng chỉnh sửa.
     */
    public function __invoke(Request $request, SlugService $service): JsonResponse
    {
        $models = config('slug-models');
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'model_type' => ['required', 'string', Rule::in(array_keys($models))],
            'model_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $definition = $models[$data['model_type']];
        $hasModelId = isset($data['model_id']);
        if (! $this->canPreview($request, $definition, $hasModelId)) {
            return BaseResponse::error(
                'Tài khoản không có quyền tạo slug cho model này.',
                Response::HTTP_FORBIDDEN,
                [],
                [
                    'code' => 'SLUG_PERMISSION_DENIED',
                    'model_type' => $data['model_type'],
                ],
            );
        }
        $modelClass = $definition['model'];
        $model = new $modelClass;
        if (isset($data['model_id'])) {
            $model = $model->newQuery()->findOrFail($data['model_id']);
        }

        return BaseResponse::success([
            'slug' => $service->previewForModel($model, $data['title']),
            'model_type' => $data['model_type'],
        ]);
    }

    /**
     * Kiểm tra quyền preview theo đúng permission catalog của model.
     *
     * Input: request đã xác thực, định nghĩa model trong config và có model ID hay không.
     * Output: bool; permission create luôn đủ, update chỉ dùng cho luồng có model ID.
     * Side effect: không ghi database; Spatie tự đọc cache permission/role hiện tại.
     */
    private function canPreview(Request $request, array $definition, bool $hasModelId): bool
    {
        $permissions = (array) $definition['create'];
        if ($hasModelId) {
            $permissions = array_merge($permissions, (array) $definition['update']);
        }

        foreach (array_unique($permissions) as $permission) {
            if ($request->user()->can($permission)) {
                return true;
            }
        }

        return false;
    }
}
