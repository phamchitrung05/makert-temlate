<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate tên/bài tham khảo dán và lựa chọn model analysis.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: authorize(), rules().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : reference_text và model selection từ admin.
 * - OUTPUT: payload bounded; không nhận schema/prompt/key/endpoint client.
 * - SIDE EFFECT: không ghi DB/gọi provider.
 * =====================================================================
 */
final class AiWritingProfileAnalysisRequest extends FormRequest
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra ai_settings.manage.
     * =====================================================================
     * Input: actor HTTP. Output: quyền quản lý profile.
     * Side effect: chỉ đọc quyền.
     * =====================================================================
     */
    public function authorize(): bool
    {
        return $this->user()?->can('ai_settings.manage') ?? false;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Giới hạn bài mẫu và model selector.
     * =====================================================================
     * Input: request analysis. Output: allowlisted rules.
     * Side effect: resolver kiểm model thực dùng; không gọi AI tại validation.
     * =====================================================================
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'reference_text' => ['required', 'string', 'min:30', 'max:100000'],
            'model_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'provider' => ['sometimes', 'nullable', 'string', 'max:100'],
            'model' => ['sometimes', 'nullable', 'string', 'max:191'],
        ];
    }
}
