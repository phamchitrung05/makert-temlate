<?php

namespace App\Http\Requests\Admin;

use App\Services\Ai\Registries\TargetRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
 * - prepareForValidation(): chuẩn hóa payload generic session về content import.
 * - authorize(): xác nhận route middleware đã kiểm tra permission.
 * - messages(): giải thích phạm vi file nguồn được hỗ trợ.
 * - rules(): whitelist URL, ngôn ngữ, prompt và thumbnail options.
 * - after(): yêu cầu đúng một nguồn, kiểm encoding và byte HTML.
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
     * =====================================================================
     * CHỨC NĂNG: Chuẩn hóa contract AI Agent generic về field import Post.
     * =====================================================================
     * INPUT: payload có thể dùng input.type/url/text và output_language.
     * OUTPUT: request có url/text/language/generate_thumbnail top-level.
     * SIDE EFFECT: merge dữ liệu vào request bag trước validation; không ghi DB.
     * EXCEPTION/TRANSACTION: không ném lỗi nghiệp vụ; FormRequest rules xử lý validation.
     * =====================================================================
     */
    protected function prepareForValidation(): void
    {
        $input = is_array($this->input('input')) ? $this->input('input') : [];
        $outputs = (array) $this->input('requested_outputs', []);

        $normalized = [
            'url' => $this->input('url', $input['url'] ?? null),
            'text' => $this->input('text', $input['text'] ?? null),
            'title' => $this->input('title', $input['title'] ?? null),
            'html' => $this->input('html', $input['html'] ?? null),
            'language' => $this->input('language', $this->input('output_language')),
        ];
        if ($this->has('requested_outputs')) {
            $normalized['generate_thumbnail'] = in_array('thumbnail', $outputs, true);
            $normalized['generate_seo'] = in_array('seo', $outputs, true);
        } elseif ($this->has('generate_thumbnail')) {
            $normalized['generate_thumbnail'] = $this->boolean('generate_thumbnail');
        }

        $this->merge($normalized);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra permission của target khai báo trong config
     * =====================================================================
     * INPUT: request admin đã qua auth/ability/account status.
     * OUTPUT: true khi actor có quyền của target; target không tồn tại trả 422 qua rules.
     * SIDE EFFECT: không có.
     * EXCEPTION/TRANSACTION: không mở transaction; permission lỗi do middleware.
     * =====================================================================
     */
    public function authorize(): bool
    {
        $key = (string) ($this->input('target_type') ?: 'post');
        $target = app(TargetRegistry::class)->all()[$key] ?? null;

        return ! $target || $this->user()?->can($target['permission'] ?? 'posts.manage');
    }

    /** Input: lỗi extension nguồn. Output: thông báo phạm vi HTML; không có side effect. */
    public function messages(): array
    {
        return ['html_file.extensions' => 'Nguồn bài viết chỉ nhận file .html hoặc .htm. File .mhtml/.mht chưa được hỗ trợ.'];
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
            'target_type' => ['nullable', Rule::in(array_keys(app(TargetRegistry::class)->all()))],
            'operation' => ['nullable', 'in:create'],
            'url' => ['nullable', 'url', 'max:2048', 'required_without_all:text,html,html_file'],
            'text' => ['nullable', 'string', 'max:200000', 'required_without_all:url,html,html_file'],
            'html' => ['nullable', 'string', 'max:5242880', 'required_without_all:url,text,html_file'],
            'html_file' => ['nullable', 'file', 'max:5120', 'extensions:html,htm', 'mimetypes:text/html,application/xhtml+xml,text/plain'],
            'source_encoding' => ['nullable', Rule::in(['UTF-8', 'Windows-1252', 'ISO-8859-1'])],
            'title' => ['nullable', 'string', 'max:255'],
            'language' => ['nullable', 'string', 'max:12'],
            'rewrite_style' => ['nullable', 'string', 'max:40'],
            'generate_thumbnail' => ['nullable', 'boolean'],
            'generate_seo' => ['nullable', 'boolean'],
            'thumbnail_mode' => ['nullable', 'in:auto,source,generate'],
            'thumbnail_prompt' => ['nullable', 'string', 'max:4000'],
            'prompt_key' => ['nullable', 'string', 'max:120'],
            'instructions' => ['nullable', 'string', 'max:4000'],
            'writing_profile_id' => ['nullable', 'integer', 'min:1', 'exists:ai_writing_profiles,id'],
            'writing_brief' => ['sometimes', 'array:audience,article_type,purpose,angle,length'],
            'writing_brief.audience' => ['nullable', 'string', 'max:500'],
            'writing_brief.article_type' => ['nullable', 'string', 'max:120'],
            'writing_brief.purpose' => ['nullable', 'string', 'max:1000'],
            'writing_brief.angle' => ['nullable', 'string', 'max:1000'],
            'writing_brief.length' => ['nullable', 'string', 'max:120'],
            'category_ids' => ['sometimes', 'array', 'max:100'],
            'category_ids.*' => ['integer', 'distinct', Rule::exists('categories', 'id')->where('status', 'active')->whereNull('deleted_at')],
            'tag_ids' => ['sometimes', 'array', 'max:100'],
            'tag_ids.*' => ['integer', 'distinct', Rule::exists('tags', 'id')->where('status', 'active')->whereNull('deleted_at')],
            'provider' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:190'],
            'model_id' => ['nullable', 'integer', 'min:1'],
            'image_provider' => ['nullable', 'string', 'max:80'],
            'image_model' => ['nullable', 'string', 'max:190'],
            'image_model_id' => ['nullable', 'integer', 'min:1'],
            'requested_outputs' => ['sometimes', 'array', 'min:1'],
            'requested_outputs.*' => ['string', 'distinct', Rule::in((array) config('ai.agent.targets.'.($this->input('target_type') ?: 'post').'.outputs', []))],
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm đúng một nguồn và giới hạn/encoding HTML thật
     * =====================================================================
     * INPUT: validator sau rules cơ bản và file upload đã qua Laravel.
     * OUTPUT: lỗi 422 khi nguồn nhập trùng, HTML quá byte hoặc encoding không đúng.
     * SIDE EFFECT: chỉ đọc file tạm trong memory; không gọi nguồn từ xa/AI.
     * =====================================================================
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $sourceCount = (int) filled($this->input('url')) + (int) filled($this->input('text'))
                + (int) filled($this->input('html')) + (int) $this->hasFile('html_file');
            if ($sourceCount > 1) {
                $validator->errors()->add('source', 'Chỉ gửi một nguồn: URL, text, HTML hoặc file HTML.');
            }
            $html = $this->hasFile('html_file') && $this->file('html_file')->isValid()
                ? (string) $this->file('html_file')->getContent() : (string) $this->input('html', '');
            if (strlen($html) > (int) config('ai.import.max_html_bytes', 5242880)) {
                $validator->errors()->add('html', 'HTML vượt giới hạn 5 MB.');
            }
            if ($html !== '' && ($this->input('source_encoding', 'UTF-8') ?? 'UTF-8') === 'UTF-8' && ! mb_check_encoding($html, 'UTF-8')) {
                $validator->errors()->add('source_encoding', 'Nguồn không phải UTF-8. Hãy khai báo encoding của file.');
            }
        }];
    }
}
