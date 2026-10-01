<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Posts\CreatePostAction;
use App\Actions\Posts\UpdatePostAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AiCandidateApplyRequest;
use App\Http\Requests\Admin\AiImportRequest;
use App\Http\Responses\BaseResponse;
use App\Jobs\ProcessAiImportJob;
use App\Models\AiImport;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Services\Ai\ArticleSourceFetcher;
use App\Services\Ai\AiProvenanceService;
use App\Services\Ai\Registries\PromptRegistry;
use App\Services\Ai\Registries\ProviderRegistry;
use App\Services\Ai\Registries\SchemaRegistry;
use App\Services\Ai\Registries\TargetRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: HTTP API tạo, polling, retry, hủy và dọn AI import.
 * =====================================================================
 * CÁC HÀM/METHOD: capabilities(), store(), show(), regenerate(), retry(),
 * candidates(), apply(), cancel(), destroy(), payload(), ensureOwner(),
 * cleanupThumbnail().
 * INPUT: admin request URL/options hoặc UUID job; OUTPUT: envelope JSON.
 * SIDE EFFECT: tạo/dispatch queue job, cập nhật vòng đời và dọn thumbnail tạm.
 * AUTHORIZATION: route permission posts.manage và kiểm tra owner bản ghi.
 * EXCEPTION/TRANSACTION: apply() dùng transaction + lock trong Post Actions;
 * candidate chỉ được apply khi ready và không tin provider/model từ client.
 */
class AiImportController extends Controller
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Trả capability AI theo target cho dialog dùng chung
     * =====================================================================
     * INPUT: target key do frontend yêu cầu.
     * OUTPUT: operation/input/output/prompt/provider/schema đã allowlist.
     * SIDE EFFECT: chỉ đọc registry; không gọi provider và không ghi database.
     * EXCEPTION/TRANSACTION: target chưa bật trả 404; không mở transaction.
     * =====================================================================
     */
    public function capabilities(
        string $target,
        TargetRegistry $targets,
        ProviderRegistry $providers,
        PromptRegistry $prompts,
        SchemaRegistry $schemas,
    ): JsonResponse {
        try {
            $targetConfig = $targets->get($target);
        } catch (\InvalidArgumentException) {
            return BaseResponse::error('AI target chưa được bật.', 404);
        }

        $promptItems = collect($prompts->all())
            ->map(function (array $prompt, string $key): array {
                return $prompt + ['key' => $key];
            })
            ->filter(fn (array $prompt): bool => in_array($target, $prompt['allowed_targets'] ?? [], true))
            ->map(function (array $prompt): array {
                return [
                    'key' => $prompt['key'],
                    'label' => $prompt['label'] ?? $prompt['key'],
                    'version' => $prompt['version'] ?? null,
                    'schema' => $prompt['schema'] ?? null,
                    'operations' => array_values($prompt['allowed_operations'] ?? []),
                ];
            })
            ->values()
            ->all();

        $schemaKeys = collect($promptItems)->pluck('schema')->filter()->unique()->values();
        $schemaItems = $schemaKeys->map(function (string $key) use ($schemas): array {
            $schema = $schemas->get($key);

            return [
                'key' => $key,
                'version' => $schema['version'] ?? null,
                'fields' => array_values($schema['fields'] ?? []),
            ];
        })->all();

        return BaseResponse::success([
            'target_type' => $target,
            'operations' => array_values($targetConfig['operations'] ?? []),
            'input_types' => array_values($targetConfig['inputs'] ?? []),
            'outputs' => array_values($targetConfig['outputs'] ?? []),
            'prompts' => $promptItems,
            'schemas' => $schemaItems,
            'providers' => $providers->publicOptions(),
        ], 'Capability AI của target.');
    }

    /** Input: URL/options đã validate. Output: 202 job queued hoặc job trùng idempotent. */
    public function store(
        AiImportRequest $request,
        ArticleSourceFetcher $fetcher,
        ProviderRegistry $providers,
        PromptRegistry $prompts,
    ): JsonResponse
    {
        if (! config('ai-import.enabled', true)) {
            return BaseResponse::error('AI import đang tắt.', 503);
        }
        $userId = (int) $request->user()->getKey();
        if (AiImport::query()->where('created_by', $userId)->where('created_at', '>=', now()->subHour())->count() >= config('ai-import.quota_per_hour', 20)) {
            return BaseResponse::error('Bạn đã đạt giới hạn import trong giờ này.', 429);
        }
        $data = $request->validated();
        $sourceType = filled($data['text'] ?? null) ? 'text' : 'url';
        $normalizedUrl = $sourceType === 'url'
            ? $fetcher->validateUrl((string) $data['url'])
            : null;
        $providerKey = (string) ($data['provider'] ?? config('ai-import.provider', 'deterministic'));
        try {
            $provider = $providers->get($providerKey);
        } catch (\InvalidArgumentException) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'provider' => 'Provider chưa được cấu hình hoặc không nằm trong allowlist.',
            ]);
        }
        $model = (string) ($data['model'] ?? ($provider['models'][0] ?? 'default'));
        if (! in_array($model, $provider['models'] ?? [], true)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'model' => 'Model không nằm trong allowlist của provider đã chọn.',
            ]);
        }
        try {
            $prompt = $prompts->select(
                isset($data['prompt_key']) ? (string) $data['prompt_key'] : null,
                'post',
                'create',
                ['source_type' => $sourceType, 'language' => (string) ($data['language'] ?? 'vi')],
            );
        } catch (\InvalidArgumentException) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'prompt_key' => 'Prompt không nằm trong allowlist của Post.',
            ]);
        }
        $promptKey = (string) $prompt['key'];
        $input = [
            'source_type' => $sourceType,
            'language' => $data['language'] ?? 'vi',
            'rewrite_style' => $data['rewrite_style'] ?? 'informative',
            'generate_thumbnail' => (bool) ($data['generate_thumbnail'] ?? true),
            'thumbnail_mode' => $data['thumbnail_mode'] ?? 'auto',
            'prompt_key' => $promptKey,
            'instructions' => $data['instructions'] ?? '',
            'provider' => $providerKey,
            'model' => $model,
        ];
        $sourceHashValue = $sourceType === 'text' ? trim((string) $data['text']) : (string) $normalizedUrl;
        $hash = hash('sha256', $sourceType.'|'.$sourceHashValue.'|'.json_encode($input, JSON_UNESCAPED_UNICODE).'|'.config('ai-import.prompt_version', 'v1'));
        $existing = AiImport::query()->where('created_by', $userId)->where('source_hash', $hash)->where('created_at', '>=', now()->subMinutes(config('ai-import.idempotency_window_minutes', 30)))->latest()->first();
        if ($existing) {
            return BaseResponse::success($this->payload($existing), 'Import đã tồn tại.', 202);
        }
        $import = AiImport::query()->create([
            'id' => (string) Str::uuid(), 'created_by' => $userId,
            'source_url' => $sourceType === 'url' ? $data['url'] : '',
            'source_text' => $sourceType === 'text' ? trim((string) $data['text']) : null,
            'normalized_url' => $normalizedUrl, 'source_hash' => $hash, 'status' => 'queued',
            'current_step' => 'queued', 'progress' => 0, 'input_json' => $input,
            'provider' => $providerKey, 'prompt_version' => config('ai-import.prompt_version', 'v1'),
        ]);
        $import->forceFill(['session_id' => $import->id])->save();
        ProcessAiImportJob::dispatch($import->id);

        return BaseResponse::success($this->payload($import), 'Đã xếp hàng import bài viết.', 202);
    }

    /** Input: UUID job của admin. Output: progress/result sau ownership check. */
    public function show(Request $request, AiImport $aiImport): JsonResponse
    {
        $this->ensureOwner($request, $aiImport);

        return BaseResponse::success($this->payload($aiImport->fresh()), 'Trạng thái import.');
    }

    /** Input: job đã terminal. Output: reset về queued và dispatch lại. */
    public function regenerate(Request $request, AiImport $aiImport, ProviderRegistry $providers, PromptRegistry $prompts): JsonResponse
    {
        $this->ensureOwner($request, $aiImport);
        if (in_array($aiImport->status, ['queued', 'fetching', 'extracting', 'rewriting', 'seo', 'thumbnail'], true)) {
            return BaseResponse::error('Import đang được xử lý.', 409);
        }
        $options = $request->validate([
            'prompt_key' => ['sometimes', 'string', 'max:120'],
            'instructions' => ['sometimes', 'string', 'max:4000'],
            'provider' => ['sometimes', 'nullable', 'string', 'max:80'],
            'model' => ['sometimes', 'nullable', 'string', 'max:120'],
            'fields' => ['sometimes', 'array'],
            'fields.*' => ['string', 'distinct', 'in:title,excerpt,content,seo,taxonomy,thumbnail'],
        ]);
        $currentInput = (array) $aiImport->input_json;
        $providerKey = (string) ($options['provider'] ?? ($currentInput['provider'] ?? config('ai-import.provider', 'deterministic')));
        if (array_key_exists('provider', $options) || array_key_exists('model', $options)) {
            try {
                $provider = $providers->get($providerKey);
            } catch (\InvalidArgumentException) {
                throw \Illuminate\Validation\ValidationException::withMessages(['provider' => 'Provider chưa được cấu hình.']);
            }
            $model = $options['model'] ?? ($currentInput['model'] ?? null);
            if (! empty($model) && ! in_array($model, $provider['models'] ?? [], true)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['model' => 'Model không nằm trong allowlist.']);
            }
        }
        if (array_key_exists('prompt_key', $options)) {
            try {
                $prompts->get((string) $options['prompt_key'], 'post', 'create');
            } catch (\InvalidArgumentException) {
                throw \Illuminate\Validation\ValidationException::withMessages(['prompt_key' => 'Prompt không nằm trong allowlist của Post.']);
            }
        }
        $child = $aiImport->replicate(['id', 'created_at', 'updated_at']);
        $child->id = (string) Str::uuid();
        $child->session_id = $aiImport->session_id ?: $aiImport->id;
        $child->parent_id = $aiImport->id;
        $child->operation = 'regenerate';
        $child->status = 'queued';
        $child->current_step = 'queued';
        $child->progress = 0;
        $child->result_json = null;
        $child->error_code = null;
        $child->error_message = null;
        $child->completed_at = null;
        $child->applied_target_id = null;
        $child->applied_fields = null;
        $child->input_json = array_replace((array) $aiImport->input_json, array_filter($options, fn ($value) => $value !== null));
        $child->save();
        ProcessAiImportJob::dispatch($child->id);

        return BaseResponse::success($this->payload($child->fresh()), 'Đã xếp hàng tạo candidate mới.', 202);
    }

    /** Input: run failed/cancelled. Output: cùng candidate được retry kỹ thuật, không tạo run mới. */
    public function retry(Request $request, AiImport $aiImport): JsonResponse
    {
        $this->ensureOwner($request, $aiImport);
        if (! in_array($aiImport->status, ['failed', 'cancelled', 'expired'], true)) {
            return BaseResponse::error('Chỉ có thể retry run đã kết thúc lỗi.', 409);
        }
        $aiImport->forceFill([
            'status' => 'queued', 'current_step' => 'queued', 'progress' => 0,
            'error_code' => null, 'error_message' => null, 'completed_at' => null,
        ])->save();
        ProcessAiImportJob::dispatch($aiImport->id);

        return BaseResponse::success($this->payload($aiImport->fresh()), 'Đã xếp hàng retry run.');
    }

    /** Input: candidate đã ready. Output: mọi candidate trong cùng session để compare. */
    public function candidates(Request $request, AiImport $aiImport): JsonResponse
    {
        $this->ensureOwner($request, $aiImport);
        $sessionId = $aiImport->session_id ?: $aiImport->id;
        $items = AiImport::query()->where('session_id', $sessionId)->orWhereKey($sessionId)->latest()->get();

        return BaseResponse::success($items->map(fn (AiImport $item): array => $this->payload($item))->values()->all(), 'Danh sách candidate.');
    }

    /** Input: fields/target tùy chọn. Output: Post draft được apply và provenance theo field. */
    public function apply(AiCandidateApplyRequest $request, AiImport $aiImport, TargetRegistry $targets, CreatePostAction $create, UpdatePostAction $update, AiProvenanceService $provenance): JsonResponse
    {
        $this->ensureOwner($request, $aiImport);
        abort_unless($aiImport->status === 'ready', 409, 'Candidate chưa sẵn sàng.');
        $data = $request->validated();
        $adapter = $targets->adapter('post');
        $outputs = (array) data_get($aiImport->result_json, 'draft', []);
        $payload = $adapter->toApplyPayload($outputs, $data['fields']);
        $actorId = (int) $request->user()->getKey();
        $post = DB::transaction(function () use ($data, $payload, $aiImport, $create, $update, $provenance, $actorId): Post {
            $target = ! empty($data['target_id']) ? Post::query()->findOrFail($data['target_id']) : null;
            if ($target && ! empty($data['expected_updated_at']) && (string) $target->updated_at !== (string) $data['expected_updated_at']) {
                abort(409, 'Post đã thay đổi, hãy tải lại trước khi áp dụng candidate.');
            }
            $post = $target ? $update->handle($target, array_merge($payload, ['status' => 'draft']), $actorId) : $create->handle(array_merge($payload, ['status' => 'draft']), $actorId);
            $provenance->recordPost($actorId, $post, (string) $aiImport->getKey(), $data['fields'], $payload);

            return $post;
        });

        return BaseResponse::success(['post_id' => $post->getKey(), 'fields' => $data['fields'], 'provenance' => data_get($aiImport->fresh()->result_json, 'provider')], 'Đã áp dụng candidate vào Post draft.');
    }

    /** Input: job đang chạy. Output: cancelled và cleanup thumbnail tạm. */
    public function cancel(Request $request, AiImport $aiImport): JsonResponse
    {
        $this->ensureOwner($request, $aiImport);
        if (in_array($aiImport->status, ['ready', 'failed', 'expired', 'cancelled'], true)) {
            return BaseResponse::success($this->payload($aiImport), 'Import đã kết thúc.');
        }
        $this->cleanupThumbnail($aiImport);
        $aiImport->update(['status' => 'cancelled', 'current_step' => 'cancelled', 'error_code' => 'CANCELLED', 'error_message' => 'Import đã bị hủy.']);

        return BaseResponse::success($this->payload($aiImport->fresh()), 'Đã hủy import.');
    }

    /** Input: job của admin. Output: xóa job và asset thumbnail chưa attach. */
    public function destroy(Request $request, AiImport $aiImport): JsonResponse
    {
        $this->ensureOwner($request, $aiImport);
        $this->cleanupThumbnail($aiImport);
        $aiImport->delete();

        return BaseResponse::success(null, 'Đã xóa import.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn hóa payload lifecycle/candidate cho API response
     * =====================================================================
     * INPUT: AiImport đã qua ownership check hoặc record nội bộ.
     * OUTPUT: mảng public gồm status/progress/result; không lộ secret/path nội bộ.
     * SIDE EFFECT: không ghi database; chỉ đọc model đã hydrate.
     * EXCEPTION/TRANSACTION: không mở transaction.
     * =====================================================================
     */
    private function payload(AiImport $import): array
    {
        return [
            'job_id' => $import->id, 'status' => $import->status, 'current_step' => $import->current_step,
            'progress' => (int) $import->progress, 'error_code' => $import->error_code,
            'source_type' => data_get($import->input_json, 'source_type', filled($import->source_url) ? 'url' : 'text'),
            'source_url' => data_get($import->input_json, 'source_type') === 'text' ? null : $import->source_url,
            'error' => $import->error_message, 'session_id' => $import->session_id ?: $import->id,
            'parent_id' => $import->parent_id, 'operation' => $import->operation,
            'applied_target_id' => $import->applied_target_id, 'applied_fields' => $import->applied_fields,
            ...($import->result_json ?? []),
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chặn admin truy cập import của user khác
     * =====================================================================
     * INPUT: authenticated request và AiImport route-bound.
     * OUTPUT: không trả giá trị khi owner hợp lệ; abort 404 nếu không khớp.
     * SIDE EFFECT: không ghi database.
     * EXCEPTION/TRANSACTION: abort 404; không mở transaction.
     * =====================================================================
     */
    private function ensureOwner(Request $request, AiImport $import): void
    {
        abort_unless((int) $import->created_by === (int) $request->user()->getKey(), 404);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Dọn thumbnail tạm chưa attach của một import
     * =====================================================================
     * INPUT: AiImport có result thumbnail media_asset_id.
     * OUTPUT: không trả giá trị.
     * SIDE EFFECT: clear collection và soft-delete asset nếu chưa có usage.
     * EXCEPTION/TRANSACTION: không mở transaction tổng; lỗi media truyền lên caller.
     * =====================================================================
     */
    private function cleanupThumbnail(AiImport $import): void
    {
        $id = data_get($import->result_json, 'draft.thumbnail.media_asset_id');
        if ($id && ($asset = MediaAsset::query()->find($id)) && ! $asset->usages()->exists()) {
            $asset->clearMediaCollection('library');
            $asset->delete();
        }
    }
}
