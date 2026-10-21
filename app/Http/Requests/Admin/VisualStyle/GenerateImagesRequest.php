<?php

namespace App\Http\Requests\Admin\VisualStyle;

use Illuminate\Foundation\Http\FormRequest;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Whitelist payload queue sinh ảnh theo Visual Style.
 * =====================================================================
 * Chặn ở cổng HTTP: style tồn tại, danh sách shot id nguyên và tối đa 4,
 * product shape (media_ids int + description + category), aspect_ratio dạng
 * `W:H`, image_count trong khoảng config `ai.content.social_images`,
 * instructions tối đa 10 dòng và model_id tuỳ chọn khi caller muốn override
 * image model. Business check (style active, recipe thuộc style, model
 * khả dụng) nằm ở service để FormRequest chỉ lo shape và quyền.
 * CÁC HÀM/METHOD TRONG FILE: authorize(), rules(), messages().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : admin HTTP có ai_settings.manage.
 * - OUTPUT: values allowlisted; không ghi DB/gọi provider.
 * =====================================================================
 */
final class GenerateImagesRequest extends FormRequest
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra quyền quản lý AI settings tại FormRequest.
     * =====================================================================
     * Input: actor authenticated. Output: true khi có ai_settings.manage.
     * Side effect: đọc permission; route middleware kiểm cùng quyền.
     * =====================================================================
     */
    public function authorize(): bool
    {
        return $this->user()?->can('ai_settings.manage') ?? false;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Khai rule cho payload generate.
     * =====================================================================
     * Input: HTTP payload. Output: rules; aspect_ratio chặn định dạng sai
     * ngay ở cổng, count giới theo config social_images (min/max).
     * =====================================================================
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $images = (array) config('ai.content.social_images', []);
        $min = max(1, (int) ($images['min'] ?? 1));
        $max = max(1, (int) ($images['max'] ?? 10));

        return [
            'style_id' => ['required', 'integer', 'min:1'],
            'shot_recipe_ids' => ['required', 'array', 'min:1', 'max:4'],
            'shot_recipe_ids.*' => ['integer', 'min:1', 'distinct'],
            'product' => ['sometimes', 'array'],
            'product.media_ids' => ['sometimes', 'array', 'max:8'],
            'product.media_ids.*' => ['integer', 'min:1', 'distinct'],
            'product.description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'product.category' => ['sometimes', 'nullable', 'string', 'max:80'],
            'aspect_ratio' => ['sometimes', 'string', 'regex:/^\d{1,2}:\d{1,2}$/'],
            'image_count' => ['sometimes', 'integer', "min:{$min}", "max:{$max}"],
            'instructions' => ['sometimes', 'array', 'max:10'],
            'instructions.*' => ['string', 'max:500'],
            'model_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Message lỗi ngắn, an toàn cho admin.
     * =====================================================================
     * Input: key rule mặc định. Output: message tiếng Việt cho các rule quan
     * trọng; còn lại dùng fallback Laravel.
     * =====================================================================
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'shot_recipe_ids.required' => 'Chọn ít nhất một shot recipe.',
            'shot_recipe_ids.max' => 'Một lần sinh chỉ nhận tối đa 4 shot recipe.',
            'aspect_ratio.regex' => 'Tỉ lệ khung hình không hợp lệ (ví dụ 1:1, 4:5).',
        ];
    }
}
