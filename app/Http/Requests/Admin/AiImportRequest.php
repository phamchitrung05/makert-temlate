<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Validate URL và tùy chọn import AI của admin.
 * =====================================================================
 *
 * Request là boundary HTTP trước khi controller chuẩn hóa URL và dispatch
 * queue job. Rule không thay thế SSRF guard; ArticleSourceFetcher vẫn phải
 * kiểm tra DNS/IP và redirect ở runtime.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - prepareForValidation(): chuẩn hóa payload generic session về Post import.
 * - authorize(): xác nhận route middleware đã kiểm tra permission.
 * - rules(): whitelist URL, ngôn ngữ, prompt và thumbnail options.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : payload JSON từ admin đã authenticated.
 * - OUTPUT: dữ liệu hợp lệ hoặc response validation 422.
 * - SIDE EFFECT: không ghi database, không gọi provider.
 * =====================================================================
 */
class AiImportRequest extends FormRequest
{
    /**
     * Chuẩn hóa contract AI Agent generic về field import Post hiện tại.
     *
     * Input: payload có thể dùng input.type/url/text và output_language.
     * Output: request có url/text/language/generate_thumbnail top-level.
     * Side effect: merge dữ liệu vào request bag trước validation; không ghi DB.
     */
    protected function prepareForValidation(): void
    {
        $input = is_array($this->input('input')) ? $this->input('input') : [];
        $outputs = (array) $this->input('requested_outputs', []);

        $normalized = [
            'url' => $this->input('url', $input['url'] ?? null),
            'text' => $this->input('text', $input['text'] ?? null),
            'language' => $this->input('language', $this->input('output_language')),
        ];
        if ($this->has('generate_thumbnail')) {
            $normalized['generate_thumbnail'] = $this->boolean('generate_thumbnail');
        } elseif ($this->has('requested_outputs')) {
            $normalized['generate_thumbnail'] = in_array('thumbnail', $outputs, true);
        }

        $this->merge($normalized);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Cho phép request đi qua middleware permission
     * =====================================================================
     * INPUT: request admin đã qua auth/ability/account status.
     * OUTPUT: true để Laravel chạy rules.
     * SIDE EFFECT: không có.
     * EXCEPTION/TRANSACTION: không mở transaction; permission lỗi do middleware.
     * =====================================================================
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo rule cho URL và tùy chọn AI import
     * =====================================================================
     * INPUT: payload HTTP chưa tin cậy.
     * OUTPUT: mảng rule; lỗi trả về envelope validation 422 của ứng dụng.
     * SIDE EFFECT: không gọi network/provider và không ghi database.
     * EXCEPTION/TRANSACTION: Laravel ValidationException ở FormRequest boundary.
     * =====================================================================
     */
    public function rules(): array
    {
        return [
            'target_type' => ['nullable', 'in:post'],
            'operation' => ['nullable', 'in:create'],
            'url' => ['nullable', 'url', 'max:2048', 'required_without:text'],
            'text' => ['nullable', 'string', 'max:200000', 'required_without:url'],
            'language' => ['nullable', 'string', 'max:12'],
            'rewrite_style' => ['nullable', 'string', 'max:40'],
            'generate_thumbnail' => ['nullable', 'boolean'],
            'thumbnail_mode' => ['nullable', 'in:auto,source,generate'],
            'prompt_key' => ['nullable', 'string', 'max:120'],
            'instructions' => ['nullable', 'string', 'max:4000'],
            'provider' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:120'],
            'requested_outputs' => ['sometimes', 'array'],
            'requested_outputs.*' => ['string', 'distinct', 'in:title,excerpt,content,seo,taxonomy,thumbnail'],
        ];
    }
}
