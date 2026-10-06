<?php

namespace App\Http\Requests\Admin;

use App\Services\Settings\ProjectSettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate theo nhóm Settings; payload partial, version bắt buộc.
 * Input: HTTP admin. Output: allowlisted values; không ghi dữ liệu.
 */
final class ProjectSettingsRequest extends FormRequest
{
    /** Route vẫn bảo vệ quyền; kiểm tra thêm để request dùng đúng guard. */
    public function authorize(): bool
    {
        return $this->user()?->can('settings.manage') ?? false;
    }

    /** Output: rules tương ứng capability thực tế; secret chỉ nhận chiều ghi. */
    public function rules(ProjectSettingsService $settings): array
    {
        $rules = match ($this->route('group')) {
            'site' => [
                'site_name' => ['sometimes', 'required', 'string', 'max:120'],
                'site_url' => ['sometimes', 'required', 'url:http,https', 'max:2048'],
                'site_description' => ['nullable', 'string', 'max:1000'],
                'contact_email' => ['nullable', 'email:rfc', 'max:254'],
                'timezone' => ['sometimes', 'required', 'timezone:all'],
            ],
            'media' => [
                ...array_fill_keys(['image_max_size_kb', 'document_max_size_kb', 'video_max_size_kb', 'archive_max_size_kb'], ['sometimes', 'required', 'integer', 'min:1', 'max:'.(int) (config('media-library.max_file_size') / 1024)]),
                'allowed_extensions' => ['sometimes', 'required', 'array', 'min:1'],
                'allowed_extensions.*' => ['required', 'string', 'distinct', Rule::in($settings->defaults('media')['allowed_extensions'])],
                'conversion_format' => ['sometimes', 'required', Rule::in(['jpg', 'png', 'webp'])],
                'conversion_quality' => ['sometimes', 'required', 'integer', 'between:1,100'],
            ],
            'seo' => [
                'title_format' => ['sometimes', 'required', 'string', 'max:200', 'regex:/%title%/'],
                'default_description' => ['nullable', 'string', 'max:1000'],
                'robots_txt' => ['nullable', 'string', 'max:10000'],
            ],
            'mail' => [
                'mailer' => ['sometimes', 'required', Rule::in(array_keys(config('mail.mailers')))],
                'host' => ['sometimes', 'required', 'string', 'max:253', 'regex:/^[a-zA-Z0-9.:-]+$/'],
                'port' => ['sometimes', 'required', 'integer', 'between:1,65535'],
                'scheme' => ['sometimes', 'required', Rule::in(['smtp', 'smtps'])],
                'username' => ['nullable', 'string', 'max:254'],
                'password' => ['nullable', 'string', 'max:1000'],
                'from_name' => ['sometimes', 'required', 'string', 'max:120'],
                'from_address' => ['sometimes', 'required', 'email:rfc', 'max:254'],
            ],
            'security' => [
                'token_expiration_days' => ['sometimes', 'required', 'integer', 'between:1,90'],
                'login_max_attempts' => ['sometimes', 'required', 'integer', 'between:1,30'],
                'login_decay_minutes' => ['sometimes', 'required', 'integer', 'between:1,60'],
            ],
            'languages' => [
                'default_locale' => ['sometimes', 'required', Rule::in(array_column($settings->locales(), 'value'))],
                'enabled_locales' => ['sometimes', 'required', 'array', 'min:1'],
                'enabled_locales.*' => ['required', 'distinct', Rule::in(array_column($settings->locales(), 'value'))],
            ],
        };

        return ['version' => ['required', 'integer', 'min:0']] + $rules;
    }

    /** Kiểm locale mặc định nằm trong tập bật; dùng giá trị đã lưu khi partial. */
    public function after(): array
    {
        return [function ($validator): void {
            if ($this->route('group') !== 'languages' || $validator->errors()->isNotEmpty()) {
                return;
            }
            $values = array_replace(app(ProjectSettingsService::class)->effective('languages'), $this->only(['default_locale', 'enabled_locales']));
            if (! in_array($values['default_locale'], $values['enabled_locales'], true)) {
                $validator->errors()->add('default_locale', 'Ngôn ngữ mặc định phải được bật.');
            }
        }];
    }
}
