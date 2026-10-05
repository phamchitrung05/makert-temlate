<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Whitelist dữ liệu người dùng duyệt khi tạo/sửa/xóa profile.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: authorize(), rules().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : tên/rules/hướng dẫn/version từ admin HTTP.
 * - OUTPUT: values allowlisted; origin/actor/metadata không lấy từ client.
 * - SIDE EFFECT: validation, không gọi AI hoặc ghi DB.
 * =====================================================================
 */
final class AiWritingProfileRequest extends FormRequest
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra quyền quản lý profile ngay tại FormRequest.
     * =====================================================================
     * Input: actor authenticated. Output: true khi có ai_settings.manage.
     * Side effect: đọc permissions; middleware route kiểm tra cùng quyền.
     * =====================================================================
     */
    public function authorize(): bool
    {
        return $this->user()?->can('ai_settings.manage') ?? false;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Validate payload theo thao tác create/update/delete.
     * =====================================================================
     * Input: HTTP method và payload. Output: rules typed, optimistic version bắt buộc.
     * Side effect: không ghi database; service đối chiếu evidence với source.
     * =====================================================================
     */
    public function rules(): array
    {
        if ($this->isMethod('DELETE')) {
            return ['version' => ['required', 'integer', 'min:1']];
        }
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'required', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'rules_json' => [$required, 'required', 'array', 'min:1', 'max:16'],
            'evidence_json' => ['sometimes', 'array', 'max:20'],
            'evidence_json.*' => ['required', 'array:feature,excerpt,explanation'],
            'evidence_json.*.feature' => ['required', 'string', 'max:80'],
            'evidence_json.*.excerpt' => ['required', 'string', 'max:300'],
            'evidence_json.*.explanation' => ['required', 'string', 'max:1000'],
            'style_instructions' => [$required, 'required', 'string', 'max:10000'],
            'is_enabled' => ['sometimes', 'required', 'boolean'],
            'analysis_id' => [$this->isMethod('POST') ? 'sometimes' : 'prohibited', 'nullable', 'uuid'],
            'version' => [$this->isMethod('POST') ? 'prohibited' : 'required', 'integer', 'min:1'],
        ];
    }
}
