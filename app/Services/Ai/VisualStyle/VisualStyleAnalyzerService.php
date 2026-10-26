<?php

namespace App\Services\Ai\VisualStyle;

use App\Actions\Media\UploadMediaAssetAction;
use App\Enums\AiCapability;
use App\Enums\MediaAssetField;
use App\Enums\MediaAssetKind;
use App\Enums\MediaAssetVisibility;
use App\Jobs\AnalyzeVisualStyleJob;
use App\Models\AiImageProfile;
use App\Models\User;
use App\Models\VisualStyleShot;
use App\Services\Ai\Data\AiTaskRequest;
use App\Services\Ai\Providers\Catalog\ModelResolver;
use App\Services\Ai\Registries\ProviderRegistry;
use App\Services\Ai\Runs\AiTaskRunService;
use App\Services\MediaAssetUsageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Queue và thực thi phân tích ảnh mẫu thành Visual Style DNA.
 * =====================================================================
 * Service là điểm vào VSE-2: queue() lưu ảnh tham chiếu vào disk local, tạo
 * AiImageProfile status=queued kèm connection snapshot rồi dispatch
 * AnalyzeVisualStyleJob sau commit; process() chạy trong worker — claim job,
 * gọi Vision provider, validate DNA bằng VisualStyleDnaDefinition và ghi
 * dna_json + suggested_shots trong một transaction có lock để cancel không bị
 * ghi đè. Mọi provider call đều nằm ở process() (worker), không ở request HTTP.
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nhận resolver/registry/definition dùng chung.
 * - queue(): kiểm queue/quota/model, lưu ảnh, tạo profile queued và dispatch job.
 * - process(): claim queued→analyzing, gọi provider, validate và lưu DNA/shots.
 * - createSuggestedShots(): tạo VisualStyleShot nháp (is_active=false).
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : name/model_id/category + UploadedFile ảnh mẫu; id profile khi process.
 * - OUTPUT: profile queued khi queue(); DNA + status analyzed khi process() xong.
 * - SIDE EFFECT: storage local, DB, queue dispatch; một provider call trong worker.
 * - EXCEPTION/TRANSACTION: queue() bọc transaction cho profile+storage; process()
 *   khóa profile lockForUpdate khi ghi kết quả để cancel giữa chừng thắng worker.
 * =====================================================================
 */
final class VisualStyleAnalyzerService
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Nhận hạ tầng model/provider và schema DNA dùng chung.
     * =====================================================================
     * Input: resolver, registry, definition. Output: service có dependencies.
     * Side effect: không ghi DB/gọi network.
     * =====================================================================
     */
    public function __construct(
        private readonly ModelResolver $models,
        private readonly ProviderRegistry $providers,
        private readonly VisualStyleDnaDefinition $definition,
    ) {}

    /**
     * =====================================================================
     * CHỨC NĂNG: Lưu ảnh mẫu, tạo profile queued và dispatch worker sau commit.
     * =====================================================================
     * Input: values đã validate (name, model_id, category tùy chọn), file ảnh,
     * actorId. Output: AiImageProfile status=queued với task_run_id nếu có.
     * Side effect: ghi file vào disk local, INSERT ai_image_profiles, dispatch
     * AnalyzeVisualStyleJob afterCommit; ảnh bị xóa nếu transaction lỗi.
     * Exception: ValidationException khi queue sync/quota đầy/model thiếu Vision.
     * =====================================================================
     */
    public function queue(array $values, ?UploadedFile $image, int $actorId): AiImageProfile
    {
        $connection = (string) config('queue.default');
        if (in_array(config('queue.connections.'.$connection.'.driver', $connection), ['sync', 'null'], true)) {
            throw ValidationException::withMessages(['image' => 'Phân tích phong cách cần queue worker bất đồng bộ.']);
        }
        $quota = max(1, (int) config('ai.import.quota_per_hour', 20));
        if (AiImageProfile::query()->where('created_by', $actorId)->where('created_at', '>=', now()->subHour())->count() >= $quota) {
            throw ValidationException::withMessages(['image' => 'Đã đạt giới hạn phân tích phong cách trong một giờ.']);
        }
        $snapshot = $this->models->resolve(AiCapability::Vision, ['model_id' => $values['model_id']]);
        if (! in_array(AiCapability::Text->value, $snapshot['capabilities'] ?? [], true)
            || ! in_array($snapshot['driver'], ['openai', 'openai-compatible', 'gemini'], true)) {
            throw ValidationException::withMessages(['model_id' => 'Chọn model có Text generation và Vision của OpenAI, OpenAI-compatible hoặc Gemini.']);
        }

        // Resolve ảnh tham chiếu: upload mới HOẶC media_asset_id đã có trong library.
        $resolved = $this->resolveReference($values, $image, $actorId);
        $asset = $resolved['asset'];
        $mediaItem = $resolved['mediaItem'];
        $path = $resolved['path'];
        $mime = $resolved['mime'];
        $referenceHash = hash('sha256', $resolved['bytes']);

        $isNewUpload = (bool) $image;
        try {
            return DB::transaction(function () use ($values, $actorId, $snapshot, $asset, $mediaItem, $path, $mime, $referenceHash): AiImageProfile {
                $profile = AiImageProfile::query()->create([
                    'created_by' => $actorId,
                    'name' => $values['name'],
                    'category' => $values['category'] ?? null,
                    'status' => 'queued',
                    'reference_path' => $path,
                    'reference_mime' => $mime,
                    'reference_hash' => $referenceHash,
                    'reference_media_id' => $asset->id,
                    'connection_snapshot_json' => $snapshot,
                    'analysis_instructions' => (string) config('ai.visual_style_dna.analysis_instructions'),
                    'schema_version' => VisualStyleDnaDefinition::SCHEMA_VERSION,
                    'version' => 1,
                    'is_enabled' => false,
                ]);
                // Gắn asset vào field visual_style.reference để usage/quản lý đúng chuẩn.
                // authorize=false vì asset vừa do chính actor upload qua
                // UploadMediaAssetAction (không phải attach asset người khác).
                app(MediaAssetUsageService::class)->attach(
                    User::query()->findOrFail($actorId),
                    $profile,
                    $asset,
                    MediaAssetField::VisualStyleReference,
                    authorize: false,
                );
                $taskRun = app(AiTaskRunService::class)->register($profile);
                // Giữ tracker id trong bản ghi trả về để popup queue hủy đúng ngay.
                $profile->setAttribute('task_run_id', $taskRun->id);
                AnalyzeVisualStyleJob::dispatch($profile->id, (int) ($snapshot['timeout'] ?? 30))->afterCommit();

                return $profile;
            });
        } catch (\Throwable $exception) {
            // Rollback upload: chỉ xóa asset mới tạo; media_asset_id từ library
            // là asset người dùng đã chọn — không được xóa.
            if ($isNewUpload) {
                try {
                    $asset->forceDelete();
                } catch (\Throwable) {
                    // Giữ nguyên lỗi gốc; cleanup thất bại không chặn throw.
                }
            }
            throw $exception;
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Resolve ảnh tham chiếu từ upload mới hoặc media_asset_id.
     * =====================================================================
     * Input: values (media_asset_id?) + UploadedFile nullable + actorId.
     * Output: {asset, mediaItem, path, mime, bytes}; ValidationException khi
     * thiếu cả hai nguồn hoặc asset không phải ảnh/không tồn tại.
     * Side effect: upload mới tạo MediaAsset qua pipeline; không ghi DB cho
     * đường media_asset_id (chỉ đọc file + trả MediaAsset).
     * =====================================================================
     *
     * @return array{asset: \App\Models\MediaAsset, mediaItem: \Spatie\MediaLibrary\MediaCollections\Models\Media|null, path: string, mime: string, bytes: string}
     */
    private function resolveReference(array $values, ?UploadedFile $image, int $actorId): array
    {
        // Ưu tiên ảnh upload trực tiếp khi cả hai được gửi (giữ tương thích cũ).
        if ($image) {
            $asset = app(UploadMediaAssetAction::class)->handle(
                $image,
                MediaAssetKind::Image,
                (string) $values['name'],
                $actorId,
                MediaAssetVisibility::Private,
            );
            $mediaItem = $asset->getFirstMedia('library');
            $path = $mediaItem?->getPath() ?? '';
            $bytes = $mediaItem && is_string($path) && file_exists($path)
                ? file_get_contents($path)
                : (string) ($mediaItem?->get() ?? '');

            return [
                'asset' => $asset,
                'mediaItem' => $mediaItem,
                'path' => $path,
                'mime' => $image->getMimeType(),
                'bytes' => $bytes,
            ];
        }

        $mediaId = (int) ($values['media_asset_id'] ?? 0);
        if ($mediaId <= 0) {
            throw ValidationException::withMessages(['image' => 'Cần ảnh tham chiếu hoặc chọn media asset.']);
        }
        $asset = \App\Models\MediaAsset::query()->findOrFail($mediaId);
        // Chỉ chấp nhận ảnh: media.kind phải là image (khớp MediaAssetKind::Image).
        if (($asset->kind?->value ?? $asset->kind) !== 'image') {
            throw ValidationException::withMessages(['media_asset_id' => 'Media asset được chọn phải là ảnh.']);
        }
        $mediaItem = $asset->getFirstMedia('library');
        $path = $mediaItem?->getPath() ?? '';
        if ($path === '' || ! file_exists($path)) {
            throw ValidationException::withMessages(['media_asset_id' => 'File media không còn trên đĩa.']);
        }
        $bytes = file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            throw ValidationException::withMessages(['media_asset_id' => 'Không đọc được nội dung media asset.']);
        }

        return [
            'asset' => $asset,
            'mediaItem' => $mediaItem,
            'path' => $path,
            'mime' => $mediaItem?->mime_type ?? 'image/jpeg',
            'bytes' => $bytes,
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Claim profile queued, gọi Vision provider và lưu DNA đã validate.
     * =====================================================================
     * Input: id profile đã queue. Output: void; status analyzed/failed lưu trên model.
     * Side effect: đọc file local, một provider execute, transaction ghi
     * dna_json/status/summary/schema_version và VisualStyleShot nháp.
     * Exception: RuntimeException khi ảnh tham chiếu đổi; ValidationException
     * của Definition được job quy về status failed.
     * =====================================================================
     */
    public function process(int $id): void
    {
        if (! AiImageProfile::query()->whereKey($id)->where('status', 'queued')->update(['status' => 'analyzing', 'updated_at' => now()])) {
            return;
        }
        $profile = AiImageProfile::query()->findOrFail($id);
        // Đọc bytes từ MediaAsset (nguồn chuẩn) hoặc fallback path raw cho style cũ.
        // Lưu ý: MediaLibrary::get() trả StreamInterface, không phải bytes —
        // phải đọc qua file_get_contents(path) hoặc disk để lấy nội dung thật.
        $bytes = null;
        if ($profile->reference_media_id) {
            $mediaItem = $profile->referenceMedia?->getFirstMedia('library');
            if ($mediaItem) {
                $mediaPath = $mediaItem->getPath();
                $bytes = is_string($mediaPath) && file_exists($mediaPath)
                    ? file_get_contents($mediaPath)
                    : (string) $mediaItem->get();
            }
        }
        if ($bytes === null || $bytes === false || $bytes === '') {
            $bytes = Storage::disk('local')->get($profile->reference_path);
        }
        if (! hash_equals((string) $profile->reference_hash, hash('sha256', $bytes))) {
            throw new \RuntimeException('Ảnh tham chiếu của tác vụ đã thay đổi.');
        }
        $taxonomyContext = $this->taxonomyContext();
        $response = $this->providers->resolveForRun($profile->connection_snapshot_json)->execute(new AiTaskRequest(
            task: 'visual-style.analysis',
            systemInstructions: (string) ($profile->analysis_instructions ?: config('ai.visual_style_dna.analysis_instructions')),
            input: [
                'requested_name' => $profile->name,
                'existing_categories' => $taxonomyContext['categories'],
                'existing_tags' => $taxonomyContext['tags'],
            ],
            schema: $this->definition->schema(),
            options: [
                'prompt_key' => 'visual-style.analyze-reference',
                'prompt_version' => VisualStyleDnaDefinition::PROMPT_VERSION,
                'schema_version' => VisualStyleDnaDefinition::SCHEMA_VERSION,
                'model' => $profile->connection_snapshot_json['model'] ?? null,
                'images' => [['mime_type' => $profile->reference_mime, 'data' => base64_encode($bytes)]],
            ],
        ));
        $dna = $this->definition->validate($response->output);
        DB::transaction(function () use ($id, $dna, $response, $taxonomyContext): void {
            // Khóa profile trước khi ghi: cancel/archive thắng thì worker không ghi đè.
            $current = AiImageProfile::query()->lockForUpdate()->findOrFail($id);
            if ($current->status !== 'analyzing') {
                return;
            }
            $suggestedShots = (array) ($dna['suggested_shots'] ?? []);
            // Chuyển taxonomy_suggestion thô → suggestion có ID resolve sẵn để
            // form edit prefill; chỉ unset suggested_shots (đã tách sang bảng).
            $resolved = $this->resolveTaxonomySuggestion((array) ($dna['taxonomy_suggestion'] ?? []), $taxonomyContext);
            unset($dna['suggested_shots']);
            if ($resolved !== null) {
                $dna['taxonomy_suggestion'] = $resolved;
            }
            $current->forceFill([
                'status' => 'analyzed',
                'summary' => $response->output['summary'] ?? null,
                'dna_json' => $dna,
                'schema_version' => VisualStyleDnaDefinition::SCHEMA_VERSION,
                'error_message' => null,
            ])->save();
            $this->createSuggestedShots($current, $suggestedShots);
            $this->applyTaxonomySuggestion($current, $resolved);
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đọc taxonomy hiện có để gửi cho AI đối chiếu.
     * =====================================================================
     * Input: không có. Output: {categories:[{id,name}], tags:[{id,name}]} bounded;
     * chỉ lấy bản ghi đang dùng, sắp theo tên.
     * Side effect: chỉ đọc DB; không ghi.
     * =====================================================================
     *
     * @return array{categories: array<int, array{id:int,name:string}>, tags: array<int, array{id:int,name:string}>}
     */
    private function taxonomyContext(): array
    {
        $load = function (string $model, int $limit): array {
            return $model::query()
                ->whereNotNull('name')->where('name', '!=', '')
                ->orderBy('name')->limit($limit)
                ->get(['id', 'name'])
                ->map(fn ($item) => ['id' => (int) $item->id, 'name' => (string) $item->name])
                ->all();
        };

        return [
            'categories' => $load(\App\Models\Category::class, 30),
            'tags' => $load(\App\Models\Tag::class, 50),
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Resolve taxonomy_suggestion của AI sang ID bản ghi hiện có.
     * =====================================================================
     * Input: suggestion thô {action, category, tags, new_category, new_tags} và
     *   taxonomyContext đã gửi cho AI. Output: suggestion đã kèm *_ids khi
     *   action=match_existing; propose_new giữ tên thô + id=null (chưa tạo).
     * Side effect: chỉ đọc taxonomyContext trong bộ nhớ; không ghi DB.
     * =====================================================================
     */
    private function resolveTaxonomySuggestion(array $suggestion, array $context): ?array
    {
        $byName = fn (array $list) => collect($list)->keyBy(fn ($item) => mb_strtolower($item['name']));

        $categories = $byName($context['categories']);
        $tags = $byName($context['tags']);

        $matched = (array) ($suggestion['matched'] ?? []);
        $proposed = (array) ($suggestion['proposed'] ?? []);
        // Format cũ (action/category/new_category) → quy về dual-list.
        $matchedCategoryNames = (array) ($matched['categories'] ?? (isset($suggestion['category']) ? [$suggestion['category']] : []));
        $matchedTagNames = (array) ($matched['tags'] ?? ($suggestion['tags'] ?? []));
        $proposedCategoryNames = (array) ($proposed['new_categories'] ?? (isset($suggestion['new_category']) ? [$suggestion['new_category']] : []));
        $proposedTagNames = (array) ($proposed['new_tags'] ?? ($suggestion['new_tags'] ?? []));

        // Resolve matched names → {id, name} cho từng bản ghi tìm thấy.
        $matchedCategories = collect($matchedCategoryNames)
            ->map(fn ($name) => $categories->get(mb_strtolower((string) $name)))
            ->filter()->values()
            ->map(fn ($c) => ['id' => (int) $c['id'], 'name' => (string) $c['name']])
            ->all();
        $matchedTags = collect($matchedTagNames)
            ->map(fn ($name) => $tags->get(mb_strtolower((string) $name)))
            ->filter()->values()
            ->map(fn ($t) => ['id' => (int) $t['id'], 'name' => (string) $t['name']])
            ->all();

        if ($matchedCategories === [] && $matchedTags === [] && $proposedCategoryNames === [] && $proposedTagNames === []) {
            return null;
        }

        return [
            'matched' => [
                'categories' => $matchedCategories,
                'tags' => $matchedTags,
            ],
            'proposed' => [
                'new_categories' => array_values(array_filter(array_map(fn ($n) => trim((string) $n) ?: null, $proposedCategoryNames))),
                'new_tags' => array_values(array_filter(array_map(fn ($n) => trim((string) $n) ?: null, $proposedTagNames))),
            ],
            'confidence' => $suggestion['confidence'] ?? null,
            'reason' => $suggestion['reason'] ?? null,
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Gắn category/tag đã resolve vào pivot khi AI khớp bản ghi có sẵn.
     * =====================================================================
     * Input: style đã lock và suggestion đã resolve (category_id/tag_ids).
     * Output: attach pivot khớp ID có sẵn; không tạo entity mới.
     * Side effect: syncWithoutDetaching; chỉ khi match_existing có ID.
     * =====================================================================
     */
    private function applyTaxonomySuggestion(AiImageProfile $style, ?array $suggestion): void
    {
        $matched = (array) ($suggestion['matched'] ?? []);
        $categoryIds = collect((array) ($matched['categories'] ?? []))
            ->map(fn ($c) => (int) ($c['id'] ?? 0))
            ->filter()->values()->all();
        $tagIds = collect((array) ($matched['tags'] ?? []))
            ->map(fn ($t) => (int) ($t['id'] ?? 0))
            ->filter()->values()->all();
        if ($categoryIds !== []) {
            $style->categories()->syncWithoutDetaching($categoryIds);
        }
        if ($tagIds !== []) {
            $style->tags()->syncWithoutDetaching($tagIds);
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo shot recipe nháp từ suggested_shots của AI (is_active=false).
     * =====================================================================
     * Input: profile đã lock và danh sách shot đã qua validate của Definition.
     * Output: void; shot trùng code hiện có bị bỏ qua, sort_order nối tiếp.
     * Side effect: INSERT visual_style_shots; chạy trong transaction của caller.
     * =====================================================================
     */
    private function createSuggestedShots(AiImageProfile $profile, array $shots): void
    {
        $existing = $profile->shots()->pluck('code')->all();
        $sort = (int) ($profile->shots()->max('sort_order') ?? 0);
        foreach ($shots as $shot) {
            if (in_array($shot['code'], $existing, true)) {
                continue;
            }
            VisualStyleShot::query()->create([
                'visual_style_id' => $profile->id,
                'code' => $shot['code'],
                'name' => $shot['name'],
                'description' => $shot['description'] ?? null,
                'composition_override_json' => $shot['composition_override'] ?? null,
                'camera_override_json' => $shot['camera_override'] ?? null,
                'prompt_suffix' => $shot['prompt_suffix'] ?? null,
                'suggested_aspect_ratio' => $shot['suggested_aspect_ratio'] ?? null,
                'sort_order' => ++$sort,
                'is_active' => false,
            ]);
        }
    }
}
