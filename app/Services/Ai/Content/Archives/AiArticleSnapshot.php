<?php

namespace App\Services\Ai\Content\Archives;

use App\Models\AiImport;
use App\Services\Ai\Content\Data\ArticleInputHasher;
use App\Services\Ai\Providers\Diagnostics\AiResponseDiagnostics;
use Illuminate\Support\Arr;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Chốt contract snapshot v1 và nguồn gốc từng field.
 * =====================================================================
 *
 * Builder thuần dựng contract v1 cho checkpoint tạm và kho approved. Phân biệt content AI mới, field kế thừa/deterministic; legacy thiếu bằng chứng giữ original null.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - recoveryResult().
 * - redactForRun().
 * - make().
 * - redact().
 * - secretValues().
 * - cleanUrl().
 * - redactUrlParameters().
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Run, kết quả canonical và cờ legacy tùy chọn.
 * - OUTPUT: Snapshot/hash allowlist đã redact, có origin từng field và trace các bước thực sự dùng.
 * - SIDE EFFECT: Chỉ xử lý trong memory; không DB/network hoặc lưu connection/header/raw response.
 * - EXCEPTION/TRANSACTION: Không mở transaction; caller quản lý persistence và lỗi hash/JSON.
 * =====================================================================
 */
final class AiArticleSnapshot
{
    public const DRAFT_FIELDS = ['title', 'content_html', 'excerpt', 'focus_keyword', 'seo_title',
        'seo_description', 'canonical_url', 'robots_index', 'robots_follow', 'og_title', 'og_description'];

    /**
     * =====================================================================
     * CHỨC NĂNG: Lọc kết quả canonical dùng phục hồi candidate
     * =====================================================================
     *
     * INPUT:
     * - Run và kết quả canonical đã qua validation/sanitize.
     *
     * OUTPUT:
     * - Array allowlist source/draft/provider/model/prompt/schema/groups/generation_meta đã redact.
     *
     * SIDE EFFECT:
     * - Chỉ lọc và dựng array; không ghi DB hoặc gọi provider.
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction; caller chịu trách nhiệm kiểm schema trước khi gọi.
     *
     * =====================================================================
     */
    public function recoveryResult(AiImport $run, array $result): array
    {
        return $this->redactForRun(Arr::only($result, [
            'source', 'draft', 'provider', 'model', 'prompt_key', 'prompt_version',
            'schema_version', 'requested_fields', 'generation_meta',
        ]), $run);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Loại thông tin xác thực đã biết khỏi payload của run
     * =====================================================================
     *
     * INPUT:
     * - Giá trị cần lọc và input_json của run chứa connection snapshot.
     *
     * OUTPUT:
     * - Giá trị cùng kiểu sau khi redact secret và credential URL.
     *
     * SIDE EFFECT:
     * - Chỉ xử lý trong memory; không lưu connection hoặc gọi I/O.
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction hoặc query DB; không thay model đầu vào.
     *
     * =====================================================================
     */
    public function redactForRun(mixed $value, AiImport $run): mixed
    {
        return $this->redact($value, $this->secretValues(Arr::only((array) $run->input_json, ['ai_connection', 'image_connection'])));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Dựng snapshot v1 và phân loại nguồn gốc từng field
     * =====================================================================
     *
     * INPUT:
     * - Run đã load source/input/diagnostics; result canonical tùy chọn và cờ legacy.
     *
     * OUTPUT:
     * - Array snapshot/hash canonical; origin và has_generated_content phân biệt AI mới, kế thừa, deterministic và legacy thiếu original.
     *
     * SIDE EFFECT:
     * - Giữ nguồn/brief/profile/trace allowlist và redact trước khi hash; không persist hoặc gọi AI. Legacy không lấy draft đã sửa làm original.
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction; JSON không encode được có thể ném JsonException từ ArticleInputHasher; caller quản lý lỗi lưu.
     *
     * =====================================================================
     */
    public function make(AiImport $run, ?array $result = null, bool $legacy = false): array
    {
        $input = (array) $run->input_json;
        $source = Arr::only((array) data_get($run->source_meta_json, 'article_source', []), [
            'source_url', 'canonical_url', 'content_type', 'title', 'description', 'content_html',
            'text', 'content_text', 'blocks', 'code_blocks', 'tables', 'links', 'quotes', 'source_images',
            'inline_image_refs', 'truncated', 'word_count', 'source_hash', 'hash', 'version', 'fetched_at',
        ]);
        // Text nguồn dùng đối chiếu khi lỗi xảy ra trước extractor.
        if ($source === [] && filled($run->source_text)) {
            $source = ['source_text' => (string) $run->source_text, 'content_type' => data_get($input, 'source_format', 'text')];
        }
        $source['source_url'] ??= $run->normalized_url ?: $run->source_url;
        $draft = $result ? Arr::only((array) ($result['draft'] ?? []), self::DRAFT_FIELDS) : null;
        if ($draft !== null && ! isset($draft['content_html']) && isset($result['draft']['content'])) {
            $draft['content_html'] = (string) $result['draft']['content'];
        }
        $groups = (array) ($input['fields'] ?? $input['requested_outputs'] ?? []);
        $hasParentSelection = $run->parent_id && $groups !== [];
        if ($groups === [] && ! array_key_exists('requested_outputs', $input)) {
            $groups = ['title', 'content'];
        }
        $groups = (array) data_get($result, 'generation_meta.requested_groups', $groups);
        $mode = (string) data_get($result, 'generation_meta.mode', 'unknown');
        $generated = (array) data_get($result, 'generation_meta.generated_fields', []);
        $parent = (array) ($input['parent_draft_snapshot'] ?? []);
        $inherited = (array) data_get($result, 'generation_meta.inherited_fields', $hasParentSelection ? array_keys($parent) : []);
        $origins = [];
        foreach (array_keys($draft ?? []) as $field) {
            $origins[$field] = $mode === 'ai' && in_array($field, $generated, true) ? 'ai'
                : (in_array($field, $inherited, true) && ! in_array($field, $generated, true) ? 'inherited' : 'source_or_deterministic');
        }
        $hasContent = ($origins['content_html'] ?? null) === 'ai' && trim(strip_tags((string) ($draft['content_html'] ?? ''))) !== '';
        $origin = match (true) {
            $legacy => 'legacy_unverified',
            $result === null => 'no_generated_content',
            $hasContent && in_array('inherited', $origins, true) => 'mixed',
            $hasContent => 'ai_original',
            in_array('ai', $origins, true) => 'ai_fields_only',
            $mode === 'deterministic' => 'deterministic',
            in_array('inherited', $origins, true) => 'inherited',
            default => 'source_only',
        };
        $profile = Arr::only((array) ($input['writing_profile_snapshot'] ?? []), [
            'id', 'name', 'version', 'rules', 'style_instructions',
        ]);
        $context = [
            'language' => $input['language'] ?? 'vi', 'rewrite_style' => $input['rewrite_style'] ?? null,
            'title' => $input['title'] ?? null, 'instructions' => $input['instructions'] ?? '',
            'website_instructions' => data_get($input, 'ai_connection.system_prompt', $input['system_prompt'] ?? ''),
            'writing_brief' => Arr::only((array) ($input['writing_brief'] ?? []), ['audience', 'article_type', 'purpose', 'angle', 'length']),
            'writing_profile' => $profile, 'requested_groups' => array_values($groups), 'field_origins' => $origins,
            'provider' => $result['provider'] ?? $run->provider, 'model' => $result['model'] ?? ($input['model'] ?? null),
            'prompt_key' => $result['prompt_key'] ?? ($input['prompt_key'] ?? null),
            'prompt_version' => $result['prompt_version'] ?? $run->prompt_version,
            'schema_version' => $result['schema_version'] ?? null,
            'pipeline' => data_get($input, 'pipeline_snapshot.pipeline'), 'generation_mode' => $mode,
            'pipeline_prompt_version' => data_get($input, 'pipeline_snapshot.prompt_version'),
            'pipeline_schema_version' => data_get($input, 'pipeline_snapshot.schema_version'),
            'inherited_from_run_id' => $run->parent_id,
            'missing_original_reason' => $legacy ? 'legacy_candidate_may_have_been_edited' : null,
        ];
        $secrets = $this->secretValues(Arr::only($input, ['ai_connection', 'image_connection']));
        $source = $this->redact($source, $secrets);
        $draft = $this->redact($draft, $secrets);
        $context = $this->redact($context, $secrets);
        $diagnostics = ['response' => AiResponseDiagnostics::sanitize((array) data_get($run->source_meta_json, 'ai_response', []))];
        $diagnostics['used_steps'] = array_map(function (array $step): array {
            return Arr::only($step, ['task', 'input_hash', 'prompt_version', 'schema_version', 'reused_checkpoint'])
                + ['diagnostics' => AiResponseDiagnostics::sanitize((array) ($step['diagnostics'] ?? []))];
        }, array_slice((array) data_get($run->source_meta_json, 'article_pipeline.used_steps', []), 0, 3));
        $payload = [
            'run_id' => (string) $run->id, 'session_id' => $run->session_id ?: $run->id,
            'parent_run_id' => $run->parent_id, 'generation_no' => max(1, (int) $run->generation_no),
            'snapshot_version' => 1, 'created_by' => $run->created_by === null ? null : (int) $run->created_by,
            'target_type' => 'post', 'operation' => $run->operation ?: 'create',
            'generation_status' => $result === null ? $run->status : 'ready', 'content_origin' => $origin,
            'has_generated_content' => $hasContent && ! $legacy,
            'source_hash' => $source === [] ? null : ArticleInputHasher::hash($source),
            'content_hash' => $draft === null ? null : ArticleInputHasher::hash($draft),
            'source_snapshot_json' => $source ?: null, 'draft_snapshot_json' => $draft,
            'context_snapshot_json' => $context, 'diagnostics_json' => $this->redact($diagnostics, $secrets),
            'failure_code' => $result !== null ? null : (preg_match('/\A[A-Z0-9_]{1,80}\z/D', (string) $run->error_code) ? $run->error_code : null),
            'generation_started_at' => $run->started_at?->format('Y-m-d H:i:s'),
            'generation_completed_at' => ($run->completed_at ?? now())->format('Y-m-d H:i:s'),
        ];

        return $payload + ['payload_hash' => ArticleInputHasher::hash($payload)];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Redact credential trong cấu trúc dữ liệu và URL
     * =====================================================================
     *
     * INPUT:
     * - Payload scalar/array và danh sách secret đã biết.
     *
     * OUTPUT:
     * - Payload đã bỏ key nhạy cảm, thay secret value và lọc auth URL; scalar khác giữ nguyên.
     *
     * SIDE EFFECT:
     * - Đệ quy xử lý bản sao trong memory; không DB/network.
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction hoặc phát sinh HTTP; URL không parse được dùng nhãn redacted-url.
     *
     * =====================================================================
     */
    public function redact(mixed $value, array $secrets = []): mixed
    {
        if (is_array($value)) {
            $safe = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && preg_match('/(?:api[_-]?key|authorization|password|secret|access[_-]?token|headers|raw[_-]?response)/i', $key)) {
                    continue;
                }
                $safe[$key] = $this->redact($item, $secrets);
            }

            return $safe;
        }
        if (! is_string($value)) {
            return $value;
        }
        if ($secrets !== []) {
            $value = str_replace($secrets, '[redacted]', $value);
        }

        return preg_replace_callback('~https?://[^\s<>"\']+~iu', fn (array $match): string => $this->cleanUrl($match[0]), $value) ?? $value;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Thu thập giá trị xác thực để redact văn bản
     * =====================================================================
     *
     * INPUT:
     * - Connection private và cờ sensitive kế thừa khi duyệt cấu trúc lồng.
     *
     * OUTPUT:
     * - Danh sách giá trị secret distinct đủ dài, gồm phần token sau Bearer khi có.
     *
     * SIDE EFFECT:
     * - Chỉ đọc connection trong memory; không log hoặc persist credential.
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction hoặc gọi I/O.
     *
     * =====================================================================
     */
    private function secretValues(array $connection, bool $sensitive = false): array
    {
        $values = [];
        foreach ($connection as $key => $value) {
            $secret = $sensitive || preg_match('/(?:key|token|secret|password|authorization|headers|cookie)/i', (string) $key);
            if (is_array($value)) {
                $values = [...$values, ...$this->secretValues($value, (bool) $secret)];
            } elseif ($secret && is_string($value) && mb_strlen($value) >= 4) {
                $values[] = $value;
                if (str_starts_with($value, 'Bearer ')) {
                    $values[] = substr($value, 7);
                }
            }
        }

        return array_values(array_unique(array_filter($values)));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Bỏ credential URL và giữ nguyên URL tham khảo thông thường
     * =====================================================================
     *
     * INPUT:
     * - Chuỗi URL HTTP/HTTPS cần redact.
     *
     * OUTPUT:
     * - URL bỏ userinfo/tham số auth hoặc nhãn redacted-url nếu không parse được.
     *
     * SIDE EFFECT:
     * - Chỉ parse/ghép chuỗi; URL thường giữ byte, query, thứ tự/encoding và fragment.
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction, không fetch URL hoặc xác minh URL còn tồn tại.
     *
     * =====================================================================
     */
    private function cleanUrl(string $url): string
    {
        $parts = parse_url(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return '[redacted-url]';
        }
        $query = $this->redactUrlParameters($parts['query'] ?? '');
        $fragment = $this->redactUrlParameters($parts['fragment'] ?? '');
        if (! isset($parts['user'], $parts['pass']) && ! isset($parts['user'])
            && $query === ($parts['query'] ?? '') && $fragment === ($parts['fragment'] ?? '')) {
            return $url;
        }
        $safe = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '');

        return $safe.($query === '' ? '' : '?'.$query).($fragment === '' ? '' : '#'.$fragment);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lọc tham số xác thực trong query hoặc fragment
     * =====================================================================
     *
     * INPUT:
     * - Chuỗi tham số query/fragment chưa đổi thứ tự hoặc encoding.
     *
     * OUTPUT:
     * - Chuỗi bỏ auth/signature/credential, giữ các cặp thông thường và key trùng.
     *
     * SIDE EFFECT:
     * - Chỉ lọc chuỗi trong memory; không I/O.
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction hoặc truy cập mạng.
     *
     * =====================================================================
     */
    private function redactUrlParameters(string $parameters): string
    {
        return implode('&', array_filter(explode('&', $parameters), function (string $pair): bool {
            if (! str_contains($pair, '=')) {
                return true;
            }
            $key = rawurldecode(explode('=', $pair, 2)[0]);

            return ! preg_match('/\A(?:api[_-]?key|key|_?token|(?:access|refresh|csrf)[_-]?token|(?:api[_-]?)?secret|password|authorization|signature|credential|sig)(?:\z|\[)|\A(?:x-amz-|x-goog-)/i', $key);
        }));
    }
}
