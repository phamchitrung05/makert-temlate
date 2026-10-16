<?php

namespace App\Services\Marketing;

use App\Enums\AiCapability;
use App\Enums\SocialPostGenerationStatus;
use App\Jobs\ProcessSocialPostCaptionJob;
use App\Jobs\ProcessSocialPostImagePromptJob;
use App\Models\AiImport;
use App\Models\SocialPost;
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
        $component = (string) ($options['component'] ?? 'all');
        if (! in_array($component, ['all', 'caption', 'image'], true)) {
            throw ValidationException::withMessages(['component' => 'Component AI Social Post không hợp lệ.']);
        }
        $context = $this->contexts->build($post, isset($options['writing_profile_id']) ? (int) $options['writing_profile_id'] : null);
        $textConnection = $this->resolveConnection(AiCapability::Text, [
            'provider' => $options['text_provider'] ?? null,
            'model' => $options['text_model'] ?? null,
            'model_id' => $options['text_model_id'] ?? null,
        ]);
        $imageConnection = $component === 'caption' ? null : $this->resolveImageConnection($options);
        $captionPrompt = $this->prompts->get('social_post.caption', 'social_post', 'generate');
        $imagePrompt = $this->prompts->get('social_post.product_image', 'social_post', 'generate');
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
        ): array {
            $locked = SocialPost::query()->lockForUpdate()->findOrFail($post->getKey());
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

            $common = [
                'social_post_id' => (int) $locked->getKey(),
                'generation_no' => $generationNo,
                'context' => $context,
                'writing_profile_snapshot' => $context['writing_profile'] ?? null,
                'requested_at' => now()->toIso8601String(),
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
                    $component === 'caption' ? 'regenerate_caption' : 'generate',
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
                    $component === 'image' ? 'regenerate_image' : 'generate',
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
