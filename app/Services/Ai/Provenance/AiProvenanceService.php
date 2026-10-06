<?php

namespace App\Services\Ai\Provenance;

use App\Models\AiImport;
use App\Models\AiProvenance;
use App\Models\Post;
use App\Services\Ai\Images\AiThumbnailService;
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
 * - recordPostRuns(), writeRunProvenance(): xác minh từng run và ghi lineage theo field.
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
    /**
     * =====================================================================
     * CHỨC NĂNG: Danh sách field Post được phép ghi provenance AI
     * =====================================================================
     * INPUT: field name từ FormRequest/adapter.
     * OUTPUT: allowlist dùng chung cho normalizeFields().
     * SIDE EFFECT: chỉ đọc hằng số; không ghi database hoặc gọi provider.
     * EXCEPTION/TRANSACTION: field ngoài allowlist bị ValidationException ở service.
     * =====================================================================
     *
     * @var list<string>
     */
    private const FIELDS = ['title', 'excerpt', 'content', 'seo', 'taxonomy', 'thumbnail'];

    /**
     * =====================================================================
     * CHỨC NĂNG: Xác minh candidate và ghi provenance cho các field Post đã lưu.
     * =====================================================================
     * INPUT: actor ID, Post đã create/update, run UUID nullable, field allowlist
     * và map giá trị sau khi domain Action đã chuẩn hóa.
     * OUTPUT: AiImport được đánh dấu applied và audit rows theo từng field.
     * SIDE EFFECT: query/insert/update database; không gọi provider.
     * EXCEPTION/TRANSACTION: ValidationException nếu run không thuộc actor,
     * chưa ready, hết hạn hoặc field không nằm trong contract; transaction thuộc caller.
     *
     * @param  array<int, string>  $fields
     * @param  array<string, mixed>  $values
     *                                        =====================================================================
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

        $this->recordPostRuns($actorId, $post, [['run_id' => $runId, 'fields' => $fields]], $values);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Ghi provenance từ nhiều run độc lập theo từng field
     * =====================================================================
     * INPUT: nhóm run_id/fields, Post đã lưu và giá trị canonical hiện tại.
     * OUTPUT: audit rows và applied metadata cho từng AiImport.
     * SIDE EFFECT: đọc/ghi ai_imports và ai_provenances trong transaction caller.
     * EXCEPTION/TRANSACTION: ValidationException khi overlap, ownership, expiry
     * hoặc image asset không khớp; service không mở transaction riêng.
     * =====================================================================
     *
     * @param  array<int, array{run_id:string, fields:array<int,string>}>  $runs
     */
    public function recordPostRuns(int $actorId, Post $post, array $runs, array $values): void
    {
        if ($runs === []) {
            return;
        }
        $owners = [];
        foreach ($runs as $run) {
            $runId = filled($run['run_id'] ?? null) ? (string) $run['run_id'] : null;
            if ($runId === null) {
                throw ValidationException::withMessages(['ai_runs' => 'Mỗi nhóm provenance phải có run ID.']);
            }
            $fields = $this->normalizeFields((array) ($run['fields'] ?? []));
            foreach ($fields as $field) {
                if (isset($owners[$field]) && $owners[$field] !== $runId) {
                    throw ValidationException::withMessages(['ai_runs' => "Field {$field} chỉ được gắn với một run."]);
                }
                $owners[$field] = $runId;
            }
            $import = $this->candidate($actorId, $runId);
            $result = (array) $import->result_json;
            if ($import->operation !== 'image' && AiThumbnailService::pending($import)) {
                throw ValidationException::withMessages(['ai_run_id' => 'Thumbnail đang được tạo. Hãy chờ ảnh trước khi lưu Post.']);
            }
            if ($import->operation === 'image' && array_diff($fields, ['thumbnail']) !== []) {
                throw ValidationException::withMessages(['ai_runs' => 'Image run chỉ được ghi provenance cho thumbnail.']);
            }
            if (in_array('thumbnail', $fields, true)) {
                $expected = data_get($result, 'draft.thumbnail.media_asset_id')
                    ?? data_get($result, 'image.media_asset_id');
                $actual = data_get($values, 'media.thumbnail_id', $values['thumbnail_id'] ?? null);
                if (! $expected || (string) $expected !== (string) $actual) {
                    throw ValidationException::withMessages(['ai_runs' => 'Thumbnail phải là asset đã được tạo bởi image run.']);
                }
            }
            $textFields = $fields;
            $imageRunId = data_get($result, 'draft.thumbnail.image_run_id');
            if ($import->operation !== 'image' && in_array('thumbnail', $fields, true) && $imageRunId) {
                $image = $this->candidate($actorId, $imageRunId);
                $ownImage = $image->parent_id === $import->id && data_get($result, 'image_job_id') === $image->id;
                $inheritedImage = $import->operation === 'regenerate'
                    && data_get($import->input_json, 'parent_draft_snapshot.thumbnail.image_run_id') === $image->id
                    && (string) data_get($import->input_json, 'parent_draft_snapshot.thumbnail.media_asset_id') === (string) data_get($image->result_json, 'image.media_asset_id');
                if ($image->operation !== 'image' || (! $ownImage && ! $inheritedImage)
                    || (string) data_get($image->result_json, 'image.media_asset_id') !== (string) data_get($result, 'draft.thumbnail.media_asset_id')) {
                    throw ValidationException::withMessages(['ai_runs' => 'Nguồn thumbnail AI không khớp candidate.']);
                }
                $this->writeRunProvenance($actorId, $post, $image, ['thumbnail'], $values, (array) $image->result_json);
                $textFields = array_values(array_diff($fields, ['thumbnail']));
            }
            $this->writeRunProvenance($actorId, $post, $import, $textFields, $values, $result);
            if ($textFields !== $fields) {
                $import->forceFill(['applied_fields' => $fields])->save();
            }
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Ghi audit theo field và cập nhật applied metadata của run
     * =====================================================================
     * INPUT: Run/fields đã xác minh, Post và map giá trị canonical.
     * OUTPUT: Audit rows chứa identity từ server và value hash.
     * SIDE EFFECT: Insert ai_provenances; cập nhật applied_target_id/applied_fields của run.
     * EXCEPTION/TRANSACTION: Không tự mở transaction; caller chịu trách nhiệm rollback đồng bộ với Post.
     * =====================================================================
     */
    private function writeRunProvenance(int $actorId, Post $post, AiImport $import, array $fields, array $values, array $result): void
    {
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
     * =====================================================================
     * CHỨC NĂNG: Chuẩn hóa và giới hạn field lineage.
     * =====================================================================
     * INPUT: danh sách field từ FormRequest hoặc adapter.
     * OUTPUT: danh sách string distinct giữ nguyên thứ tự.
     * SIDE EFFECT: Không ghi database hoặc gọi provider.
     * EXCEPTION/TRANSACTION: ValidationException nếu field không hợp lệ; không transaction.
     *
     * @param  array<int, mixed>  $fields
     * @return list<string>
     *                      =====================================================================
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
     * =====================================================================
     * CHỨC NĂNG: Lấy run ready chưa bị từ chối, thuộc đúng actor và còn hạn sử dụng.
     * =====================================================================
     * INPUT: actor ID và UUID run.
     * OUTPUT: AiImport đã load result/input metadata.
     * SIDE EFFECT: một truy vấn read-only.
     * EXCEPTION/TRANSACTION: ValidationException nếu không tìm thấy candidate hợp lệ;
     * không tiết lộ run của actor khác; không mở transaction.
     * =====================================================================
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
        if (data_get($import->source_meta_json, 'editorial.status') === 'rejected') {
            throw ValidationException::withMessages(['ai_run_id' => 'Không thể dùng bản AI đã bị từ chối để lưu Post.']);
        }

        return $import;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy giá trị canonical tương ứng với field để audit hash.
     * =====================================================================
     * INPUT: field allowlist và payload Post đã chuẩn hóa.
     * OUTPUT: scalar/array đại diện giá trị field tại thời điểm lưu.
     * SIDE EFFECT: không mutate payload và không truy vấn database.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
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
     * =====================================================================
     * CHỨC NĂNG: Tạo SHA-256 ổn định thay vì lưu nội dung AI đầy đủ trong audit.
     * =====================================================================
     * INPUT: giá trị scalar hoặc nested array của field.
     * OUTPUT: chuỗi hash 64 ký tự.
     * SIDE EFFECT: Không ghi database hoặc gọi provider.
     * EXCEPTION/TRANSACTION: Không mở transaction.
     * =====================================================================
     */
    private function valueHash(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', is_string($encoded) ? $encoded : serialize($value));
    }
}
