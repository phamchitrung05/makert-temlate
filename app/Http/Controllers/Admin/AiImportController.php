<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Posts\CreatePostAction;
use App\Actions\Posts\UpdatePostAction;
use App\Enums\AiCapability;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AiCandidateApplyRequest;
use App\Http\Requests\Admin\AiImportRequest;
use App\Http\Responses\BaseResponse;
use App\Models\AiImport;
use App\Models\Post;
use App\Services\Ai\AiProvenanceService;
use App\Services\Ai\AiRunAssetCleaner;
use App\Services\Ai\AiRunService;
use App\Services\Ai\ArticleSourceFetcher;
use App\Services\Ai\ModelResolver;
use App\Services\Ai\Registries\PromptRegistry;
use App\Services\Ai\Registries\ProviderRegistry;
use App\Services\Ai\Registries\SchemaRegistry;
use App\Services\Ai\Registries\TargetRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
 * =====================================================================
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
            'providers' => $providers->publicOptions(AiCapability::Text),
            'image_providers' => $providers->publicOptions(AiCapability::Image),
        ], 'Capability AI của target.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo tác vụ nội dung với snapshot model và ảnh tùy chọn
     * =====================================================================
     * INPUT: URL/text cùng các lựa chọn đã được AiImportRequest kiểm tra.
     * OUTPUT: 202 chứa job queued hoặc job trùng trong cửa sổ idempotency.
     * SIDE EFFECT: Đọc defaults; AiRunService ghi run và dispatch queue, không gọi model tại HTTP request.
     * EXCEPTION/TRANSACTION: ValidationException khi lựa chọn không hợp lệ; quota/idempotency và transaction do AiRunService quản lý.
     * =====================================================================
     */
    public function store(
        AiImportRequest $request,
        ArticleSourceFetcher $fetcher,
        ModelResolver $resolver,
        PromptRegistry $prompts,
        AiRunService $runs,
    ): JsonResponse {
        if (! config('ai-import.enabled', true)) {
            return BaseResponse::error('AI import đang tắt.', 503);
        }
        $userId = (int) $request->user()->getKey();
        $data = $request->validated();
        $sourceType = filled($data['text'] ?? null) ? 'text' : 'url';
        $normalizedUrl = $sourceType === 'url'
            ? $fetcher->validateUrl((string) $data['url'])
            : null;
        $connection = $resolver->resolve(AiCapability::Text, array_filter([
            'provider' => $data['provider'] ?? null,
            'model' => $data['model'] ?? null,
            'model_id' => $data['model_id'] ?? null,
        ], static fn (mixed $value): bool => filled($value)));
        $imageConnection = null;
        if (($data['thumbnail_mode'] ?? 'auto') === 'generate' && ($data['generate_thumbnail'] ?? true)
            && $request->user()->can('media.upload')) {
            try {
                $imageConnection = $resolver->resolve(AiCapability::Image, array_filter([
                    'provider' => $data['image_provider'] ?? null,
                    'model' => $data['image_model'] ?? null,
                    'model_id' => $data['image_model_id'] ?? null,
                ], static fn (mixed $value): bool => filled($value)));
            } catch (\Illuminate\Validation\ValidationException) {
                /**
                 * =================================================================
                 * GHI CHÚ: Ảnh là capability tùy chọn; content run vẫn hợp lệ.
                 * =================================================================
                 * Không ghi secret vào input khi image model chưa resolve được.
                 * =================================================================
                 */
                $imageConnection = null;
            }
        }
        $providerKey = (string) $connection['provider'];
        $model = (string) ($connection['model'] ?? 'default');
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
            'model_id' => $connection['model_id'] ?? null,
            /**
             * =================================================================
             * GHI CHÚ: Snapshot identity không chứa API key.
             * =================================================================
             * Worker resolve key mã hóa lại theo provider_id khi chạy queue.
             * =================================================================
             */
            'ai_connection' => $connection,
            'image_connection' => $imageConnection,
        ];
        $sourceHashValue = $sourceType === 'text' ? trim((string) $data['text']) : (string) $normalizedUrl;
        $hash = hash('sha256', $sourceType.'|'.$sourceHashValue.'|'.json_encode($input, JSON_UNESCAPED_UNICODE).'|'.config('ai-import.prompt_version', 'v1'));
        $import = $runs->create($userId, [
            'source_url' => $sourceType === 'url' ? $data['url'] : '',
            'source_text' => $sourceType === 'text' ? trim((string) $data['text']) : null,
            'normalized_url' => $normalizedUrl, 'source_hash' => $hash, 'status' => 'queued',
            'current_step' => 'queued', 'progress' => 0, 'input_json' => $input,
            'provider' => $providerKey, 'prompt_version' => config('ai-import.prompt_version', 'v1'),
        ]);

        return BaseResponse::success($this->payload($import), 'Đã xếp hàng import bài viết.', 202);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đọc tiến trình và kết quả của tác vụ thuộc admin hiện tại
     * =====================================================================
     * INPUT: Request xác thực và AiImport route-bound.
     * OUTPUT: Status, progress và candidate qua public payload.
     * SIDE EFFECT: Chỉ đọc lại bản ghi; không ghi DB hoặc gọi provider.
     * EXCEPTION/TRANSACTION: Abort 404 nếu không phải owner; không mở transaction.
     * =====================================================================
     */
    public function show(Request $request, AiImport $aiImport): JsonResponse
    {
        $this->ensureOwner($request, $aiImport);

        return BaseResponse::success($this->payload($aiImport->fresh()), 'Trạng thái import.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo candidate mới từ nguồn cũ, giữ nguyên lịch sử run
     * =====================================================================
     * INPUT: Run đã terminal, prompt/model/fields override tùy chọn.
     * OUTPUT: 202 chứa child run mới trong cùng session.
     * SIDE EFFECT: AiRunService ghi child run và dispatch queue; không ghi đè candidate cũ.
     * EXCEPTION/TRANSACTION: Abort 404/409/422 hoặc ValidationException; quota/transaction do AiRunService quản lý.
     * =====================================================================
     */
    public function regenerate(Request $request, AiImport $aiImport, PromptRegistry $prompts, ModelResolver $resolver, AiRunService $runs): JsonResponse
    {
        $this->ensureOwner($request, $aiImport);
        abort_if($aiImport->operation === 'image', 422, 'Tạo candidate ảnh mới qua tác vụ tạo ảnh.');
        if (in_array($aiImport->status, ['queued', 'fetching', 'extracting', 'rewriting', 'seo', 'thumbnail'], true)) {
            return BaseResponse::error('Import đang được xử lý.', 409);
        }
        $options = $request->validate([
            'prompt_key' => ['sometimes', 'string', 'max:120'],
            'instructions' => ['sometimes', 'string', 'max:4000'],
            'provider' => ['sometimes', 'nullable', 'string', 'max:80'],
            'model' => ['sometimes', 'nullable', 'string', 'max:190'],
            'model_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'fields' => ['sometimes', 'array'],
            'fields.*' => ['string', 'distinct', 'in:title,excerpt,content,seo,taxonomy,thumbnail'],
        ]);
        $currentInput = (array) $aiImport->input_json;
        $hasModelOverride = array_key_exists('provider', $options)
            || array_key_exists('model', $options)
            || array_key_exists('model_id', $options);
        $selection = $hasModelOverride
            ? array_intersect_key($options, array_flip(['provider', 'model', 'model_id']))
            : array_intersect_key($currentInput, array_flip(['provider', 'model', 'model_id']));
        if (! array_key_exists('provider', $options) && filled($options['model'] ?? null)
            && ! filled($options['model_id'] ?? null)) {
            $selection['provider'] = $currentInput['provider'] ?? null;
        }
        $connection = ! $hasModelOverride && ! empty($currentInput['ai_connection'])
            ? (array) $currentInput['ai_connection']
            : $resolver->resolve(AiCapability::Text, array_filter($selection, static fn (mixed $value): bool => filled($value)));
        if (array_key_exists('prompt_key', $options)) {
            try {
                $prompts->get((string) $options['prompt_key'], 'post', 'create');
            } catch (\InvalidArgumentException) {
                throw \Illuminate\Validation\ValidationException::withMessages(['prompt_key' => 'Prompt không nằm trong allowlist của Post.']);
            }
        }
        $childInput = array_replace((array) $aiImport->input_json, array_filter($options, fn ($value) => $value !== null));
        $childInput['provider'] = $connection['provider'];
        $childInput['model'] = $connection['model'] ?? null;
        $childInput['model_id'] = $connection['model_id'] ?? null;
        $childInput['ai_connection'] = $connection;
        $child = $runs->create((int) $request->user()->getKey(), [
            'session_id' => $aiImport->session_id ?: $aiImport->id, 'parent_id' => $aiImport->id,
            'operation' => 'regenerate', 'source_url' => $aiImport->source_url,
            'source_text' => $aiImport->source_text, 'source_hash' => $aiImport->source_hash,
            'normalized_url' => $aiImport->normalized_url, 'input_json' => $childInput,
            'provider' => $connection['provider'], 'prompt_version' => $aiImport->prompt_version,
        ], true);

        return BaseResponse::success($this->payload($child->fresh()), 'Đã xếp hàng tạo candidate mới.', 202);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đưa lại run lỗi vào queue mà không tạo candidate mới
     * =====================================================================
     * INPUT: Run failed/cancelled/expired thuộc actor; image run cần media.upload.
     * OUTPUT: Run hiện tại đã reset trạng thái queued.
     * SIDE EFFECT: Cập nhật lifecycle/errors rồi dispatch job theo operation.
     * EXCEPTION/TRANSACTION: Abort 403/404 hoặc trả 409 khi run chưa terminal; không mở transaction tổng.
     * =====================================================================
     */
    public function retry(Request $request, AiImport $aiImport, AiRunService $runs): JsonResponse
    {
        $this->ensureOwner($request, $aiImport);
        if ($aiImport->operation === 'image') {
            abort_unless($request->user()->can('media.upload'), 403);
        }
        if (! in_array($aiImport->status, ['failed', 'cancelled', 'expired'], true)) {
            return BaseResponse::error('Chỉ có thể retry run đã kết thúc lỗi.', 409);
        }
        $aiImport->forceFill([
            'status' => 'queued', 'current_step' => 'queued', 'progress' => 0,
            'error_code' => null, 'error_message' => null, 'completed_at' => null,
        ])->save();
        $runs->dispatch($aiImport);

        return BaseResponse::success($this->payload($aiImport->fresh()), 'Đã xếp hàng retry run.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Liệt kê candidate nội dung trong cùng session để so sánh
     * =====================================================================
     * INPUT: Run thuộc actor dùng để xác định session.
     * OUTPUT: Danh sách candidate không bao gồm image run.
     * SIDE EFFECT: Chỉ query ai_imports; không gọi model.
     * EXCEPTION/TRANSACTION: Abort 404 nếu không phải owner; không mở transaction.
     * =====================================================================
     */
    public function candidates(Request $request, AiImport $aiImport): JsonResponse
    {
        $this->ensureOwner($request, $aiImport);
        $sessionId = $aiImport->session_id ?: $aiImport->id;
        $items = AiImport::query()->where(function ($query) use ($sessionId): void {
            $query->where('session_id', $sessionId)->orWhereKey($sessionId);
        })->where('operation', '!=', 'image')->latest()->get();

        return BaseResponse::success($items->map(fn (AiImport $item): array => $this->payload($item))->values()->all(), 'Danh sách candidate.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Áp dụng field được chọn vào Post draft và ghi provenance
     * =====================================================================
     * INPUT: Run ready, fields whitelist, target_id và expected_updated_at tùy chọn.
     * OUTPUT: Post ID, các field đã áp dụng và metadata nguồn AI.
     * SIDE EFFECT: Ghi Post/media/SEO và provenance qua domain actions; không gọi model.
     * EXCEPTION/TRANSACTION: DB transaction bao toàn bộ apply; abort 409 khi candidate/target không còn hợp lệ.
     * =====================================================================
     */
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

    /**
     * =====================================================================
     * CHỨC NĂNG: Hủy tác vụ đang chạy và dọn asset tạm chưa được dùng
     * =====================================================================
     * INPUT: AiImport route-bound thuộc actor.
     * OUTPUT: Payload cancelled hoặc trạng thái terminal hiện tại.
     * SIDE EFFECT: Dọn thumbnail chưa có usage và cập nhật lifecycle; không hủy HTTP provider đã gửi.
     * EXCEPTION/TRANSACTION: Abort 404 khi ownership không khớp; không mở transaction tổng.
     * =====================================================================
     */
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

    /**
     * =====================================================================
     * CHỨC NĂNG: Xóa run của actor và dọn thumbnail chưa attach
     * =====================================================================
     * INPUT: AiImport route-bound thuộc actor.
     * OUTPUT: Response thành công không có payload.
     * SIDE EFFECT: Dọn asset chưa có usage rồi xóa bản ghi run; không gọi provider.
     * EXCEPTION/TRANSACTION: Abort 404 khi ownership không khớp; lỗi lưu trữ/DB truyền lên caller.
     * =====================================================================
     */
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
        app(AiRunAssetCleaner::class)->cleanup($import);
    }
}
