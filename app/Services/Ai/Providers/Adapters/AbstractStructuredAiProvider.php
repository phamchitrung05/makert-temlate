<?php

namespace App\Services\Ai\Providers\Adapters;

use App\Exceptions\AiImportException;
use App\Services\Ai\Content\AiOutputValidator;
use App\Services\Ai\Content\AiTaskSchemaValidator;
use App\Services\Ai\Contracts\AiProviderContract;
use App\Services\Ai\Contracts\AiResponseMetadataProvider;
use App\Services\Ai\Data\AiTaskRequest;
use App\Services\Ai\Data\AiTaskResponse;
use App\Services\Ai\Providers\Diagnostics\AiResponseDiagnostics;
use App\Services\Ai\Providers\Transport\AiConnection;
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
 * - execute(): thực thi schema độc lập nhiệm vụ, giữ guard response task 1.
 * - responseMetadata(), responseFormat(), canonicalInput(), withRunSettings(), runSettings(), withOutputFields().
 * - decodeObject(), decodeJsonObject(), captureMetadata(), rethrowWithDiagnostics().
 * - normalizePayload(): bóc JSON khỏi các response shape phổ biến.
 * - validatePayload(): kiểm tra field/type trước khi đưa vào domain.
 * - requestPayload(): contract transport cho provider con.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : title/content đã sanitize, prompt key và instruction.
 * - OUTPUT: canonical fields; ném AiImportException khi provider/schema lỗi.
 * =====================================================================
 */
abstract class AbstractStructuredAiProvider implements AiProviderContract, AiResponseMetadataProvider
{
    private ?string $requestedModel = null;

    private ?AiConnection $connection = null;

    private array $runSettings = [];

    /** Null keeps the legacy complete response; an empty selection requests no AI fields. */
    private ?array $outputFields = null;

    private array $responseMetadata = [];

    /**
     * =====================================================================
     * Input: không có; dùng metadata request gần nhất.
     * Output: diagnostics đã redaction/allowlist; không ghi DB hoặc gọi HTTP.
     * =====================================================================
     */
    public function responseMetadata(): array
    {
        return AiResponseDiagnostics::sanitize($this->responseMetadata);
    }

    /**
     * =====================================================================
     * Input: không có.
     * Output: envelope HTTP JSON mặc định cho response parser; không side effect.
     * =====================================================================
     */
    protected function responseFormat(): string
    {
        return 'http-json';
    }

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

    /**
     * =====================================================================
     * Input: không có.
     * Output: connection server-side hoặc null; không đưa key vào queue metadata.
     * =====================================================================
     */
    protected function connection(): ?AiConnection
    {
        return $this->connection;
    }

    /**
     * =====================================================================
     * Input: immutable tuning snapshot.
     * Output: clone adapter với settings riêng cho run; không có side effect.
     * =====================================================================
     */
    public function withRunSettings(array $settings): static
    {
        $instance = clone $this;
        $instance->runSettings = $settings;

        return $instance;
    }

    /**
     * =====================================================================
     * Input: không có.
     * Output: settings connection snapshot hoặc tuning snapshot; không side effect.
     * =====================================================================
     */
    protected function runSettings(): array
    {
        return $this->connection()?->snapshot ?? $this->runSettings;
    }

    /**
     * =====================================================================
     * Input: output groups allowlist của run.
     * Output: clone adapter chỉ generate nhóm đó; execute() có schema riêng.
     * =====================================================================
     */
    public function withOutputFields(array $fields): static
    {
        $instance = clone $this;
        $instance->outputFields = array_values(array_unique(array_map('strval', $fields)));

        return $instance;
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
        if (isset($input['task_input'])) {
            return json_encode([
                'task' => $input['task'], 'input' => $input['task_input'],
                'prompt_key' => $input['prompt_key'], 'prompt_version' => $input['prompt_version'],
                'schema_version' => $input['schema_version'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

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
     * Input: AiTaskRequest với input dữ liệu, prompt và JSON Schema riêng.
     * Output: AiTaskResponse đã validate; ném lỗi timeout/envelope/schema an toàn.
     * SIDE EFFECT: gọi transport một lần; không retry ngầm hoặc ghi domain DB.
     * =====================================================================
     */
    public function execute(AiTaskRequest $request): AiTaskResponse
    {
        $this->responseMetadata = ['stage' => 'request', 'schema_version' => $request->options['schema_version'] ?? $request->task.'.v1'];
        if (! $this->configured()) {
            throw new AiImportException('Provider AI chưa được cấu hình cho nhiệm vụ.', 'AI_PROVIDER_NOT_CONFIGURED');
        }
        try {
            $this->responseMetadata['stage'] = 'transport';
            $payload = $this->requestPayload([
                'task' => $request->task, 'task_input' => $request->input,
                'model' => $request->options['model'] ?? ($this->requestedModel ?: $this->modelName()),
                'instructions' => $request->systemInstructions.' Return exactly one JSON object matching this JSON Schema: '
                    .json_encode($request->schema, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'prompt_key' => $request->options['prompt_key'] ?? $request->task,
                'prompt_version' => $request->options['prompt_version'] ?? '1.0',
                'schema_version' => $request->options['schema_version'] ?? $request->task.'.v1',
                'json_schema' => $request->schema,
                'title' => '', 'content_html' => '', 'language' => '', 'rewrite_style' => '', 'user_instructions' => '',
            ]);
            $output = $this->normalizePayload($payload);
            $output = (new AiTaskSchemaValidator)->validate($output, $request->schema);
            $this->responseMetadata['stage'] = 'validate';

            return new AiTaskResponse($output, $this->responseMetadata());
        } catch (ConnectionException $exception) {
            throw new AiImportException('AI provider mất kết nối hoặc hết thời gian chờ. Hãy kiểm tra request rồi thử lại thủ công.', 'AI_PROVIDER_TIMEOUT', false, $exception, $this->responseMetadata());
        } catch (AiImportException $exception) {
            $this->rethrowWithDiagnostics($exception);
        }
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
        $this->responseMetadata = ['stage' => 'request', 'requested_groups' => $this->outputFields ?? (array) config('ai.agent.legacy_required_outputs', ['title', 'content'])];
        if (! $this->configured()) {
            $this->responseMetadata['stage'] = 'skipped';

            return [];
        }

        // Target đã được kiểm tra ở controller/pipeline; transport dùng schema của prompt được chọn.
        $prompt = (new PromptRegistry)->get($promptKey, null, 'create');
        $schema = (new SchemaRegistry)->get($prompt['schema']);
        $this->responseMetadata['schema_version'] = $prompt['schema'];
        $runSettings = $this->runSettings();
        $systemPrompt = trim((string) ($runSettings['system_prompt'] ?? ''));
        $minWords = (int) ($runSettings['min_word_count'] ?? 0);
        $generateSeo = $this->outputFields === null
            ? (bool) ($runSettings['generate_seo'] ?? true)
            : in_array('seo', $this->outputFields, true);
        $allowedFields = $schema['fields'];
        if ($this->outputFields !== null) {
            $selectedFields = [];
            foreach ($this->outputFields as $group) {
                $selectedFields = array_merge($selectedFields, (array) config('ai.agent.output_definitions.'.$group.'.fields', []));
            }
            $allowedFields = array_values(array_intersect($allowedFields, $selectedFields));
            if ($allowedFields === []) {
                $this->responseMetadata['stage'] = 'skipped';

                return [];
            }
        }
        if (! $generateSeo) {
            $allowedFields = array_values(array_diff($allowedFields, ['focus_keyword', 'seo_title', 'seo_description', 'og_title', 'og_description']));
        }
        $contentInstructions = ($systemPrompt === '' ? '' : $systemPrompt.' ')
            .$prompt['instructions']
            .($minWords > 0 && in_array('content_html', $allowedFields, true) ? ' Aim for at least '.$minWords.' words in content_html unless additional_instructions explicitly request a different length.' : '')
            .($generateSeo ? '' : ' Do not generate focus_keyword, seo_title, seo_description, og_title or og_description.')
            .($this->outputFields === null ? '' : ' Generate only the selected fields listed below. Use the source as context and leave every other field unchanged.')
            .' '.(new AiOutputValidator)->instructions($this->outputFields);
        try {
            $this->responseMetadata['stage'] = 'transport';
            $payload = $this->requestPayload([
                'model' => $this->requestedModel ?: $this->modelName(),
                'title' => $title,
                'content_html' => $content,
                'language' => $language,
                'rewrite_style' => $rewriteStyle,
                'instructions' => $contentInstructions.' Allowed fields: '.implode(', ', $allowedFields).'. '
                    .'Return one flat JSON object, without wrapping fields in value objects. '
                    .'Use strings for text fields, including content_html (HTML string). '
                    .'Use JSON booleans for robots_index and robots_follow. Never return taxonomy, IDs, slug, actor or publishing status. '
                    .'Omit optional fields with no value; do not return null.',
                'user_instructions' => $instructions,
                'prompt_key' => $promptKey,
                'prompt_version' => $prompt['version'],
                'schema_version' => $prompt['schema'],
            ]);
        } catch (ConnectionException $exception) {
            throw new AiImportException('AI provider mất kết nối hoặc hết thời gian chờ. Hãy kiểm tra trạng thái request rồi thử lại thủ công.', 'AI_PROVIDER_TIMEOUT', false, $exception, $this->responseMetadata());
        } catch (AiImportException $exception) {
            $this->rethrowWithDiagnostics($exception);
        }

        try {
            $payload = $this->normalizePayload($payload);
            foreach ((array) config('ai.agent.output_aliases', []) as $alias => $canonical) {
                if (! array_key_exists($canonical, $payload) && array_key_exists($alias, $payload)) {
                    $payload[$canonical] = $payload[$alias];
                }
            }
            $this->responseMetadata['returned_fields'] = array_keys($payload);
            if ($this->outputFields !== null) {
                $payload = array_intersect_key($payload, array_flip($allowedFields));
            }
            if (! $generateSeo) {
                foreach (['focus_keyword', 'seo_title', 'seo_description', 'og_title', 'og_description'] as $field) {
                    unset($payload[$field]);
                }
            }
            $result = $this->validatePayload($payload);
            $this->responseMetadata['stage'] = 'validate';
            $this->responseMetadata = $this->responseMetadata();

            return $result;
        } catch (AiImportException $exception) {
            $this->rethrowWithDiagnostics($exception);
        }
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
        $this->responseMetadata['stage'] = 'envelope';
        if (is_string($payload)) {
            $payload = $this->decodeJsonObject($payload);
        }
        if ($payload instanceof \stdClass && $this->responseFormat() === 'http-json'
            && ! property_exists($payload, 'choices') && ! property_exists($payload, 'candidates') && ! property_exists($payload, 'promptFeedback')) {
            if (property_exists($payload, 'data')) {
                return $this->normalizePayload($payload->data);
            }
            if (property_exists($payload, 'output')) {
                return $this->normalizePayload($payload->output);
            }
        }
        $objectRoot = $payload instanceof \stdClass;
        if ($payload instanceof \stdClass) {
            $payload = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        }
        if (! is_array($payload)) {
            $payload = $this->decodeObject($payload);
            $objectRoot = true;
        }
        $this->responseMetadata['stage'] = 'envelope';
        if (array_key_exists('choices', $payload) || $this->responseFormat() === 'openai') {
            $choice = data_get($payload, 'choices.0');
            $this->captureMetadata($payload, is_array($choice) ? $choice['finish_reason'] ?? null : null);
            if (filled(data_get($choice, 'message.refusal'))) {
                throw new AiImportException('AI provider từ chối xử lý nội dung nguồn.', 'AI_PROVIDER_REFUSAL');
            }
            if (filled(data_get($choice, 'message.tool_calls')) || filled(data_get($choice, 'message.function_call'))
                || in_array(data_get($choice, 'finish_reason'), ['tool_calls', 'function_call'], true)) {
                throw new AiImportException('AI provider trả thao tác công cụ thay vì nội dung.', 'AI_PROVIDER_TOOL_OUTPUT');
            }
            if (! is_array($choice) || ($choice['finish_reason'] ?? null) !== 'stop') {
                throw new AiImportException('AI provider chưa hoàn tất phản hồi nội dung.', 'AI_PROVIDER_INCOMPLETE');
            }
            $content = data_get($choice, 'message.content');
            if (! is_string($content) || trim($content) === '') {
                throw new AiImportException('AI provider trả nội dung rỗng.', 'AI_PROVIDER_EMPTY_CONTENT');
            }

            return $this->decodeObject($content);
        }
        if (array_key_exists('candidates', $payload) || array_key_exists('promptFeedback', $payload) || $this->responseFormat() === 'gemini') {
            $candidate = data_get($payload, 'candidates.0');
            $this->captureMetadata($payload, is_array($candidate) ? $candidate['finishReason'] ?? null : null, true);
            if (filled(data_get($payload, 'promptFeedback.blockReason'))
                || in_array(data_get($candidate, 'finishReason'), ['SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII'], true)) {
                throw new AiImportException('AI provider từ chối xử lý nội dung nguồn.', 'AI_PROVIDER_REFUSAL');
            }
            $parts = data_get($candidate, 'content.parts', []);
            if (is_array($parts)) {
                foreach ($parts as $part) {
                    if (is_array($part) && (array_key_exists('functionCall', $part) || array_key_exists('functionResponse', $part))) {
                        throw new AiImportException('AI provider trả thao tác công cụ thay vì nội dung.', 'AI_PROVIDER_TOOL_OUTPUT');
                    }
                }
            }
            if (! is_array($candidate) || ($candidate['finishReason'] ?? null) !== 'STOP') {
                throw new AiImportException('AI provider chưa hoàn tất phản hồi nội dung.', 'AI_PROVIDER_INCOMPLETE');
            }
            $text = '';
            if (is_array($parts)) {
                foreach ($parts as $part) {
                    if (is_array($part) && ! ($part['thought'] ?? false) && is_string($part['text'] ?? null)) {
                        $text .= $part['text'];
                    }
                }
            }
            if (trim($text) === '') {
                throw new AiImportException('AI provider trả nội dung rỗng.', 'AI_PROVIDER_EMPTY_CONTENT');
            }

            return $this->decodeObject($text);
        }
        if (array_key_exists('data', $payload)) {
            return $this->normalizePayload($payload['data']);
        }
        if (array_key_exists('output', $payload)) {
            return $this->normalizePayload($payload['output']);
        }
        $this->responseMetadata['stage'] = 'parse';
        if (($payload !== [] && array_is_list($payload)) || ($payload === [] && ! $objectRoot)) {
            throw new AiImportException('AI provider trả JSON object không hợp lệ.', 'AI_PROVIDER_INVALID_JSON');
        }
        $this->responseMetadata['stage'] = 'parse';

        return $payload;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra canonical output, loại field ngoài schema và sai kiểu.
     * =====================================================================
     * INPUT: array output đã parse.
     * OUTPUT: array canonical fields an toàn; field bắt buộc null/thiếu luôn lỗi,
     *   field optional không có giá trị được bỏ qua trước merge chọn lọc.
     * SIDE EFFECT: chỉ đọc schema và tạo array mới.
     * EXCEPTION/TRANSACTION: AiImportException khi field/type không hợp lệ; không transaction.
     * =====================================================================
     */
    protected function validatePayload(array $payload): array
    {
        return (new AiOutputValidator)->validate($payload, $this->outputFields);
    }

    /**
     * =====================================================================
     * Input: chuỗi JSON provider.
     * Output: object dạng array; ném invalid JSON, không ghi raw response.
     * =====================================================================
     */
    private function decodeObject(mixed $json): array
    {
        $this->decodeJsonObject($json);

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * =====================================================================
     * Input: giá trị chưa decode.
     * Output: JSON object thật; chặn list/scalar/JSON lỗi, không side effect.
     * =====================================================================
     */
    private function decodeJsonObject(mixed $json): \stdClass
    {
        $this->responseMetadata['stage'] = 'parse';
        if (! is_string($json)) {
            throw new AiImportException('AI provider trả JSON object không hợp lệ.', 'AI_PROVIDER_INVALID_JSON');
        }
        try {
            $object = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AiImportException('AI provider trả JSON không hợp lệ.', 'AI_PROVIDER_INVALID_JSON');
        }
        if (! $object instanceof \stdClass) {
            throw new AiImportException('AI provider trả JSON object không hợp lệ.', 'AI_PROVIDER_INVALID_JSON');
        }

        return $object;
    }

    /**
     * =====================================================================
     * Input: envelope provider, finish reason và giao thức Gemini tùy chọn.
     * Output: cập nhật metadata ID/model/token theo allowlist, không chứa key/raw.
     * =====================================================================
     */
    private function captureMetadata(array $payload, mixed $finishReason, bool $gemini = false): void
    {
        $usage = $gemini ? (array) ($payload['usageMetadata'] ?? []) : (array) ($payload['usage'] ?? []);
        $this->responseMetadata = array_replace($this->responseMetadata, AiResponseDiagnostics::sanitize([
            'response_id' => $payload[$gemini ? 'responseId' : 'id'] ?? null,
            'reported_model' => $payload[$gemini ? 'modelVersion' : 'model'] ?? null,
            'finish_reason' => $finishReason,
            'usage' => $gemini ? [
                'prompt_tokens' => $usage['promptTokenCount'] ?? null,
                'completion_tokens' => $usage['candidatesTokenCount'] ?? null,
                'total_tokens' => $usage['totalTokenCount'] ?? null,
                'cached_tokens' => $usage['cachedContentTokenCount'] ?? null,
                'reasoning_tokens' => $usage['thoughtsTokenCount'] ?? null,
            ] : $usage + [
                'cached_tokens' => data_get($usage, 'prompt_tokens_details.cached_tokens'),
                'reasoning_tokens' => data_get($usage, 'completion_tokens_details.reasoning_tokens'),
            ],
        ]));
    }

    /**
     * =====================================================================
     * Input: lỗi business/provider và diagnostics đã có.
     * Output: luôn ném lại với metadata sanitized; không retry hay fallback.
     * =====================================================================
     */
    private function rethrowWithDiagnostics(AiImportException $exception): never
    {
        $this->responseMetadata = AiResponseDiagnostics::sanitize(array_replace($this->responseMetadata, $exception->diagnostics));
        throw new AiImportException($exception->getMessage(), $exception->errorCode, $exception->retryable, $exception, $this->responseMetadata);
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
