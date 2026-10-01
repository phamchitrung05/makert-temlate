<?php

namespace App\Services\Ai;

use App\Models\AiImport;
use App\Models\AiProvenance;
use App\Models\Post;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Ghi audit lineage khi candidate AI được lưu vào Post.
 * =====================================================================
 *
 * Service là boundary duy nhất nhận run ID từ HTTP payload rồi đọc lại
 * provider/model/prompt từ AiImport. Client không thể tự khai báo nguồn AI,
 * ghi vào Post fillable hoặc tạo provenance cho run của quản trị viên khác.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - recordPost(): xác minh run/field và ghi audit theo Post.
 * - normalizeFields(): chuẩn hóa field allowlist và báo lỗi validation.
 * - valueForField(): lấy giá trị canonical cần băm cho từng field.
 * - valueHash(): tạo hash ổn định, không lưu nội dung đầy đủ vào audit.
 * - candidate(): tải run ready thuộc actor hiện tại.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : actor ID, Post đã lưu, run UUID, field đã chọn và giá trị Post.
 * - OUTPUT: bản ghi AiProvenance và AiImport applied metadata.
 * - SIDE EFFECT: ghi database trong transaction của Action/Controller.
 * - EXCEPTION/TRANSACTION: ValidationException nếu run/field không hợp lệ;
 *   service không tự mở transaction để caller kiểm soát atomicity.
 * =====================================================================
 */
final class AiProvenanceService
{
    /** @var list<string> Field AI được phép lineage vào Post. */
    private const FIELDS = ['title', 'excerpt', 'content', 'seo', 'taxonomy', 'thumbnail'];

    /**
     * Xác minh candidate và ghi provenance cho các field Post đã lưu.
     *
     * Input: actor ID, Post đã create/update, run UUID nullable, field allowlist
     * và map giá trị sau khi domain Action đã chuẩn hóa.
     * Output: không trả giá trị; AiImport được đánh dấu applied và audit rows
     * được insert theo từng field.
     * Side effect: query/insert/update database; không gọi provider.
     * Exception/transaction: ValidationException nếu run không thuộc actor,
     * chưa ready, hết hạn hoặc field không nằm trong contract.
     *
     * @param  array<int, string>  $fields
     * @param  array<string, mixed>  $values
     */
    public function recordPost(int $actorId, Post $post, ?string $runId, array $fields, array $values): void
    {
        $runId = filled($runId) ? (string) $runId : null;
        if ($runId === null && $fields === []) {
            return;
        }
        if ($runId === null) {
            throw ValidationException::withMessages(['ai_run_id' => 'AI run là bắt buộc khi gửi ai_fields.']);
        }

        $fields = $this->normalizeFields($fields);
        $import = $this->candidate($actorId, $runId);
        $result = (array) $import->result_json;
        $provider = (string) ($result['provider'] ?? $import->provider ?? 'deterministic');
        $model = isset($result['model']) ? (string) $result['model'] : data_get($import->input_json, 'model');
        $promptKey = isset($result['prompt_key']) ? (string) $result['prompt_key'] : data_get($import->input_json, 'prompt_key');
        $promptVersion = isset($result['prompt_version']) ? (string) $result['prompt_version'] : $import->prompt_version;

        foreach ($fields as $field) {
            AiProvenance::query()->create([
                'target_type' => Post::class,
                'target_id' => $post->getKey(),
                'field' => $field,
                'run_id' => $import->getKey(),
                'provider' => $provider,
                'model' => $model,
                'prompt_key' => $promptKey,
                'prompt_version' => $promptVersion,
                'value_hash' => $this->valueHash($this->valueForField($field, $values)),
                'applied_by' => $actorId,
            ]);
        }

        $import->forceFill([
            'applied_target_id' => $post->getKey(),
            'applied_fields' => $fields,
        ])->save();
    }

    /**
     * Chuẩn hóa và giới hạn field lineage.
     *
     * Input: danh sách field từ request đã qua FormRequest hoặc adapter.
     * Output: danh sách string distinct giữ nguyên thứ tự.
     * Side effect: không có.
     * Exception/transaction: ValidationException nếu field không hợp lệ;
     * không mở transaction.
     *
     * @param  array<int, mixed>  $fields
     * @return list<string>
     */
    private function normalizeFields(array $fields): array
    {
        $normalized = array_values(array_unique(array_map(static fn ($field): string => (string) $field, $fields)));
        $invalid = array_values(array_diff($normalized, self::FIELDS));
        if ($normalized === [] || $invalid !== []) {
            throw ValidationException::withMessages([
                'ai_fields' => 'Field provenance AI không hợp lệ: '.implode(', ', $invalid ?: $normalized),
            ]);
        }

        return $normalized;
    }

    /**
     * Lấy run ready thuộc đúng actor và còn hạn sử dụng.
     *
     * Input: actor ID và UUID run.
     * Output: AiImport đã load result/input metadata.
     * Side effect: một truy vấn read-only.
     * Exception/transaction: ValidationException nếu không tìm thấy candidate
     * hợp lệ; không tiết lộ run của actor khác.
     */
    private function candidate(int $actorId, string $runId): AiImport
    {
        $import = AiImport::query()
            ->whereKey($runId)
            ->where('created_by', $actorId)
            ->where('status', 'ready')
            ->first();

        if (! $import || ($import->expires_at && $import->expires_at->isPast())) {
            throw ValidationException::withMessages([
                'ai_run_id' => 'AI candidate không tồn tại, chưa sẵn sàng hoặc đã hết hạn.',
            ]);
        }

        return $import;
    }

    /**
     * Lấy giá trị canonical tương ứng với field để audit hash.
     *
     * Input: field allowlist và payload Post đã chuẩn hóa.
     * Output: scalar/array đại diện giá trị field tại thời điểm lưu.
     * Side effect: không mutate payload và không truy vấn database.
     */
    private function valueForField(string $field, array $values): mixed
    {
        return match ($field) {
            'seo' => Arr::only($values, [
                'focus_keyword', 'seo_title', 'seo_description', 'canonical_url',
                'robots_index', 'robots_follow', 'og_title', 'og_description', 'og_image_id',
            ]),
            'taxonomy' => Arr::only($values, ['category_ids', 'tag_ids']),
            'thumbnail' => [
                'media_asset_id' => data_get($values, 'media.thumbnail_id', $values['thumbnail_id'] ?? null),
            ],
            default => $values[$field] ?? null,
        };
    }

    /**
     * Tạo SHA-256 ổn định thay vì lưu lại nội dung AI đầy đủ trong audit.
     *
     * Input: giá trị scalar hoặc nested array của field.
     * Output: chuỗi hash 64 ký tự.
     * Side effect: không có.
     */
    private function valueHash(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', is_string($encoded) ? $encoded : serialize($value));
    }
}
