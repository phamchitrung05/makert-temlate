<?php

namespace App\Services\Ai\VisualStyle;

use App\Enums\AiCapability;
use App\Exceptions\AiImportException;
use App\Jobs\GenerateVisualStyleImagesJob;
use App\Models\AiImageProfile;
use App\Models\AiImport;
use App\Models\MediaAsset;
use App\Models\VisualStyleShot;
use App\Services\Ai\Images\AiImageGenerationService;
use App\Services\Ai\Providers\Catalog\ModelResolver;
use App\Services\Ai\VisualStyle\PromptLayers\ProductContext;
use App\Services\Ai\VisualStyle\PromptLayers\VisualPromptContext;
use App\Services\Ai\Registries\ProviderRegistry;
use App\Services\Ai\Runs\AiTaskRunService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Queue và thực thi sinh ảnh theo Visual Style + Shot Recipe.
 * =====================================================================
 * Service là điểm vào VSE-5: generate() kiểm style active+enabled, kiểm
 * shot_recipe_ids thuộc style và is_active, chuẩn hoá product/aspect/count/
 * instructions, resolve connection image qua ModelResolver rồi tạo AiImport
 * queued và dispatch GenerateVisualStyleImagesJob sau commit. process() chạy
 * trong worker — claim import, loop từng shot recipe, build prompt qua
 * VisualPromptBuilder, gọi image provider qua AiImageGenerationService và
 * upload kết quả thành MediaAsset; mỗi shot ghi status riêng vào output_json
 * để retry/lọc lỗi từng phần, lỗi một shot không dừng batch.
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nhận resolver/registry/builder/image service dùng chung.
 * - generate(): validate + tạo AiImport + dispatch job sau commit.
 * - process(): claim queued→processing, loop recipes → prompt → provider →
 *   media; cập nhật per-shot status và tổng trạng thái ready/partial/failed.
 * - buildPrompts(): dựng prompt+negative cho từng shot (thuần, không DB).
 * - persistShot(): ghi output của một shot vào result_json/import.
 * - resolveSnapshot(): chọn snapshot ai_connection cho Image capability.
 * - product(): chuẩn hoá product context input.
 * - shots(): lọc recipe hợp lệ thuộc style.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : values đã qua FormRequest; UUID import khi process.
 * - OUTPUT: AiImport queued khi generate(); import ready/partial/failed khi
 *   process() xong; MediaAsset cho mỗi ảnh thành công.
 * - SIDE EFFECT: DB (ai_imports), queue dispatch; provider HTTP chỉ ở process().
 * - EXCEPTION/TRANSACTION: generate() bọc transaction cho AiImport; process()
 *   lockForUpdate import khi ghi trạng thái; lỗi shot ghi failed riêng và tiếp
 *   tục shot khác; lỗi claim/stale → return sớm.
 * =====================================================================
 */
final class VisualStyleGenerationService
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Nhận hạ tầng model/provider/prompt/image dùng chung.
     * =====================================================================
     * Input: resolver, registry, builder, image service, task tracker.
     * Output: service có dependencies; không gọi network/DB lúc khởi tạo.
     * =====================================================================
     */
    public function __construct(
        private readonly ModelResolver $models,
        private readonly ProviderRegistry $providers,
        private readonly VisualPromptBuilder $prompts,
        private readonly AiImageGenerationService $images,
        private readonly AiTaskRunService $tasks,
    ) {}

    /**
     * =====================================================================
     * CHỨC NĂNG: Validate input, tạo AiImport queued và dispatch worker sau commit.
     * =====================================================================
     * Input: values đã qua FormRequest (style_id, shot_recipe_ids, product,
     * aspect_ratio, image_count, instructions), actorId.
     * Output: AiImport operation visual_style_generate status=queued; job
     * được dispatch afterCommit để đảm bảo import đã commit trước khi worker đọc.
     * Side effect: INSERT ai_imports, dispatch GenerateVisualStyleImagesJob;
     * không gọi provider.
     * Exception: ValidationException khi style/recipe/count/ratio/model không
     * hợp lệ hoặc queue driver sync.
     * =====================================================================
     */
    public function generate(array $values, int $actorId): AiImport
    {
        $connection = (string) config('queue.default');
        if (in_array(config('queue.connections.'.$connection.'.driver', $connection), ['sync', 'null'], true)) {
            throw ValidationException::withMessages(['style_id' => 'Sinh ảnh cần queue worker bất đồng bộ.']);
        }

        $style = AiImageProfile::query()->find((int) ($values['style_id'] ?? 0));
        // "Sẵn sàng generate" = đã có DNA (analyzed/active/ready) + user đã bật cờ
        // is_enabled trong dialog edit. `active` chỉ là nhãn lifecycle — không còn
        // bắt buộc làm điều kiện generate nữa (UI không có bước Activate riêng).
        $usable = $style
            && in_array($style->status, ['analyzed', 'ready', 'active'], true)
            && $style->is_enabled;
        if (! $usable) {
            throw ValidationException::withMessages(['style_id' => 'Visual style chưa sẵn sàng (cần đã phân tích + bật dùng).']);
        }

        $shots = $this->shots($style, (array) ($values['shot_recipe_ids'] ?? []));
        if ($shots->isEmpty()) {
            throw ValidationException::withMessages(['shot_recipe_ids' => 'Chọn ít nhất một shot recipe hợp lệ của style.']);
        }

        $imagesConfig = (array) config('ai.content.social_images', []);
        $imageCount = (int) ($values['image_count'] ?? ($imagesConfig['default'] ?? 1));
        $min = max(1, (int) ($imagesConfig['min'] ?? 1));
        $max = max(1, (int) ($imagesConfig['max'] ?? 10));
        if ($imageCount < $min || $imageCount > $max) {
            throw ValidationException::withMessages(['image_count' => "Số ảnh phải trong khoảng {$min}–{$max}."]);
        }

        $snapshot = $this->resolveSnapshot($values);

        $aspectRatio = $this->aspectRatio($values);
        $product = $this->product($values);
        $instructions = $this->instructions($values);

        return DB::transaction(function () use ($style, $shots, $snapshot, $aspectRatio, $imageCount, $product, $instructions, $actorId): AiImport {
            $import = AiImport::query()->create([
                'created_by' => $actorId,
                'source_url' => '',
                'source_hash' => hash('sha256', Str::uuid()->toString()),
                'status' => 'queued',
                'current_step' => 'queued',
                'progress' => 0,
                'input_json' => [
                    'operation_name' => 'visual_style_generate',
                    'visual_style_id' => $style->id,
                    'style_version' => (int) ($style->version ?? 1),
                    'shot_recipe_ids' => $shots->pluck('id')->values()->all(),
                    'product' => $product,
                    'aspect_ratio' => $aspectRatio,
                    'image_count' => $imageCount,
                    'user_instructions' => $instructions,
                    'ai_connection' => $snapshot,
                ],
                'source_meta_json' => [],
                'provider' => (string) ($snapshot['provider'] ?? 'none'),
                'operation' => 'visual_style_generate',
                'expires_at' => now()->addDays((int) config('ai.import.retention_days', 2)),
            ]);
            $this->tasks->registerImport($import);
            GenerateVisualStyleImagesJob::dispatch((string) $import->getKey())->afterCommit();

            return $import;
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Claim import, loop từng shot recipe và sinh ảnh.
     * =====================================================================
     * Input: UUID import đã queue.
     * Output: void; import ready/partial/failed với result_json chứa outputs
     * per-shot (media_asset_id, prompt_used, recipe_code, status, error).
     * Side effect: một provider call cho mỗi shot × image_count; upload vào
     * Media Library; transaction ghi status cuối và per-shot.
     * Exception: lỗi shot ghi failed riêng trong output rồi tiếp shot khác;
     * lỗi claim/stale → return; lỗi không mong đợi ném lên job failed().
     * =====================================================================
     */
    public function process(string $importId): void
    {
        $import = AiImport::query()->find($importId);
        if (! $import || ! in_array($import->status, ['queued', 'processing'], true)) {
            return;
        }
        // Claim bằng UPDATE có điều kiện: chỉ một worker được đi tiếp.
        if (! AiImport::query()->whereKey($importId)->whereIn('status', ['queued', 'processing'])
            ->update(['status' => 'processing', 'current_step' => 'processing', 'started_at' => now(), 'updated_at' => now()])) {
            return;
        }
        $import = $import->fresh();
        $task = $this->tasks->syncFromImport($import);
        if ($task) {
            $this->tasks->start($task, 1, 'processing');
        }

        $input = (array) $import->input_json;
        $style = AiImageProfile::query()->find((int) ($input['visual_style_id'] ?? 0));
        if (! $style) {
            $this->fail($import, 'AI_STYLE_MISSING', 'Visual style không còn tồn tại.');

            return;
        }
        $snapshot = (array) ($input['ai_connection'] ?? []);
        if ($snapshot === []) {
            $this->fail($import, 'AI_IMAGE_MODEL_MISSING', 'Chưa cấu hình image model cho Visual Style.');

            return;
        }
        try {
            $connection = $this->providers->connectionForRun($snapshot, AiCapability::Image);
        } catch (\Throwable $exception) {
            $this->fail($import, 'AI_IMAGE_CONNECTION_FAILED', $exception->getMessage());

            return;
        }

        $shots = VisualStyleShot::query()
            ->whereIn('id', (array) ($input['shot_recipe_ids'] ?? []))
            ->where('visual_style_id', $style->id)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('id')
            ->get();
        if ($shots->isEmpty()) {
            $this->fail($import, 'AI_SHOT_MISSING', 'Shot recipe không còn khả dụng.');

            return;
        }

        $imageCount = max(1, (int) ($input['image_count'] ?? 1));
        $product = $this->productFromInput((array) ($input['product'] ?? []));
        $instructions = (array) ($input['user_instructions'] ?? []);
        $aspectRatio = (string) ($input['aspect_ratio'] ?? '');
        $actorId = (int) $import->created_by;
        $referenceImages = $this->referenceImages($style, $product);
        // reference images

        $outputs = [];
        $progressBase = 5;
        $progressStep = max(1, intdiv(90, $shots->count()));
        $totalOk = 0;
        $totalFail = 0;

        foreach ($shots as $index => $shot) {
            try {
                $prompts = $this->buildPrompts($style, $shot, $product, $instructions, $aspectRatio, $imageCount);
                $shotOutputs = [];
                $shotFailed = 0;
                foreach ($prompts as $slot => $prompt) {
                    try {
                        // generationMeta ghi provenance vào media.custom_properties['ai_generation']:
                        // source/style/shot/prompt dùng cho trace và regenerate.
                        $asset = $this->images->generate(
                            $connection,
                            $prompt,
                            $actorId,
                            ($shot->name ?: $shot->code).' · '.$style->name,
                            $shot->description,
                            [
                                'source' => 'visual_style',
                                'operation' => 'visual_style_generate',
                                'import_id' => (string) $import->id,
                                'visual_style_id' => $style->id,
                                'style_version' => (int) ($style->version ?? 1),
                                'shot_recipe_id' => $shot->id,
                                'shot_recipe_code' => $shot->code,
                                'prompt_used' => $prompt,
                                'aspect_ratio' => $aspectRatio !== '' ? $aspectRatio : ($shot->suggested_aspect_ratio ?: '1:1'),
                                'slot_no' => $slot,
                            ],
                            $referenceImages,
                        );
                        $shotOutputs[] = [
                            'slot_no' => $slot,
                            'status' => 'ready',
                            'media_asset_id' => $asset->getKey(),
                            'prompt_used' => $prompt,
                        ];
                        $totalOk++;
                    } catch (AiImportException $exception) {
                        $shotOutputs[] = [
                            'slot_no' => $slot,
                            'status' => 'failed',
                            'error_code' => $exception->errorCode,
                            'error_message' => Str::limit($exception->getMessage(), 300),
                            'prompt_used' => $prompt,
                        ];
                        $shotFailed++;
                        $totalFail++;
                    } catch (\Throwable $exception) {
                        // failed generation
                        $shotOutputs[] = [
                            'slot_no' => $slot,
                            'status' => 'failed',
                            'error_code' => 'AI_IMAGE_PROVIDER_FAILED',
                            'error_message' => Str::limit($exception->getMessage(), 300),
                            'prompt_used' => $prompt,
                        ];
                        $shotFailed++;
                        $totalFail++;
                    }
                }
                $outputs[$shot->code] = [
                    'recipe_code' => $shot->code,
                    'shot_id' => $shot->id,
                    'status' => $shotFailed === 0 ? 'ready' : ($shotFailed === count($shotOutputs) ? 'failed' : 'partial'),
                    'images' => $shotOutputs,
                ];
            } catch (\Throwable $exception) {
                $outputs[$shot->code] = [
                    'recipe_code' => $shot->code,
                    'shot_id' => $shot->id,
                    'status' => 'failed',
                    'error_code' => 'AI_PROMPT_BUILD_FAILED',
                    'error_message' => Str::limit($exception->getMessage(), 300),
                    'images' => [],
                ];
                $totalFail += $imageCount;
            }
            // Cập nhật tiến độ an toàn: terminal của user huỷ sẽ thắng.
            $progress = min(95, $progressBase + ($index + 1) * $progressStep);
            AiImport::query()->whereKey($importId)->whereIn('status', ['processing'])
                ->update(['progress' => $progress, 'updated_at' => now()]);
        }

        DB::transaction(function () use ($importId, $style, $snapshot, $outputs, $totalOk, $totalFail, $task): void {
            $current = AiImport::query()->lockForUpdate()->findOrFail($importId);
            if (in_array($current->status, AiImport::TERMINAL_STATUSES, true)) {
                return;
            }
            // Một phần thành công vẫn báo ready cho cả batch; caller lọc per-shot.
            $status = $totalOk === 0 ? 'failed' : 'ready';
            $current->forceFill([
                'status' => $status,
                'current_step' => $status,
                'progress' => 100,
                'result_json' => [
                    'visual_style_id' => $style->id,
                    'style_version' => (int) ($style->version ?? 1),
                    'provider' => (string) ($snapshot['provider'] ?? 'none'),
                    'model' => (string) ($snapshot['model'] ?? ''),
                    'outputs' => $outputs,
                    'summary' => ['succeeded' => $totalOk, 'failed' => $totalFail],
                ],
                'provider' => (string) ($snapshot['provider'] ?? 'none'),
                'error_code' => $totalOk === 0 ? 'AI_ALL_SHOTS_FAILED' : null,
                'error_message' => $totalOk === 0 ? 'Tất cả shot recipe đều thất bại.' : null,
                'completed_at' => now(),
            ])->save();
        });

        if ($task) {
            $this->tasks->syncFromImport(AiImport::query()->findOrFail($importId));
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Build prompt+negative cho từng shot; mỗi shot có thể trả
     * nhiều prompt khi image_count > 1.
     * =====================================================================
     * Input: style, shot recipe, product context, instructions, aspect, count.
     * Output: mảng prompt string theo slot; negative prompt trả riêng qua
     * prompt kèm suffix tránh (vì image contract hiện chỉ nhận 1 chuỗi).
     * Side effect: thuần — không DB, không provider.
     * =====================================================================
     *
     * @return array<int, string>
     */
    private function buildPrompts(AiImageProfile $style, VisualStyleShot $shot, ?ProductContext $product, array $instructions, string $aspectRatio, int $imageCount): array
    {
        $context = new VisualPromptContext(
            style: $style,
            recipe: $shot,
            product: $product,
            instructions: $instructions,
            aspectRatio: $aspectRatio !== '' ? $aspectRatio : ($shot->suggested_aspect_ratio ?: null),
        );
        $prompt = $this->prompts->build($context);
        $negative = $this->prompts->negative($context);
        if ($negative !== '') {
            $prompt .= "\nAvoid: {$negative}";
        }
        if ($prompt === '') {
            throw new \RuntimeException('PromptBuilder không sinh được nội dung.');
        }

        $prompts = [];
        for ($slot = 0; $slot < $imageCount; $slot++) {
            $prompts[] = $imageCount > 1 ? $prompt.' Variation '.($slot + 1).'.' : $prompt;
        }

        return $prompts;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đọc MediaAsset của style reference + sản phẩm thành base64.
     * =====================================================================
     * INPUT: style (reference_media_id/reference_path) và ProductContext
     * (media_ids). OUTPUT: mảng `{base64, media_type}` để kèm prompt.
     * Side effect: đọc file từ disk/media library; bỏ qua asset lỗi/missing.
     * =====================================================================
     */
    private function referenceImages(AiImageProfile $style, ?ProductContext $product): array
    {
        $images = [];

        // Ảnh tham chiếu của style (media_asset_id hoặc path local).
        $styleImage = $this->readAssetImage(
            (int) ($style->reference_media_id ?? 0),
            (string) ($style->reference_path ?? ''),
            (string) ($style->reference_mime ?? 'image/png'),
        );
        if ($styleImage !== null) {
            $images[] = $styleImage;
        }

        // Ảnh sản phẩm theo mediaIds trong product context — ưu tiên URL public
        // của MediaAsset (gateway OpenAI-compatible yêu cầu image URL); local
        // path fallback vẫn base64 cho provider chấp nhận inline data.
        foreach ((array) ($product?->mediaIds ?? []) as $mediaId) {
            $asset = MediaAsset::query()->find((int) $mediaId);
            if (! $asset) {
                continue;
            }
            $media = $asset->getFirstMedia('library');
            $url = $media?->getUrl();
            if ($url) {
                $images[] = [
                    'url' => $url,
                    'media_type' => $asset->mime_type ?? 'image/jpeg',
                ];
                continue;
            }
            $path = $media?->getPath();
            if (! $path || ! is_file($path)) {
                continue;
            }
            $contents = @file_get_contents($path);
            if ($contents === false) {
                continue;
            }
            $images[] = [
                'base64' => base64_encode($contents),
                'media_type' => $asset->mime_type ?? 'image/jpeg',
            ];
        }

        return $images;
    }

    /** Input: media_asset_id hoặc path local + mime. Output: `{url|base64, media_type}` hoặc null. */
    private function readAssetImage(int $mediaId, string $path, string $mime): ?array
    {
        if ($mediaId > 0) {
            $asset = MediaAsset::query()->find($mediaId);
            $media = $asset?->getFirstMedia('library');
            $url = $media?->getUrl();
            if ($url) {
                return [
                    'url' => $url,
                    'media_type' => $asset->mime_type ?? 'image/jpeg',
                ];
            }
            $filePath = $media?->getPath();
            if ($filePath && is_file($filePath)) {
                $contents = @file_get_contents($filePath);
                if ($contents !== false) {
                    return [
                        'base64' => base64_encode($contents),
                        'media_type' => $asset->mime_type ?? 'image/jpeg',
                    ];
                }
            }
        }
        if ($path !== '' && is_file(storage_path('app/private/'.$path))) {
            $contents = @file_get_contents(storage_path('app/private/'.$path));
            if ($contents !== false) {
                return [
                    'base64' => base64_encode($contents),
                    'media_type' => $mime !== '' ? $mime : 'image/jpeg',
                ];
            }
        }

        return null;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Ghi lỗi terminal vào import, đồng bộ tracker.
     * =====================================================================
     * Input: import, code, message. Output: void; status=failed.
     * Side effect: UPDATE import + sync AiTaskRun.
     * =====================================================================
     */
    private function fail(AiImport $import, string $code, string $message): void
    {
        if (in_array($import->refresh()->status, AiImport::TERMINAL_STATUSES, true)) {
            return;
        }
        $import->forceFill([
            'status' => 'failed', 'current_step' => 'failed', 'progress' => 100,
            'error_code' => Str::limit($code, 80, ''),
            'error_message' => Str::limit($message, 500),
            'completed_at' => now(),
        ])->save();
        $this->tasks->syncFromImport($import->fresh());
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Resolve snapshot ai_connection cho capability Image.
     * =====================================================================
     * Input: values (model_id/provider/model tuỳ chọn).
     * Output: snapshot public không secret; ValidationException nếu không có.
     * =====================================================================
     */
    private function resolveSnapshot(array $values): array
    {
        $selection = [];
        if (filled($values['model_id'] ?? null)) {
            $selection['model_id'] = (int) $values['model_id'];
        } elseif (filled($values['provider'] ?? null)) {
            $selection['provider'] = (string) $values['provider'];
            if (filled($values['model'] ?? null)) {
                $selection['model'] = (string) $values['model'];
            }
        }

        return $this->models->resolve(AiCapability::Image, $selection);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lọc shot recipe hợp lệ thuộc style, giữ thứ tự input.
     * =====================================================================
     * Input: style + mảng shot id. Output: collection VisualStyleShot
     * is_active của style; không có ném ValidationException với id lạ.
     * =====================================================================
     *
     * @return \Illuminate\Support\Collection<int, VisualStyleShot>
     */
    private function shots(AiImageProfile $style, array $ids): \Illuminate\Support\Collection
    {
        $ids = array_values(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0));
        if ($ids === [] || count($ids) > 4) {
            throw ValidationException::withMessages(['shot_recipe_ids' => 'Chọn từ 1 đến 4 shot recipe.']);
        }
        $shots = VisualStyleShot::query()
            ->where('visual_style_id', $style->id)
            ->where('is_active', true)
            ->whereIn('id', $ids)
            ->orderBy('sort_order')->orderBy('id')
            ->get();
        if ($shots->count() !== count($ids)) {
            throw ValidationException::withMessages(['shot_recipe_ids' => 'Một hoặc nhiều shot recipe không thuộc style hoặc đã tắt.']);
        }

        return $shots;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn hoá aspect_ratio hợp lệ.
     * =====================================================================
     * =====================================================================
     */
    private function aspectRatio(array $values): string
    {
        $ratio = trim((string) ($values['aspect_ratio'] ?? ''));
        if ($ratio === '') {
            return '1:1';
        }
        if (! preg_match('/^\d+:\d+$/', $ratio)) {
            throw ValidationException::withMessages(['aspect_ratio' => 'Tỉ lệ khung hình không hợp lệ (ví dụ 1:1, 4:5).']);
        }

        return $ratio;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn hoá product context từ values.
     * =====================================================================
     * =====================================================================
     */
    private function product(array $values): array
    {
        $raw = (array) ($values['product'] ?? []);
        $mediaIds = array_values(array_filter(array_map(
            static fn (mixed $id): ?int => is_numeric($id) ? (int) $id : null,
            (array) ($raw['media_ids'] ?? []),
        )));

        return [
            'media_ids' => $mediaIds,
            'description' => trim((string) ($raw['description'] ?? '')) ?: null,
            'category' => trim((string) ($raw['category'] ?? '')) ?: null,
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Dựng ProductContext từ input_json của worker.
     * =====================================================================
     * =====================================================================
     */
    private function productFromInput(array $product): ?ProductContext
    {
        $mediaIds = (array) ($product['media_ids'] ?? []);
        $description = trim((string) ($product['description'] ?? '')) ?: null;
        $category = trim((string) ($product['category'] ?? '')) ?: null;
        if ($mediaIds === [] && $description === null && $category === null) {
            return null;
        }

        return ProductContext::fromUpload(
            array_values(array_filter(array_map('intval', $mediaIds), fn (int $id): bool => $id > 0)),
            $description,
            $category,
        );
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn hoá user_instructions thành mảng string.
     * =====================================================================
     * =====================================================================
     */
    private function instructions(array $values): array
    {
        $raw = $values['instructions'] ?? [];
        if (is_string($raw)) {
            $raw = [$raw];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $line): ?string => is_string($line) && trim($line) !== '' ? trim($line) : null,
            (array) $raw,
        )));
    }
}
