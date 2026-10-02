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
 * - withConnection(), connection(): giữ/đọc snapshot connection server-side.
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

    private ?AiConnection $connection = null;

    /**
     * =====================================================================
     * CHỨC NĂNG: Chọn model từ request sau khi controller đã kiểm tra allowlist.
     * =====================================================================
     * INPUT: model key hoặc null.
     * OUTPUT: chính provider instance để chain; không gọi network.
     * SIDE EFFECT: clone provider state; không ghi database.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function withModel(?string $model): static
    {
        $instance = clone $this;
        $instance->requestedModel = $model ?: null;

        return $instance;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Bind a server-side run snapshot; the API key never enters the queue payload.
     * =====================================================================
     * INPUT: AiConnection server-side đã được resolver kiểm tra.
     * OUTPUT: clone adapter với connection immutable riêng cho run.
     * SIDE EFFECT: Không ghi database hoặc gọi provider.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    public function withConnection(AiConnection $connection): static
    {
        $instance = clone $this;
        $instance->connection = $connection;

        return $instance;
    }

    protected function connection(): ?AiConnection
    {
        return $this->connection;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: canonical source JSON for every adapter.
     * =====================================================================
     * INPUT: structured prompt context.
     * OUTPUT: canonical source JSON for every adapter.
     * SIDE EFFECT: Không ghi database hoặc gọi provider.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    protected function canonicalInput(array $input): string
    {
        return json_encode([
            'title' => $input['title'], 'content_html' => $input['content_html'],
            'language' => $input['language'], 'rewrite_style' => $input['rewrite_style'],
            'additional_instructions' => $input['user_instructions'], 'prompt_key' => $input['prompt_key'],
            'prompt_version' => $input['prompt_version'], 'schema_version' => $input['schema_version'],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả model override đã được controller allowlist.
     * =====================================================================
     * INPUT: Không có.
     * OUTPUT: model override hoặc null; chỉ đọc state provider.
     * SIDE EFFECT: Không ghi database hoặc gọi provider.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    protected function requestedModel(): ?string
    {
        return $this->requestedModel;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo structured output và chuẩn hóa thành canonical fields.
     * =====================================================================
     * INPUT: title, content, language, style, prompt key, instruction.
     * OUTPUT: mảng field allowlist với contract kiểu dữ liệu nêu rõ trong prompt;
     *   transport lỗi được chuyển thành exception.
     * SIDE EFFECT: có thể gọi provider HTTP; không ghi domain database.
     * EXCEPTION/TRANSACTION: AiImportException khi transport/schema lỗi; không transaction.
     *
     * @return array<string, mixed>
     *                              =====================================================================
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
                'instructions' => $prompt['instructions'].' Allowed fields: '.implode(', ', $schema['fields']).'. '
                    .'Return one flat JSON object, without wrapping fields in value objects. '
                    .'Use strings for text fields, including content_html (HTML string). '
                    .'Use JSON booleans for robots_index and robots_follow, and arrays of integer IDs '
                    .'for suggested_category_ids and suggested_tag_ids. Omit optional fields with no value; do not return null.',
                'user_instructions' => $instructions,
                'prompt_key' => $promptKey,
                'prompt_version' => $prompt['version'],
                'schema_version' => $prompt['schema'],
            ]);
        } catch (ConnectionException $exception) {
            throw new AiImportException('AI provider mất kết nối hoặc hết thời gian chờ. Hãy kiểm tra trạng thái request rồi thử lại thủ công.', 'AI_PROVIDER_TIMEOUT', false, $exception);
        }

        return $this->validatePayload($this->normalizePayload($payload));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Bóc output JSON từ response provider kiểu data/output/choices.
     * =====================================================================
     * INPUT: mixed payload từ transport.
     * OUTPUT: array JSON; lỗi malformed output ném AiImportException.
     * SIDE EFFECT: chỉ normalize payload trong memory.
     * EXCEPTION/TRANSACTION: AiImportException khi response/refusal không hợp lệ; không transaction.
     * =====================================================================
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
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra canonical output, loại field ngoài schema và sai kiểu.
     * =====================================================================
     * INPUT: array output đã parse.
     * OUTPUT: array canonical fields an toàn; null có nghĩa field chưa có giá trị,
     *   dùng fallback nguồn như field bị bỏ qua.
     * SIDE EFFECT: chỉ đọc schema và tạo array mới.
     * EXCEPTION/TRANSACTION: AiImportException khi field/type không hợp lệ; không transaction.
     * =====================================================================
     */
    protected function validatePayload(array $payload): array
    {
        $allowed = [
            'title', 'content_html', 'content', 'excerpt', 'focus_keyword',
            'seo_title', 'seo_description', 'canonical_url', 'robots_index',
            'robots_follow', 'og_title', 'og_description', 'suggested_category_ids',
            'suggested_tag_ids', 'thumbnail_prompt', 'thumbnail_alt_text',
        ];
        $result = array_filter(array_intersect_key($payload, array_flip($allowed)), static fn (mixed $value): bool => $value !== null);

        foreach (['title', 'content_html', 'content', 'excerpt', 'focus_keyword', 'seo_title', 'seo_description', 'canonical_url', 'og_title', 'og_description', 'thumbnail_prompt', 'thumbnail_alt_text'] as $field) {
            if (array_key_exists($field, $result) && ! is_string($result[$field])) {
                throw new AiImportException('AI provider trả sai kiểu dữ liệu cho '.$field.'.', 'AI_PROVIDER_SCHEMA');
            }
        }
        foreach (['robots_index', 'robots_follow'] as $field) {
            if (array_key_exists($field, $result) && ! is_bool($result[$field])) {
                throw new AiImportException('AI provider trả sai kiểu dữ liệu cho '.$field.'.', 'AI_PROVIDER_SCHEMA');
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
     * =====================================================================
     * CHỨC NĂNG: Gửi request theo giao thức riêng của provider.
     * =====================================================================
     * INPUT: context đã có prompt/schema/model.
     * OUTPUT: raw response payload; lỗi HTTP phải ném AiImportException.
     * SIDE EFFECT: gọi transport của provider.
     * EXCEPTION/TRANSACTION: Provider exception; không mở transaction.
     *
     * @param  array<string, mixed>  $input
     *                                       =====================================================================
     */
    abstract protected function requestPayload(array $input): mixed;
}
