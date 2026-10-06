<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** Validate địa chỉ nhận mail thử; quyền được kiểm tra trước gửi thật. */
final class SettingsTestMailRequest extends FormRequest
{
    /** Input: admin. Output: có quyền Settings hay không. */
    public function authorize(): bool
    {
        return $this->user()?->can('settings.manage') ?? false;
    }

    /** Output: chỉ cho phép địa chỉ email; không nhận nội dung hoặc credential. */
    public function rules(): array
    {
        return ['recipient' => ['required', 'email:rfc', 'max:254']];
    }
}
