<?php

namespace App\Services\Marketing;

use App\Enums\AiCapability;
use App\Enums\SocialPlatform;
use App\Enums\SocialPostGenerationStatus;
use App\Jobs\ProcessSocialPostCaptionJob;
use App\Jobs\ProcessSocialPostImageJob;
use App\Jobs\ProcessSocialPostImagePromptJob;
use App\Models\AiImport;
use App\Models\SocialPost;
use App\Models\SocialPostVariant;
use App\Services\Ai\Images\ImageProfileService;
use App\Services\Ai\Providers\Catalog\ModelResolver;
use App\Services\Ai\Registries\PromptRegistry;
use App\Services\Ai\Registries\SchemaRegistry;
use App\Services\Ai\Runs\AiTaskRunService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Xếp hàng hai nhánh AI caption và prompt ảnh.
 * =====================================================================
 * Service tạo bounded snapshot trước queue, tăng generation atomically và
 * đăng ký AiTaskRun để popup hiện có theo dõi được từng component.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nhận resolver/context/registry/task services.
 * - queue(): tạo generation mới và dispatch caption/image-prompt jobs.
 * - createImport(): tạo một AiImport component với snapshot bất biến.
 * - resolveConnection(): resolve model hoặc deterministic fallback.
 * - resolveImageConnection(): resolve image model tùy chọn.
 * - dispatch(): dispatch jobs sau commit.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : SocialPost, actor, context/profile và model selections.
 * - OUTPUT: generation_no cùng hai AiImport queued.
 * - SIDE EFFECT: ghi DB, đăng ký tracker và dispatch queue; không gọi provider.
 * =====================================================================
 */
final class SocialPostGenerationService
{
    /** Retry/regenerate one terminal slot in the current generation, retaining the other images. */
    public function regenerateSlot(SocialPost $post, int $slot, int $actorId, array $options = []): array
    {
        return DB::transaction(function () use ($post, $slot, $actorId, $options): array {
            $locked = SocialPost::query()->lockForUpdate()->findOrFail($post->id);
            $run = AiImport::query()->where('input_social_post_id', $locked->id)
                ->where('input_generation_no', $locked->generation_no)->where('operation', 'social_image')
                ->where('input_json->slot_no', $slot)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($run->status, AiImport::TERMINAL_STATUSES, true) || $run->expires_at?->isPast(), 409, 'Ảnh này đang được tạo.');
            $input = $run->input_json;
            if (filled($options['image_model_id'] ?? null) || filled($options['image_provider'] ?? null) || filled($options['image_model'] ?? null)) {
                $input['ai_connection'] = $this->resolveImageConnection($options);
            }
            $run->forceFill([
                'status' => 'queued', 'current_step' => 'queued', 'progress' => 0,
                'input_json' => $input, 'result_json' => null, 'error_code' => null, 'error_message' => null,
                'completed_at' => null, 'started_at' => null,
                'generation_no' => (int) $run->generation_no + 1,
                'expires_at' => now()->addDays((int) config('ai.import.retention_days', 2)),
            ])->save();
            $locked->forceFill(['generation_status' => 'generating', 'review_status' => 'draft',
                'reviewed_by' => null, 'reviewed_at' => null, 'review_reason' => null, 'updated_by' => $actorId])->save();
            $locked->variants()->where('platform', 'facebook')->update(['generation_status' => 'generating', 'review_status' => 'draft',
                'reviewed_by' => null, 'reviewed_at' => null, 'review_reason' => null]);
            $this->tasks->registerImport($run->fresh());
            DB::afterCommit(fn () => ProcessSocialPostImageJob::dispatch((string) $run->id, (int) $run->generation_no));

            return ['job_id' => (string) $run->id, 'generation_no' => (int) $run->generation_no, 'slot_no' => $slot];
        });
    }

    /**
     * Nhận các registry/service phục vụ snapshot và queue.
     *
     * Input: resolver, context builder, prompt/schema registry và task tracker.
     * Output: service điều phối; không query/gọi provider lúc khởi tạo.
     */
    public function __construct(
        private readonly ModelResolver $models,
        private readonly SocialPostContextBuilder $contexts,
        private readonly PromptRegistry $prompts,
        private readonly SchemaRegistry $schemas,
        private readonly AiTaskRunService $tasks,
    ) {}

    /**
     * Tạo một generation mới cho caption và image prompt.
     *
     * Input: post, actor ID, profile/model selections và force flag regenerate.
     * Output: array generation_no/import IDs; post chuyển generating.
     * Side effect: transaction lock post, create imports/task trackers, dispatch sau commit.
     * Exception: ValidationException khi payload/context/model selection sai.
     *
     * @param  array<string, mixed>  $options  component=all|caption|image
     * @return array<string, mixed>
     */
    public function queue(SocialPost $post, int $actorId, array $options = []): array
    {
        // Regeneration keeps the selected models unless the caller explicitly replaces them.
        foreach (['text' => ['social_caption', 'social_image_prompt'], 'image' => ['social_image_prompt']] as $kind => $operations) {
            if (filled($options[$kind.'_model_id'] ?? null) || filled($options[$kind.'_provider'] ?? null) || filled($options[$kind.'_model'] ?? null)) {
                continue;
            }
            $previous = AiImport::query()->where('input_social_post_id', $post->id)->whereIn('operation', $operations)
                ->latest('created_at')->latest('id')->first();
            $snapshot = (array) data_get($previous?->input_json, $kind === 'text' ? 'ai_connection' : 'image_connection', []);
            foreach (['provider', 'model', 'model_id'] as $key) {
                if (filled($snapshot[$key] ?? null)) {
                    $options[$kind.'_'.$key] = $snapshot[$key];
                }
            }
        }
        $component = (string) ($options['component'] ?? 'all');
        if (! in_array($component, ['all', 'caption', 'image'], true)) {
            throw ValidationException::withMessages(['component' => 'Component AI Social Post không hợp lệ.']);
        }
        $context = $this->contexts->build($post, isset($options['writing_profile_id']) ? (int) $options['writing_profile_id'] : null, $options);
        $context['image_count'] = (int) ($options['image_count'] ?? $post->variants()->where('platform', 'facebook')->value('image_count') ?? config('ai.content.social_images.default', 3));
        if (array_key_exists('image_profile_id', $options)) {
            $context['image_profile'] = app(ImageProfileService::class)->snapshot($options['image_profile_id'], $actorId);
        }
        if ($context['image_count'] < 1 || $context['image_count'] > 10) {
            throw ValidationException::withMessages(['image_count' => 'Số lượng ảnh phải từ 1 đến 10.']);
        }
        $context['context_hash'] = hash('sha256', json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $textConnection = $this->resolveConnection(AiCapability::Text, [
            'provider' => $options['text_provider'] ?? null,
            'model' => $options['text_model'] ?? null,
            'model_id' => $options['text_model_id'] ?? null,
        ]);
        $imageConnection = $component === 'caption' ? null : $this->resolveImageConnection($options);
        $captionOperation = $component === 'caption' ? 'regenerate_content' : 'generate';
        $imageOperation = $component === 'image' ? 'regenerate_images' : 'generate';
        $captionPrompt = $this->prompts->get('social_post.facebook_content', 'social_post', $captionOperation);
        $imagePrompt = $this->prompts->get('social_post.facebook_image', 'social_post', $imageOperation);
        $captionSchema = $this->schemas->get($captionPrompt['schema']);
        $imageSchema = $this->schemas->get($imagePrompt['schema']);

        $imports = DB::transaction(function () use (
            $post,
            $actorId,
            $context,
            $textConnection,
            $imageConnection,
            $captionPrompt,
            $imagePrompt,
            $captionSchema,
            $imageSchema,
            $component,
            $options,
        ): array {
            $locked = SocialPost::query()->lockForUpdate()->findOrFail($post->getKey());
            $variant = SocialPostVariant::query()->firstOrCreate(
                ['social_post_id' => $locked->getKey(), 'platform' => SocialPlatform::Facebook],
                [
                    'format' => 'feed',
                    'content_status' => SocialPostGenerationStatus::Pending,
                    'generation_status' => SocialPostGenerationStatus::Pending,
                    'review_status' => 'draft',
                ],
            );
            $locked->targets()->whereNull('social_post_variant_id')->update([
                'social_post_variant_id' => $variant->getKey(),
            ]);
            $generationNo = (int) $locked->generation_no + 1;
            $locked->forceFill([
                'generation_no' => $generationNo,
                'generation_status' => SocialPostGenerationStatus::Generating,
                'review_status' => 'draft',
                'reviewed_by' => null,
                'reviewed_at' => null,
                'review_reason' => null,
                'updated_by' => $actorId,
            ])->save();
            $variant->forceFill([
                'image_count' => $component === 'caption' ? $variant->image_count : $context['image_count'],
                'generation_status' => SocialPostGenerationStatus::Generating,
                'content_status' => in_array($component, ['all', 'caption'], true)
                    ? SocialPostGenerationStatus::Generating
                    : ($variant->content_status ?? SocialPostGenerationStatus::Pending),
                'review_status' => 'draft',
                'reviewed_by' => null,
                'reviewed_at' => null,
                'review_reason' => null,
            ])->save();

            $common = [
                'social_post_id' => (int) $locked->getKey(),
                'social_post_variant_id' => (int) $variant->getKey(),
                'generation_no' => $generationNo,
                'context' => $context,
                'writing_profile_snapshot' => $context['writing_profile'] ?? null,
                'requested_at' => now()->toIso8601String(),
                'manual_request_hash' => $options['manual_request_hash'] ?? null,
            ];
            $caption = null;
            $image = null;
            if (in_array($component, ['all', 'caption'], true)) {
                $caption = $this->createImport(
                    $actorId,
                    'social_caption',
                    $captionPrompt,
                    $captionSchema,
                    $textConnection,
                    [...$common, 'component' => 'caption'],
                    $component === 'caption' ? 'regenerate_content' : 'generate',
                    $generationNo,
                );
                $this->tasks->registerImport($caption);
            }
            if (in_array($component, ['all', 'image'], true)) {
                $image = $this->createImport(
                    $actorId,
                    'social_image_prompt',
                    $imagePrompt,
                    $imageSchema,
                    $textConnection,
                    [...$common, 'component' => 'image_prompt', 'image_connection' => $imageConnection],
                    $component === 'image' ? 'regenerate_images' : 'generate',
                    $generationNo,
                );
                $this->tasks->registerImport($image);
            }
            DB::afterCommit(fn () => $this->dispatch($caption, $image));

            return [$generationNo, $caption, $image];
        });

        return [
            'generation_no' => $imports[0],
            'caption_job_id' => $imports[1]?->getKey() ? (string) $imports[1]->getKey() : null,
            'image_prompt_job_id' => $imports[2]?->getKey() ? (string) $imports[2]->getKey() : null,
            'image_connection_available' => $imageConnection !== null,
        ];
    }

    /**
     * Tạo AiImport component có context/prompt/schema snapshot bounded.
     *
     * Input: actor, operation, prompt/schema metadata, connection và component context.
     * Output: AiImport queued chưa dispatch; không chứa API key.
     * Side effect: insert database trong transaction caller.
     *
     * @param  array<string, mixed>  $prompt
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>|null  $connection
     * @param  array<string, mixed>  $input
     */
    private function createImport(
        int $actorId,
        string $operation,
        array $prompt,
        array $schema,
        ?array $connection,
        array $input,
        string $requestedOperation,
        int $generationNo,
    ): AiImport {
        $input['target_type'] = 'social_post';
        $input['prompt_key'] = $prompt['key'];
        $input['prompt_version'] = $prompt['version'];
        $input['prompt_instructions'] = $prompt['instructions'];
        $input['schema_version'] = $prompt['schema'];
        $input['schema'] = $schema['json_schema'] ?? [];
        $input['ai_connection'] = $connection;
        $input['operation_name'] = $requestedOperation;
        $hash = hash('sha256', $operation.'|'.json_encode([
            'post_id' => $input['social_post_id'], 'generation_no' => $generationNo,
            'context_hash' => data_get($input, 'context.context_hash'), 'prompt' => $prompt['key'],
            'prompt_version' => $prompt['version'], 'connection' => $connection,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return AiImport::query()->create([
            'created_by' => $actorId,
            'source_url' => '',
            'source_hash' => $hash,
            'status' => 'queued',
            'current_step' => 'queued',
            'progress' => 0,
            'input_json' => $input,
            'source_meta_json' => [],
            'provider' => $connection['provider'] ?? 'deterministic',
            'prompt_version' => (string) $prompt['version'],
            'operation' => $operation,
            'generation_no' => $generationNo,
            'expires_at' => now()->addDays((int) config('ai.import.retention_days', 2)),
        ]);
    }

    /**
     * Resolve connection theo capability; text được deterministic fallback nếu chưa cấu hình.
     *
     * Input: capability và model selection đã whitelist từ request.
     * Output: snapshot public không có API key; không gọi provider.
     * Side effect: đọc settings/catalog; ném ValidationException nếu selection rõ ràng sai.
     *
     * @param  array<string, mixed>  $selection
     */
    private function resolveConnection(AiCapability $capability, array $selection): array
    {
        $hasSelection = collect($selection)->contains(fn ($value): bool => filled($value));
        try {
            return $this->models->resolve($capability, array_filter($selection, fn ($value): bool => filled($value)));
        } catch (ValidationException $exception) {
            if ($capability === AiCapability::Text && ! $hasSelection) {
                return [
                    'provider' => 'deterministic', 'provider_label' => 'Deterministic',
                    'driver' => 'deterministic', 'model' => 'deterministic', 'model_id' => null,
                    'capabilities' => [AiCapability::Text->value, AiCapability::Structured->value],
                    'timeout' => 30, 'temperature' => 0.2,
                ];
            }
            throw $exception;
        }
    }

    /**
     * Resolve image connection nếu có model; thiếu model để worker ghi partial failure.
     *
     * Input: options provider/model/image_model_id.
     * Output: image connection snapshot hoặc null; không gọi provider.
     * Side effect: đọc settings/catalog; selection rõ ràng sai vẫn trả validation.
     *
     * @param  array<string, mixed>  $options
     */
    private function resolveImageConnection(array $options): ?array
    {
        $selection = [
            'provider' => $options['image_provider'] ?? null,
            'model' => $options['image_model'] ?? null,
            'model_id' => $options['image_model_id'] ?? null,
        ];
        if (! collect($selection)->contains(fn ($value): bool => filled($value))) {
            try {
                return $this->models->resolve(AiCapability::Image);
            } catch (ValidationException) {
                return null;
            }
        }

        return $this->resolveConnection(AiCapability::Image, $selection);
    }

    /**
     * Dispatch hai branch sau khi import đã commit.
     *
     * Input: caption/image prompt AiImport.
     * Output: không trả giá trị; queue nhận hai job unique theo import.
     * Side effect: ghi queue; không gọi provider trong request.
     */
    private function dispatch(?AiImport $caption, ?AiImport $image): void
    {
        if ($caption) {
            ProcessSocialPostCaptionJob::dispatch((string) $caption->getKey(), (int) $caption->generation_no);
        }
        if ($image) {
            ProcessSocialPostImagePromptJob::dispatch((string) $image->getKey(), (int) $image->generation_no);
        }
    }
}
