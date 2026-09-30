<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** Request validate URL và tùy chọn import AI của admin. */
class AiImportRequest extends FormRequest
{
    /** Input: request admin. Output: true vì route đã yêu cầu quyền posts.manage. */
    public function authorize(): bool
    {
        return true;
    }

    /** Input: payload HTTP. Output: rule URL/options cho validation 422. */
    public function rules(): array
    {
        return ['url' => ['required', 'url', 'max:2048'], 'language' => ['nullable', 'string', 'max:12'], 'rewrite_style' => ['nullable', 'string', 'max:40'], 'generate_thumbnail' => ['nullable', 'boolean']];
    }
}
