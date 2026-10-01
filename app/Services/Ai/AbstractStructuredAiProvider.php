<?php

namespace App\Services\Ai;

use App\Exceptions\AiImportException;
use App\Services\Ai\Contracts\AiProviderContract;
use App\Services\Ai\Registries\PromptRegistry;
use App\Services\Ai\Registries\SchemaRegistry;
use Illuminate\Http\Client\ConnectionException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Chuẩn hóa structured output cho các provider AI HTTP.
 * =====================================================================
 *
 * Lớp này giữ logic prompt/schema/allowlist dùng chung. Provider cụ thể chỉ
 * triển khai transport và nhận payload theo API của mình, không được ghi domain.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - generate(): tạo request context và validate canonical output.
 * - withModel(): chọn model đã được registry allowlist.
 * - requestedModel(): đọc model override để ghi provenance.
 * - normalizePayload(): bóc JSON khỏi các response shape phổ biến.
 * - validatePayload(): kiểm tra field/type trước khi đưa vào domain.
 * - requestPayload(): contract transport cho provider con.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : title/content đã sanitize, prompt key và instruction.
 * - OUTPUT: canonical fields; ném AiImportException khi provider/schema lỗi.
 * =====================================================================
 */
abstract class AbstractStructuredAiProvider implements AiProviderContract
{
    private ?string $requestedModel = null;

    /**
     * Chọn model từ request sau khi controller đã kiểm tra allowlist.
     *
     * Input: model key hoặc null.
     * Output: chính provider instance để chain; không gọi network.
     */
    public function withModel(?string $model): static
    {
        $this->requestedModel = $model ?: null;

        return $this;
    }

    /**
     * Trả model override đã được controller allowlist.
     *
     * Input: Không có.
     * Output: model override hoặc null; chỉ đọc state provider.
     */
    protected function requestedModel(): ?string
    {
        return $this->requestedModel;
    }

    /**
     * Tạo structured output và chuẩn hóa thành canonical fields.
     *
     * Input: title, content, language, style, prompt key, instruction.
     * Output: mảng field allowlist; transport lỗi được chuyển thành exception.
     *
     * @return array<string, mixed>
     */
    public function generate(
        string $title,
        string $content,
        string $language = 'vi',
        string $rewriteStyle = 'informative',
        string $promptKey = 'post.create.from_url',
        string $instructions = '',
    ): array {
        if (! $this->configured()) {
            return [];
        }

        $prompt = (new PromptRegistry)->get($promptKey, 'post', 'create');
        $schema = (new SchemaRegistry)->get($prompt['schema']);
        try {
            $payload = $this->requestPayload([
                'model' => $this->requestedModel ?: $this->modelName(),
                'title' => $title,
                'content_html' => $content,
                'language' => $language,
                'rewrite_style' => $rewriteStyle,
                'instructions' => $prompt['instructions'].' Allowed fields: '.implode(', ', $schema['fields']).'.',
                'user_instructions' => $instructions,
                'prompt_key' => $promptKey,
                'prompt_version' => $prompt['version'],
                'schema_version' => $prompt['schema'],
            ]);
        } catch (ConnectionException $exception) {
            throw new AiImportException('AI provider kết nối thất bại hoặc quá thời gian.', 'AI_PROVIDER_TIMEOUT', true, $exception);
        }

        return $this->validatePayload($this->normalizePayload($payload));
    }

    /**
     * Bóc output JSON từ response provider kiểu data/output/choices.
     *
     * Input: mixed payload từ transport.
     * Output: array JSON; lỗi malformed output ném AiImportException.
     */
    protected function normalizePayload(mixed $payload): array
    {
        if (is_array($payload) && (filled(data_get($payload, 'choices.0.message.refusal')) || filled(data_get($payload, 'promptFeedback.blockReason')))) {
            throw new AiImportException('AI provider từ chối xử lý nội dung nguồn.', 'AI_PROVIDER_REFUSAL');
        }
        if (is_array($payload) && isset($payload['data'])) {
            $payload = $payload['data'];
        }
        if (is_array($payload) && isset($payload['output']) && is_string($payload['output'])) {
            $payload = json_decode($payload['output'], true);
        }
        if (is_array($payload) && isset($payload['choices'][0]['message']['content'])) {
            $payload = json_decode((string) $payload['choices'][0]['message']['content'], true);
        }
        if (is_array($payload) && isset($payload['candidates'][0]['content']['parts'][0]['text'])) {
            $payload = json_decode((string) $payload['candidates'][0]['content']['parts'][0]['text'], true);
        }

        if (! is_array($payload) || $payload === []) {
            throw new AiImportException('AI provider trả JSON không hợp lệ.', 'AI_PROVIDER_INVALID_JSON');
        }

        return $payload;
    }

    /**
     * Kiểm tra canonical output, loại field ngoài schema và sai kiểu.
     *
     * Input: array output đã parse.
     * Output: array canonical fields an toàn cho ArticleImportService.
     */
    protected function validatePayload(array $payload): array
    {
        $allowed = [
            'title', 'content_html', 'content', 'excerpt', 'focus_keyword',
            'seo_title', 'seo_description', 'canonical_url', 'robots_index',
            'robots_follow', 'og_title', 'og_description', 'suggested_category_ids',
            'suggested_tag_ids', 'thumbnail_prompt', 'thumbnail_alt_text',
        ];
        $result = array_intersect_key($payload, array_flip($allowed));

        foreach (['title', 'content_html', 'content', 'excerpt', 'focus_keyword', 'seo_title', 'seo_description', 'canonical_url', 'og_title', 'og_description', 'thumbnail_prompt', 'thumbnail_alt_text'] as $field) {
            if (array_key_exists($field, $result) && ! is_string($result[$field])) {
                throw new AiImportException('AI provider trả sai kiểu dữ liệu.', 'AI_PROVIDER_SCHEMA');
            }
        }
        foreach (['robots_index', 'robots_follow'] as $field) {
            if (array_key_exists($field, $result) && ! is_bool($result[$field])) {
                throw new AiImportException('AI provider trả sai kiểu dữ liệu.', 'AI_PROVIDER_SCHEMA');
            }
        }
        foreach (['suggested_category_ids', 'suggested_tag_ids'] as $field) {
            if (array_key_exists($field, $result) && ! is_array($result[$field])) {
                throw new AiImportException('AI provider trả sai taxonomy.', 'AI_PROVIDER_SCHEMA');
            }
        }

        return $result;
    }

    /**
     * Gửi request theo giao thức riêng của provider.
     *
     * Input: context đã có prompt/schema/model.
     * Output: raw response payload; lỗi HTTP phải ném AiImportException.
     *
     * @param  array<string, mixed>  $input
     */
    abstract protected function requestPayload(array $input): mixed;
}
