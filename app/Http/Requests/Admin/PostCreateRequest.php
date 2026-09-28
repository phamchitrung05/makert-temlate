<?php

namespace App\Http\Requests\Admin;

use App\Enums\PostStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Validate payload tạo Post và media fields riêng của Post. */
class PostCreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            'status' => ['sometimes', 'string', Rule::in(PostStatus::values())],
            'media' => ['sometimes', 'array'],
            'media.thumbnail_id' => ['nullable', 'integer', 'min:1'],
            'media.content_image_ids' => ['sometimes', 'array'],
            'media.content_image_ids.*' => ['integer', 'distinct', 'min:1'],
        ];
    }
}
