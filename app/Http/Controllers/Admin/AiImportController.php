<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AiCapability;
use App\Enums\MediaAssetKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AiCandidateApplyRequest;
use App\Http\Requests\Admin\AiCandidateUpdateRequest;
use App\Http\Requests\Admin\AiImportRequest;
use App\Http\Requests\Admin\AiSessionIndexRequest;
use App\Http\Resources\AiSessionSummaryResource;
use App\Http\Resources\MediaAssetResource;
use App\Http\Responses\BaseResponse;
use App\Models\AiImport;
use App\Models\AiTaskRun;
use App\Models\MediaAsset;
use App\Services\Ai\Content\AiContentReviewService;
use App\Services\Ai\Content\AiContentSanitizer;
use App\Services\Ai\Content\Archives\AiArticleArchiveService;
use App\Services\Ai\Content\ArticleSourceFetcher;
use App\Services\Ai\Images\AiThumbnailService;
use App\Services\Ai\Providers\Catalog\ModelResolver;
use App\Services\Ai\Providers\Diagnostics\AiResponseDiagnostics;
use App\Services\Ai\Registries\PromptRegistry;
use App\Services\Ai\Registries\ProviderRegistry;
use App\Services\Ai\Registries\SchemaRegistry;
use App\Services\Ai\Registries\TargetRegistry;
use App\Services\Ai\Runs\AiRunAssetCleaner;
use App\Services\Ai\Runs\AiRunService;
use App\Services\Ai\Runs\AiTaskRunService;
use App\Services\Ai\Settings\AiSettingsService;
use App\Services\Media\ContentMediaReferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: HTTP API tạo, polling, retry, hủy và dọn AI import.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - index(): danh sách import/candidate có pagination, filter target và owner scope.
 * - targets(), capabilities(): trả catalog target/model được phép cho form AI.
 * - store(): tạo import và dispatch generation mới.
 * - regenerate(): tạo candidate generation mới từ run parent.
 * - retry(): reset run terminal, tăng generation và dispatch lại tracker.
 * - show(): đọc detail run của owner.
 * - candidates(): đọc candidate cùng session của owner.
 * - updateCandidate(): sửa candidate theo version/status.
 * - apply(): apply bản ready qua service/action.
 * - cancel(): hủy lifecycle run.
 * - destroy(): xóa mềm run theo rule terminal.
 * - payload(): serialize run/thumbnail bounded cho API.
 * - ensureOwner(): kiểm actor sở hữu run trước mutation/detail.
 * - loadThumbnails(), cleanupThumbnail(): hydrate và dọn media thumbnail domain.
 * INPUT: admin request URL/options hoặc UUID job; OUTPUT: envelope JSON.
 * SIDE EFFECT: tạo/dispatch queue job, cập nhật vòng đời và dọn thumbnail tạm.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): request admin -> tác vụ/candidate an toàn.
 * AUTHORIZATION: permission theo config target và kiểm tra owner bản ghi.
 * EXCEPTION/TRANSACTION: apply() dùng transaction + lock trong Post Actions;
 * candidate chỉ được apply khi ready và không tin provider/model từ client.
 * =====================================================================
 */
class AiImportController extends Controller
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Đọc danh sách tác vụ thuộc admin hiện tại
Đọc danh sách tác vụ và candidate con còn hạn của chính admin hiện tại.
     * =====================================================================
     *
     * INPUT:
     * - page/per_page đã validate, actor đã qua auth/posts.manage.
     *
     * OUTPUT:
     * - summary phân trang mới nhất trước; loại run ảnh và target không có quyền.
     *
     * SIDE EFFECT:
     * - query DB và tải thumbnail/media theo lô; không dispatch hoặc gọi AI.
     *
     * EXCEPTION/TRANSACTION:
     * - không mở transaction; middleware kiểm tra quyền.
     *
     * =====================================================================
     */
    public function index(AiSessionIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $allowedTargets = collect(app(TargetRegistry::class)->all())
            ->filter(fn (array $target): bool => $request->user()->can($target['permission'] ?? 'posts.manage'))
            ->keys()->all();
        abort_if($allowedTargets === [], 403);
        $paginator = AiImport::query()
            ->where('created_by', $request->user()->getKey())
            ->where(function ($query) use ($allowedTargets): void {
                $query->whereIn('input_json->target_type', $allowedTargets);
                if (in_array('post', $allowedTargets, true)) {
                    $query->orWhereNull('input_json->target_type');
                }
            })
            ->where(fn ($query) => $query->whereNull('operation')->orWhereIn('operation', ['create', 'regenerate']))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where('status', '!=', 'expired')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 100), ['*'], 'page', (int) ($filters['page'] ?? 1));

        $this->loadThumbnails($paginator->getCollection());

        return BaseResponse::paginated(AiSessionSummaryResource::collection($paginator), 'Danh sách tác vụ viết bài AI.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Liệt kê target AI theo quyền của admin
     * =====================================================================
     *
     * INPUT:
     * - request xác thực và registry.
     *
     * OUTPUT:
     * - JSON target/options public.
     *
     * SIDE EFFECT:
     * - chỉ đọc cấu hình; không gọi provider hoặc ghi DB.
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction/lock; middleware/controller xác thực actor và quyền trước khi gọi.
     *
     * =====================================================================
     */
    public function targets(Request $request, TargetRegistry $targets): JsonResponse
    {
        $items = collect($targets->all())
            ->filter(fn (array $target): bool => $request->user()->can($target['permission'] ?? 'posts.manage'))
            ->map(fn (array $target): array => [
                'key' => $target['key'], 'label' => $target['label'] ?? $target['key'],
                'icon' => $target['icon'] ?? 'tabler-file-text', 'color' => $target['color'] ?? 'primary',
                'outputs' => array_values($target['outputs'] ?? []),
                'output_options' => $targets->outputOptions($target['key']),
            ])->values()->all();
        abort_if($items === [], 403);

        return BaseResponse::success($items, 'Tài nguyên AI được phép tạo.');
    }

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
        Request $request,
        string $target,
        TargetRegistry $targets,
        ProviderRegistry $providers,
        PromptRegistry $prompts,
        SchemaRegistry $schemas,
        AiSettingsService $settings,
    ): JsonResponse {
        try {
            $targetConfig = $targets->get($target);
        } catch (\InvalidArgumentException) {
            return BaseResponse::error('AI target chưa được bật.', 404);
        }
        abort_unless($request->user()->can($targetConfig['permission'] ?? 'posts.manage'), 403);

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

        $contentSettings = $settings->all();

        return BaseResponse::success([
            'target_type' => $target,
            'operations' => array_values($targetConfig['operations'] ?? []),
            'input_types' => array_values($targetConfig['inputs'] ?? []),
            'outputs' => array_values($targetConfig['outputs'] ?? []),
            'output_options' => $targets->outputOptions($target),
            'prompts' => $promptItems,
            'schemas' => $schemaItems,
            'providers' => $providers->publicOptions(AiCapability::Text),
            'image_providers' => $providers->publicOptions(AiCapability::Image),
            'content_defaults' => [
                'model_id' => $contentSettings['default_text_model_id'],
                'generate_thumbnail' => $contentSettings['auto_thumbnail'],
                'generate_seo' => $contentSettings['auto_seo'],
                'min_word_count' => $contentSettings['min_word_count'],
                'default_writing_profile_id' => $contentSettings['default_writing_profile_id'] ?? null,
            ],
            'writing_profile_options_endpoint' => '/api/admin/ai/writing-profiles/options',
            'writing_brief_fields' => ['audience', 'article_type', 'purpose', 'angle', 'length'],
            'manual_taxonomy' => true,
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
        AiSettingsService $settings,
    ): JsonResponse {
        if (! config('ai-import.enabled', true)) {
            return BaseResponse::error('AI import đang tắt.', 503);
        }
        $userId = (int) $request->user()->getKey();
        $data = $request->validated();
        $contentSettings = $settings->all();
        $generateThumbnail = (bool) ($data['generate_thumbnail'] ?? $contentSettings['auto_thumbnail']);
        $generateSeo = (bool) ($data['generate_seo'] ?? $contentSettings['auto_seo']);
        $targetType = (string) ($data['target_type'] ?? 'post');
        $sourceHtml = $request->hasFile('html_file') ? (string) $request->file('html_file')->getContent() : (string) ($data['html'] ?? '');
        $encoding = (string) ($data['source_encoding'] ?? 'UTF-8');
        if ($sourceHtml !== '' && $encoding !== 'UTF-8') {
            $sourceHtml = mb_convert_encoding($sourceHtml, 'UTF-8', $encoding);
        }
        $sourceType = filled($data['text'] ?? null) || $sourceHtml !== '' ? 'text' : 'url';
        $normalizedUrl = $sourceType === 'url'
            ? $fetcher->validateUrl((string) $data['url'])
            : null;
        $connection = $resolver->resolve(AiCapability::Text, array_filter([
            'provider' => $data['provider'] ?? null,
            'model' => $data['model'] ?? null,
            'model_id' => $data['model_id'] ?? null,
        ], static fn (mixed $value): bool => filled($value)));
        $connection['generate_seo'] = $generateSeo;
        $imageConnection = null;
        if (($data['thumbnail_mode'] ?? 'auto') === 'generate' && $generateThumbnail) {
            abort_unless($request->user()->can('media.upload'), 403, 'Cần quyền tải media để tạo thumbnail AI.');
            try {
                $imageConnection = $resolver->resolve(AiCapability::Image, array_filter([
                    'provider' => $data['image_provider'] ?? null,
                    'model' => $data['image_model'] ?? null,
                    'model_id' => $data['image_model_id'] ?? null,
                ], static fn (mixed $value): bool => filled($value)));
            } catch (ValidationException $exception) {
                throw ValidationException::withMessages(['image_model_id' => 'Chọn model ảnh đã bật, khả dụng và có provider hỗ trợ tạo ảnh.']);
            }
        }
        $providerKey = (string) $connection['provider'];
        $model = (string) ($connection['model'] ?? 'default');
        try {
            $prompt = $prompts->select(
                isset($data['prompt_key']) ? (string) $data['prompt_key'] : null,
                $targetType,
                'create',
                ['source_type' => $sourceType, 'language' => (string) ($data['language'] ?? 'vi')],
            );
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages([
                'prompt_key' => 'Prompt không nằm trong allowlist của tài nguyên đã chọn.',
            ]);
        }
        $promptKey = (string) $prompt['key'];
        $input = [
            'target_type' => $targetType,
            'source_type' => $sourceType,
            'source_format' => $sourceHtml !== '' ? 'html' : 'text',
            'language' => $data['language'] ?? 'vi',
            'rewrite_style' => $data['rewrite_style'] ?? 'informative',
            'generate_thumbnail' => $generateThumbnail,
            'generate_seo' => $generateSeo,
            'thumbnail_mode' => $data['thumbnail_mode'] ?? 'auto',
            'thumbnail_prompt' => $data['thumbnail_prompt'] ?? '',
            'prompt_key' => $promptKey,
            'instructions' => $data['instructions'] ?? '',
            'writing_brief' => $data['writing_brief'] ?? [],
            'writing_profile_id' => $data['writing_profile_id'] ?? null,
            'category_ids' => $data['category_ids'] ?? [],
            'tag_ids' => $data['tag_ids'] ?? [],
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
        if (array_key_exists('requested_outputs', $data)) {
            $input['requested_outputs'] = array_values($data['requested_outputs']);
            $input['fields'] = $input['requested_outputs'];
        }
        if (filled($data['title'] ?? null)) {
            $input['title'] = $data['title'];
        }
        $sourceText = $sourceHtml !== '' ? $sourceHtml : trim((string) ($data['text'] ?? ''));
        $sourceHashValue = $sourceType === 'text' ? $sourceText : (string) $normalizedUrl;
        $hash = hash('sha256', $sourceType.'|'.$sourceHashValue.'|'.json_encode($input, JSON_UNESCAPED_UNICODE).'|'.config('ai-import.prompt_version', 'v1'));
        $import = $runs->create($userId, [
            'source_url' => $sourceType === 'url' ? $data['url'] : '',
            'source_text' => $sourceType === 'text' ? $sourceText : null,
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
     * SIDE EFFECT: Chụp parent dưới row lock; AiRunService ghi child và dispatch sau commit.
     * EXCEPTION/TRANSACTION: Abort 404/409/422 hoặc ValidationException; quota/transaction do AiRunService quản lý.
     * =====================================================================
     */
    public function regenerate(Request $request, AiImport $aiImport, PromptRegistry $prompts, ModelResolver $resolver, AiRunService $runs): JsonResponse
    {
        $aiImport = DB::transaction(fn (): AiImport => AiImport::query()->lockForUpdate()->findOrFail($aiImport->id));
        $this->ensureOwner($request, $aiImport);
        abort_if($aiImport->operation === 'image', 422, 'Tạo candidate ảnh mới qua tác vụ tạo ảnh.');
        if (in_array($aiImport->status, AiImport::RUNNING_STATUSES, true)) {
            return BaseResponse::error('Import đang được xử lý.', 409);
        }
        $options = $request->validate([
            'prompt_key' => ['sometimes', 'nullable', 'string', 'max:120'],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'writing_profile_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'exists:ai_writing_profiles,id'],
            'writing_brief' => ['sometimes', 'array:audience,article_type,purpose,angle,length'],
            'writing_brief.*' => ['nullable', 'string', 'max:1000'],
            'category_ids' => ['sometimes', 'array', 'max:100'],
            'category_ids.*' => ['integer', 'distinct', Rule::exists('categories', 'id')->where('status', 'active')->whereNull('deleted_at')],
            'tag_ids' => ['sometimes', 'array', 'max:100'],
            'tag_ids.*' => ['integer', 'distinct', Rule::exists('tags', 'id')->where('status', 'active')->whereNull('deleted_at')],
            'refresh_source' => ['sometimes', 'boolean'],
            'provider' => ['sometimes', 'nullable', 'string', 'max:80'],
            'model' => ['sometimes', 'nullable', 'string', 'max:190'],
            'model_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'thumbnail_mode' => ['sometimes', 'in:auto,source,generate'],
            'thumbnail_prompt' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'image_provider' => ['sometimes', 'nullable', 'string', 'max:80'],
            'image_model' => ['sometimes', 'nullable', 'string', 'max:190'],
            'image_model_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'fields' => ['sometimes', 'array'],
            'fields.*' => ['string', 'distinct', Rule::in((array) config('ai-agent.targets.'.($aiImport->input_json['target_type'] ?? 'post').'.outputs', []))],
        ]);
        $currentInput = (array) $aiImport->input_json;
        $profileOverride = array_key_exists('writing_profile_id', $options);
        $profileSelection = $options['writing_profile_id'] ?? null;
        $options['fields'] = $options['fields'] ?? [];
        $options = array_filter($options, static fn ($value): bool => $value !== null && $value !== '');
        $hasModelOverride = filled($options['provider'] ?? null)
            || filled($options['model'] ?? null)
            || filled($options['model_id'] ?? null);
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
                $prompts->get((string) $options['prompt_key'], (string) ($currentInput['target_type'] ?? 'post'), 'create');
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages(['prompt_key' => 'Prompt không hỗ trợ tài nguyên của bài này.']);
            }
        }
        $childInput = array_replace((array) $aiImport->input_json, array_filter($options, fn ($value) => $value !== null));
        $parentDraft = (array) data_get($aiImport->result_json, 'draft', []);
        $childInput['parent_draft_snapshot'] = $parentDraft;
        $manualTaxonomy = ($parentDraft['taxonomy_origin'] ?? null) === 'manual'
            || ($currentInput['taxonomy_origin'] ?? null) === 'manual';
        foreach (['category_ids', 'tag_ids'] as $taxonomyField) {
            $childInput[$taxonomyField] = $options[$taxonomyField]
                ?? ($manualTaxonomy ? ($parentDraft[$taxonomyField] ?? $currentInput[$taxonomyField] ?? []) : []);
        }
        $childInput['taxonomy_origin'] = 'manual';
        if (! ($options['refresh_source'] ?? false) && ($aiImport->expires_at?->isPast()
            || ($aiImport->status === 'ready' && isset($currentInput['pipeline_snapshot']) && ! data_get($aiImport->source_meta_json, 'article_source')))) {
            throw ValidationException::withMessages(['refresh_source' => 'Snapshot nguồn đã hết hạn hoặc không còn. Gửi refresh_source=true để đọc lại nguồn.']);
        }
        if ($profileOverride) {
            $childInput['writing_profile_id'] = $profileSelection;
            unset($childInput['writing_profile_snapshot']);
        }
        $childInput['provider'] = $connection['provider'];
        $childInput['model'] = $connection['model'] ?? null;
        $childInput['model_id'] = $connection['model_id'] ?? null;
        $connection['generate_seo'] = (bool) ($currentInput['generate_seo'] ?? true);
        $childInput['ai_connection'] = $connection;
        // An explicitly requested output takes priority over the original automatic defaults.
        if (in_array('seo', $options['fields'], true)) {
            $childInput['generate_seo'] = true;
            $childInput['ai_connection']['generate_seo'] = true;
        }
        if (in_array('thumbnail', $options['fields'], true)
            || ($options['fields'] === [] && ($options['thumbnail_mode'] ?? null) === 'generate')) {
            $childInput['generate_thumbnail'] = true;
        }
        if (($options['fields'] === [] || in_array('thumbnail', $options['fields'], true)) && ($childInput['generate_thumbnail'] ?? true)) {
            if (($childInput['thumbnail_mode'] ?? 'auto') === 'generate') {
                abort_unless($request->user()->can('media.upload'), 403, 'Cần quyền tải media để tạo thumbnail AI.');
                $hasImageOverride = filled($options['image_provider'] ?? null) || filled($options['image_model'] ?? null) || filled($options['image_model_id'] ?? null);
                $selection = $hasImageOverride ? [
                    'provider' => $options['image_provider'] ?? null, 'model' => $options['image_model'] ?? null, 'model_id' => $options['image_model_id'] ?? null,
                ] : array_intersect_key((array) ($childInput['image_connection'] ?? []), array_flip(['provider', 'model', 'model_id']));
                $selection = array_filter($selection, static fn (mixed $value): bool => filled($value));
                try {
                    $childInput['image_connection'] = $resolver->resolve(AiCapability::Image, $selection);
                } catch (ValidationException) {
                    throw ValidationException::withMessages(['image_model_id' => 'Chọn model ảnh hợp lệ trước khi tạo lại thumbnail.']);
                }
            }
        }
        $child = $runs->create((int) $request->user()->getKey(), [
            'session_id' => $aiImport->session_id ?: $aiImport->id, 'parent_id' => $aiImport->id,
            'operation' => 'regenerate', 'source_url' => $aiImport->source_url,
            'source_text' => $aiImport->source_text, 'source_hash' => $aiImport->source_hash,
            'normalized_url' => $aiImport->normalized_url, 'input_json' => $childInput,
            'source_meta_json' => ! ($options['refresh_source'] ?? false) && data_get($aiImport->source_meta_json, 'article_source')
                ? ['article_source' => data_get($aiImport->source_meta_json, 'article_source')] : [],
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
     * SIDE EFFECT: Cập nhật timeout theo provider hiện tại, lifecycle/retention và tracker projection;
     * giữ identity/input, bỏ checkpoint chưa duyệt của lần trước, tăng generation_no;
     * dispatch sau commit. Bản đã Apply không được retry.
     * EXCEPTION/TRANSACTION: Lock row trong transaction; 403/404/409 hoặc validation
     * khi connection đã đổi/tắt; không gọi provider trong transaction.
     * =====================================================================
     */
    public function retry(Request $request, AiImport $aiImport, AiRunService $runs, ProviderRegistry $providers): JsonResponse
    {
        $this->ensureOwner($request, $aiImport);
        if ($aiImport->operation === 'image') {
            abort_unless($request->user()->can('media.upload'), 403);
        }
        $retried = Cache::lock('ai-import-process-'.$aiImport->id, 10)->get(fn () => DB::transaction(function () use ($aiImport, $runs, $providers): AiImport {
            app(AiThumbnailService::class)->prepareRetry($aiImport);
            $run = AiImport::query()->lockForUpdate()->findOrFail($aiImport->id);
            abort_unless(in_array($run->status, ['failed', 'cancelled', 'expired'], true), 409, 'Chỉ có thể retry run đã kết thúc lỗi.');
            abort_if($run->applied_target_id !== null || data_get($run->source_meta_json, 'editorial.status') === 'approved', 409, 'Bản đã duyệt không được retry. Hãy tạo bản mới.');
            $archives = app(AiArticleArchiveService::class);
            $input = (array) $run->input_json;
            foreach (['ai_connection', 'image_connection'] as $key) {
                $snapshot = (array) ($input[$key] ?? []);
                if (empty($snapshot['provider_id'])) {
                    continue;
                }
                $capability = $key === 'image_connection' || $run->operation === 'image' ? AiCapability::Image : AiCapability::Text;
                try {
                    $input[$key] = $providers->connectionForRun($snapshot, $capability, true)->snapshot;
                } catch (\InvalidArgumentException $exception) {
                    throw ValidationException::withMessages(['provider' => $exception->getMessage()]);
                }
            }
            $run->forceFill([
                'status' => 'queued', 'current_step' => 'queued', 'progress' => 0,
                'input_json' => $input, 'error_code' => null, 'error_message' => null, 'completed_at' => null,
                'generation_no' => (int) $run->generation_no + 1,
                'archive_version' => $archives->supports($run) ? 1 : null,
                'archive_pending_json' => null, 'result_json' => null, 'started_at' => null,
                'expires_at' => now()->addDays((int) config('ai-import.retention_days', 2)),
            ])->save();
            app(AiThumbnailService::class)->sync($run);
            // Tạo projection generation mới trước khi dispatch để popup thấy retry ngay.
            app(AiTaskRunService::class)->registerImport($run->refresh());
            DB::afterCommit(fn () => $runs->dispatch($run));

            return $run;
        }));
        abort_if($retried === false, 409, 'Worker cũ đang kết thúc. Hãy chờ trước khi thử lại.');
        $aiImport = $retried;

        return BaseResponse::success($this->payload($aiImport->fresh()), 'Đã xếp hàng retry run.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Liệt kê candidate nội dung trong cùng session để so sánh
     * =====================================================================
     * INPUT: Run thuộc actor dùng để xác định session.
     * OUTPUT: Danh sách candidate không bao gồm image run.
     * SIDE EFFECT: Query ai_imports và tải thumbnail/media theo lô; không gọi model.
     * EXCEPTION/TRANSACTION: Abort 404 nếu không phải owner; không mở transaction.
     * =====================================================================
     */
    public function candidates(Request $request, AiImport $aiImport): JsonResponse
    {
        $this->ensureOwner($request, $aiImport);
        $sessionId = $aiImport->session_id ?: $aiImport->id;
        $items = AiImport::query()->where(function ($query) use ($sessionId): void {
            $query->where('session_id', $sessionId)->orWhere('id', $sessionId);
        })->where('created_by', $request->user()->getKey())
            ->where(fn ($query) => $query->whereNull('operation')->orWhere('operation', '!=', 'image'))->latest()->get();

        $this->loadThumbnails($items);

        return BaseResponse::success($items->map(fn (AiImport $item): array => $this->payload($item))->values()->all(), 'Danh sách candidate.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Sửa candidate với version lock và xác thực ảnh MediaLibrary
     * =====================================================================
     *
     * Sửa candidate chờ duyệt, kiểm tra phiên bản và sanitize HTML tại backend.
     *
     * INPUT:
     * - title/content/excerpt/SEO và hash phiên bản từ GET detail.
     *
     * OUTPUT:
     * - candidate cập nhật; không ghi domain model hoặc gọi AI.
     *
     * SIDE EFFECT:
     * - lock row, ghi result_json và audit trong cùng transaction.
     *
     * EXCEPTION/TRANSACTION:
     * - 409 khi đã duyệt/từ chối/hết hạn hoặc version cũ; không gọi model. Transaction khóa run; thiếu quyền ảnh/validation truyền ra và rollback result/audit.
     *
     * =====================================================================
     */
    public function updateCandidate(AiCandidateUpdateRequest $request, AiImport $aiImport, AiContentSanitizer $sanitizer): JsonResponse
    {
        $this->ensureOwner($request, $aiImport);
        $data = $request->validated();
        $run = DB::transaction(function () use ($aiImport, $data, $sanitizer, $request): AiImport {
            $run = AiImport::query()->lockForUpdate()->findOrFail($aiImport->id);
            abort_unless($run->status === 'ready' && ! $run->applied_target_id && $run->operation !== 'image', 409, 'Chỉ sửa candidate sẵn sàng chưa được áp dụng.');
            abort_if(AiContentReviewService::state($run)['status'] === 'rejected', 409, 'Bài đã bị từ chối. Hãy tạo bản mới để biên tập.');
            abort_if($run->expires_at?->isPast(), 409, 'Candidate đã hết hạn.');
            $result = (array) $run->result_json;
            $draft = (array) ($result['draft'] ?? []);
            abort_unless(hash_equals(hash('sha256', json_encode($draft)), $data['expected_version']), 409, 'Nội dung đã thay đổi. Hãy mở lại bài trước khi lưu.');
            unset($data['expected_version']);
            app(ContentMediaReferenceService::class)->validate($data['content_html'], $request->user());
            $data['content_html'] = $sanitizer->sanitize($data['content_html']);
            $data['content_image_ids'] = app(ContentMediaReferenceService::class)->validate($data['content_html'], $request->user());
            if (trim(strip_tags($data['content_html'])) === '') {
                throw ValidationException::withMessages(['content_html' => 'Nội dung không được rỗng sau khi làm sạch HTML.']);
            }
            $data['content'] = $data['content_html'];
            if (array_key_exists('category_ids', $data) || array_key_exists('tag_ids', $data)) {
                $data['taxonomy_origin'] = 'manual';
            }
            $result['draft'] = array_replace($draft, $data);
            $run->update(['result_json' => $result]);
            activity('ai-content')->causedBy($request->user())
                ->withProperties(['candidate_id' => $run->id, 'fields' => array_keys($data)])->log('candidate.edited');

            return $run;
        });

        return BaseResponse::success($this->payload($run), 'Đã lưu nội dung AI.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: API Apply cũ dùng service duyệt để tạo/cập nhật Post draft và ghi audit
     * =====================================================================
     * INPUT: Run ready, fields whitelist, target_id, expected_version và expected_updated_at tùy chọn.
     * OUTPUT: Post ID, các field đã áp dụng, provenance và quyết định biên tập.
     * SIDE EFFECT: service ghi Post/media/SEO, provenance, JSON quyết định và Spatie Activitylog.
     * EXCEPTION/TRANSACTION: service khóa run/target trong DB transaction;
     * 409 khi stale/đã quyết định/hết hạn, không Publish hoặc gọi model.
     * =====================================================================
     */
    public function apply(AiCandidateApplyRequest $request, AiImport $aiImport, AiContentReviewService $review): JsonResponse
    {
        $this->ensureOwner($request, $aiImport);

        return BaseResponse::success($review->approve($request->user(), $aiImport, $request->validated()), $request->filled('target_id') ? 'Đã duyệt và cập nhật Post nháp.' : 'Đã duyệt và tạo Post nháp.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Hủy tác vụ đang chạy và dọn asset tạm chưa được dùng
     * =====================================================================
     * INPUT: AiImport route-bound thuộc actor.
     * OUTPUT: Payload cancelled hoặc trạng thái terminal hiện tại.
     * SIDE EFFECT: Dọn thumbnail chưa có usage và cập nhật lifecycle; không hủy HTTP provider đã gửi.
     * EXCEPTION/TRANSACTION: Khóa row trước khi hủy; dọn asset sau transaction.
     * =====================================================================
     */
    public function cancel(Request $request, AiImport $aiImport): JsonResponse
    {
        $this->ensureOwner($request, $aiImport);
        $run = DB::transaction(function () use ($aiImport): AiImport {
            $run = AiImport::query()->lockForUpdate()->findOrFail($aiImport->id);
            if (! in_array($run->status, AiImport::TERMINAL_STATUSES, true)) {
                $run->update(['status' => 'cancelled', 'current_step' => 'cancelled', 'error_code' => 'CANCELLED', 'error_message' => 'Import đã bị hủy.', 'completed_at' => now()]);
            }

            return $run;
        });
        if ($run->status === 'cancelled') {
            app(AiThumbnailService::class)->sync($run);
            $this->cleanupThumbnail($run);
        }

        return BaseResponse::success($this->payload($run->fresh()), $run->status === 'cancelled' ? 'Đã hủy import.' : 'Import đã kết thúc.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Xóa run của actor và dọn thumbnail chưa attach
     * =====================================================================
     * INPUT: AiImport route-bound thuộc actor.
     * OUTPUT: Response thành công không có payload.
     * SIDE EFFECT: Đối chiếu archive rồi dọn asset/run; không gọi provider.
     * EXCEPTION/TRANSACTION: Khóa run trong transaction; lỗi archive giữ run để phục hồi.
     * =====================================================================
     */
    public function destroy(Request $request, AiImport $aiImport): JsonResponse
    {
        $this->ensureOwner($request, $aiImport);
        DB::transaction(function () use ($aiImport): void {
            $run = AiImport::query()->lockForUpdate()->findOrFail($aiImport->id);
            abort_if(in_array($run->status, AiImport::RUNNING_STATUSES, true), 409, 'Hãy đợi tác vụ kết thúc trước khi xóa.');
            app(AiArticleArchiveService::class)->preserve($run, removalReason: 'user_deleted');
            $this->cleanupThumbnail($run);
            $run->delete();
        });

        return BaseResponse::success(null, 'Đã xóa import.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn hóa payload lifecycle/candidate cho API response
     * =====================================================================
     * INPUT: AiImport đã qua ownership check hoặc record nội bộ.
     * OUTPUT: mảng public gồm status/progress/result; không lộ secret/path nội bộ.
     * SIDE EFFECT: đọc thumbnail/media nếu chưa tải; không ghi database.
     * EXCEPTION/TRANSACTION: không mở transaction.
     * =====================================================================
     */
    private function payload(AiImport $import): array
    {
        if (! $import->relationLoaded('thumbnail')) {
            $this->loadThumbnails(collect([$import]));
        }
        $thumbnail = $import->getRelation('thumbnail');
        $result = array_intersect_key((array) $import->result_json, array_flip([
            'source', 'draft', 'provider', 'model', 'prompt_key', 'prompt_version',
            'schema_version', 'requested_fields', 'image', 'image_job_id',
        ]));
        if (! in_array($import->status, ['ready', 'completed', 'succeeded'], true)) {
            unset($result['draft']);
        }
        $diagnostics = AiResponseDiagnostics::sanitize((array) data_get($import->source_meta_json, 'ai_response', []));
        $pipeline = (array) data_get($import->source_meta_json, 'article_pipeline', []);
        $taskRunId = AiTaskRun::query()
            ->where('taskable_type', AiImport::class)
            ->where('taskable_id', $import->id)
            ->where('dedupe_key', ($import->operation === 'image' ? 'image_generation' : 'article_generation').':'.$import->id.':'.(int) $import->generation_no)
            ->value('id');
        $steps = $import->steps->map(fn ($step): array => [
            'key' => $step->step_key, 'status' => $step->status, 'attempt' => $step->attempt,
            'diagnostics' => AiResponseDiagnostics::sanitize((array) $step->diagnostics_json),
            'started_at' => $step->started_at?->toIso8601String(), 'completed_at' => $step->completed_at?->toIso8601String(),
        ])->values()->all();

        return [
            ...$result,
            'job_id' => $import->id, 'status' => $import->status, 'current_step' => $import->current_step,
            'task_run_id' => $taskRunId,
            'generation_no' => (int) $import->generation_no,
            'steps' => $steps,
            // Allowlist dùng cả cho nhánh một lượt; không expose source/intermediate/raw response.
            'response_diagnostics' => $diagnostics,
            'quality_checks' => array_map(fn (array $check): array => array_intersect_key($check, array_flip(['check', 'status', 'reason', 'metric', 'detected', 'count'])), array_slice((array) ($pipeline['quality'] ?? []), 0, 20)),
            'image_warnings' => array_values(array_intersect((array) ($pipeline['image_warnings'] ?? []), ['restored_unplaced_images'])),
            'editor_warning_count' => max(0, min(100, (int) ($pipeline['editor_warning_count'] ?? 0))),
            'writing_profile' => array_intersect_key((array) data_get($import->input_json, 'writing_profile_snapshot', []), array_flip(['id', 'name', 'version'])),
            'source_format' => data_get($import->input_json, 'source_format', 'text'),
            'progress' => (int) $import->progress, 'error_code' => $import->error_code,
            'source_type' => data_get($import->input_json, 'source_type', filled($import->source_url) ? 'url' : 'text'),
            'source_url' => data_get($import->input_json, 'source_type') === 'text' ? null : $import->source_url,
            'error' => $import->error_message, 'session_id' => $import->session_id ?: $import->id,
            'validation_errors' => $import->status === 'failed' ? ($diagnostics['validation_errors'] ?? []) : [],
            'parent_id' => $import->parent_id, 'operation' => $import->operation,
            'target_type' => data_get($import->input_json, 'target_type', 'post'),
            'created_at' => $import->created_at?->toIso8601String(),
            'draft_version' => hash('sha256', json_encode(data_get($import->result_json, 'draft', []))),
            'review' => AiContentReviewService::state($import),
            'review_version' => AiContentReviewService::version($import),
            'expires_at' => $import->expires_at?->toIso8601String(),
            'applied_target_id' => $import->applied_target_id, 'applied_fields' => $import->applied_fields,
            'thumbnail' => $thumbnail ? MediaAssetResource::make($thumbnail) : null,
            'thumbnail_generation' => AiThumbnailService::state($import),
            'thumbnail_options' => ['mode' => data_get($import->input_json, 'thumbnail_mode', 'source'),
                'model_id' => data_get($import->input_json, 'image_connection.model_id'),
                'prompt' => data_get($import->input_json, 'thumbnail_prompt', '')],
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tải thumbnail và media theo lô cho public payload
     * =====================================================================
     *
     * INPUT:
     * - collection AiImport.
     *
     * OUTPUT:
     * - relation thumbnail hoặc null.
     *
     * SIDE EFFECT:
     * - query asset/media; không tạo ảnh hoặc ghi DB.
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction/lock; middleware/controller xác thực actor và quyền trước khi gọi.
     *
     * =====================================================================
     */
    private function loadThumbnails(Collection $imports): void
    {
        $unloaded = $imports->reject(fn (AiImport $import): bool => $import->relationLoaded('thumbnail'));
        $assetIds = $unloaded
            ->map(fn (AiImport $import): int => (int) data_get($import->result_json, 'draft.thumbnail.media_asset_id', 0))
            ->filter(fn (int $id): bool => $id > 0)->unique()->values();
        $assets = $assetIds->isEmpty()
            ? collect()
            : MediaAsset::query()->ofKind(MediaAssetKind::Image)->whereKey($assetIds)
                ->with('media')->get()->keyBy('id');

        // Spatie reads the parent model when resolving conversion URLs.
        foreach ($assets as $asset) {
            foreach ($asset->media as $media) {
                $media->setRelation('model', $asset);
            }
        }

        foreach ($unloaded as $import) {
            $assetId = (int) data_get($import->result_json, 'draft.thumbnail.media_asset_id', 0);
            $import->setRelation('thumbnail', $assets->get($assetId));
        }
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
        $target = (string) data_get($import->input_json, 'target_type', 'post');
        abort_unless($request->user()->can(config('ai-agent.targets.'.$target.'.permission', 'posts.manage')), 403);
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
