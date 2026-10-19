<?php

namespace App\Services\Marketing;

use App\Exceptions\AiImportException;
use App\Jobs\ProcessSocialPostImageJob;
use App\Models\AiImport;
use App\Models\SocialPost;
use App\Models\SocialPostVariant;
use App\Services\Ai\Data\AiTaskRequest;
use App\Services\Ai\Registries\PromptRegistry;
use App\Services\Ai\Registries\SchemaRegistry;
use App\Services\Ai\Runs\AiTaskRunService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Chạy structured task và ghi kết quả AI Social Post.
 * =====================================================================
 * Worker service giữ provider ngoài transaction, kiểm stale generation trước
 * mỗi mutation và tạo image binary task sau khi prompt ảnh hợp lệ.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nhận registry/provider/task/context/version/state services.
 * - processCaption(): chạy task caption và cập nhật canonical caption.
 * - processImagePrompt(): chạy task prompt ảnh và xếp hàng binary image.
 * - execute(): gọi provider structured hoặc deterministic fallback.
 * - complete(): ghi output ready và version snapshot.
 * - fail(): ghi lỗi bounded và cập nhật aggregate status.
 * - deterministicCaption()/deterministicImagePrompt(): output dự phòng khi chưa có provider.
 * - isCurrent(): kiểm tra post/generation còn hiện hành.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : AiImport component, provider snapshot và bounded context.
 * - OUTPUT: output ready/failed, SocialPost caption hoặc image run queued.
 * - SIDE EFFECT: gọi provider, ghi DB, tạo tracker/queue; không tự publish.
 * =====================================================================
 */
final class SocialPostAiWorkerService
{
    /**
     * Nhận các dependency dùng chung cho worker caption/prompt ảnh.
     *
     * Input: ProviderRegistry, prompt/schema/task/context/version/state services.
     * Output: worker service; không gọi provider lúc khởi tạo.
     */
    public function __construct(
        private readonly \App\Services\Ai\Registries\ProviderRegistry $providers,
        private readonly PromptRegistry $prompts,
        private readonly SchemaRegistry $schemas,
        private readonly AiTaskRunService $tasks,
        private readonly SocialPostContextBuilder $contexts,
        private readonly SocialPostVersionService $versions,
        private readonly SocialPostGenerationStateService $states,
    ) {}

    /**
     * Chạy caption task và cập nhật canonical caption của Social Post.
     *
     * Input: AiImport operation social_caption.
     * Output: void; import ready/failed và post caption nếu generation còn mới.
     * Side effect: gọi text provider ngoài transaction, ghi snapshot/version.
     */
    public function processCaption(AiImport $import): void
    {
        $input = (array) $import->input_json;
        $post = SocialPost::query()->find((int) ($input['social_post_id'] ?? 0));
        if (! $post || ! $this->isCurrent($post, (int) ($input['generation_no'] ?? 0))) {
            $this->fail($import, 'AI_STALE_GENERATION', 'Lượt tạo đã cũ và không được ghi đè kết quả mới.', false);

            return;
        }

        try {
            $output = $this->execute($import, 'caption');
            $content = FacebookContent::normalize($output);
            $caption = FacebookContent::caption($content);
            $updated = DB::transaction(function () use ($import, $post, $input, $content, $caption): bool {
                $locked = SocialPost::query()->lockForUpdate()->findOrFail($post->getKey());
                if (! $this->isCurrent($locked, (int) ($input['generation_no'] ?? 0))) {
                    return false;
                }
                $variant = SocialPostVariant::query()->lockForUpdate()->firstOrCreate(
                    ['social_post_id' => $locked->getKey(), 'platform' => 'facebook'],
                    ['format' => 'feed', 'review_status' => 'draft'],
                );
                $variant->forceFill([
                    'content_json' => $content,
                    'content_status' => 'ready',
                    'generation_status' => 'ready',
                    'review_status' => 'draft',
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'review_reason' => null,
                ])->save();
                $locked->forceFill([
                    'caption' => $caption,
                    'review_status' => 'draft',
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'review_reason' => null,
                    'updated_by' => $import->created_by,
                ])->save();
                $this->complete($import, $content, [
                    'provider' => $import->provider,
                    'model' => data_get($input, 'ai_connection.model', 'deterministic'),
                    'prompt_key' => $input['prompt_key'] ?? null,
                    'prompt_version' => $input['prompt_version'] ?? null,
                    'schema_version' => $input['schema_version'] ?? null,
                    'component' => 'caption',
                    'generation_no' => $input['generation_no'] ?? null,
                ]);

                return true;
            });
            if (! $updated) {
                $this->fail($import, 'AI_STALE_GENERATION', 'Lượt tạo đã cũ.', false);

                return;
            }
            $this->tasks->syncFromImport($import->fresh());
            $this->versions->create($post->fresh(), (array) $input['context'], [
                'key' => $input['prompt_key'] ?? 'social_post.caption',
                'version' => $input['prompt_version'] ?? null,
                'schema' => $input['schema_version'] ?? null,
                'component' => 'caption',
            ], (array) ($input['writing_profile_snapshot'] ?? []), [
                'provider' => $import->provider,
                'model' => data_get($input, 'ai_connection.model', 'deterministic'),
                'component' => 'caption',
                'generation_no' => $input['generation_no'] ?? null,
            ], 'caption_ready', (int) $import->created_by);
            $this->states->refresh($post->fresh(), (int) $input['generation_no']);
        } catch (\Throwable $exception) {
            if ($exception instanceof AiImportException && $exception->retryable) {
                throw $exception;
            }
            $this->fail($import, $exception instanceof AiImportException ? $exception->errorCode : 'AI_SOCIAL_CAPTION_FAILED', $exception->getMessage());
        }
    }

    /**
     * Chạy image prompt task rồi tạo binary image AiImport riêng.
     *
     * Input: AiImport operation social_image_prompt.
     * Output: prompt import ready và image import queued/failed.
     * Side effect: gọi text provider, insert image import, dispatch image worker.
     */
    public function processImagePrompt(AiImport $import): void
    {
        $input = (array) $import->input_json;
        $post = SocialPost::query()->find((int) ($input['social_post_id'] ?? 0));
        if (! $post || ! $this->isCurrent($post, (int) ($input['generation_no'] ?? 0))) {
            $this->fail($import, 'AI_STALE_GENERATION', 'Lượt tạo đã cũ và không được ghi đè kết quả mới.', false);

            return;
        }

        try {
            $output = $this->execute($import, 'image_prompt');
            $imageImports = DB::transaction(function () use ($import, $input, $output): array {
                $this->complete($import, $output, [
                    'provider' => $import->provider,
                    'model' => data_get($input, 'ai_connection.model', 'deterministic'),
                    'prompt_key' => $input['prompt_key'] ?? null,
                    'prompt_version' => $input['prompt_version'] ?? null,
                    'schema_version' => $input['schema_version'] ?? null,
                    'component' => 'image_prompt',
                    'generation_no' => $input['generation_no'] ?? null,
                ]);

                $hash = hash('sha256', 'social_image|'.json_encode([
                    'post_id' => $input['social_post_id'], 'generation_no' => $input['generation_no'],
                    'prompt' => $output['image_prompt'], 'connection' => $input['image_connection'] ?? null,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

                $slotCount = max(3, min(4, (int) ($output['slot_count'] ?? 3)));
                $imageImports = [];
                for ($slotNo = 0; $slotNo < $slotCount; $slotNo++) {
                    $imageImport = AiImport::query()->create([
                        'created_by' => $import->created_by,
                        'source_url' => '',
                        'source_hash' => hash('sha256', $hash.'|slot:'.$slotNo),
                        'status' => 'queued',
                        'current_step' => 'queued',
                        'progress' => 0,
                        'input_json' => [
                            'target_type' => 'social_post',
                            'component' => 'image',
                            'social_post_id' => $input['social_post_id'],
                            'social_post_variant_id' => $input['social_post_variant_id'] ?? null,
                            'generation_no' => $input['generation_no'],
                            'slot_no' => $slotNo,
                            'context' => $input['context'],
                            'writing_profile_snapshot' => $input['writing_profile_snapshot'] ?? null,
                            'prompt_key' => $input['prompt_key'],
                            'prompt_version' => $input['prompt_version'],
                            'schema_version' => $input['schema_version'],
                            'image_prompt' => $output['image_prompt'].'\nVariation slot '.($slotNo + 1).'.',
                            'negative_prompt' => $output['negative_prompt'] ?? null,
                            'aspect_ratio' => $output['aspect_ratio'] ?? '4:5',
                            'ai_connection' => $input['image_connection'] ?? null,
                        ],
                        'source_meta_json' => [],
                        'provider' => data_get($input, 'image_connection.provider', 'none'),
                        'prompt_version' => (string) ($input['prompt_version'] ?? '1.0'),
                        'operation' => 'social_image',
                        'generation_no' => (int) $input['generation_no'],
                        'parent_id' => $import->getKey(),
                        'expires_at' => now()->addDays((int) config('ai.import.retention_days', 2)),
                    ]);
                    $this->tasks->registerImport($imageImport);
                    $imageImports[] = $imageImport;
                }

                return $imageImports;
            });
            $this->tasks->syncFromImport($import->fresh());

            $this->versions->create($post->fresh(), (array) $input['context'], [
                'key' => $input['prompt_key'] ?? 'social_post.facebook_image',
                'version' => $input['prompt_version'] ?? null,
                'schema' => $input['schema_version'] ?? null,
                'component' => 'image_prompt',
            ], (array) ($input['writing_profile_snapshot'] ?? []), [
                'provider' => $import->provider,
                'model' => data_get($input, 'ai_connection.model', 'deterministic'),
                'component' => 'image_prompt',
                'generation_no' => $input['generation_no'] ?? null,
            ], 'image_prompt_ready', (int) $import->created_by);
            foreach ($imageImports as $imageImport) {
                ProcessSocialPostImageJob::dispatch((string) $imageImport->getKey(), (int) $imageImport->generation_no);
            }
            $this->states->refresh($post->fresh(), (int) $input['generation_no']);
        } catch (\Throwable $exception) {
            if ($exception instanceof AiImportException && $exception->retryable) {
                throw $exception;
            }
            $this->fail($import, $exception instanceof AiImportException ? $exception->errorCode : 'AI_SOCIAL_IMAGE_PROMPT_FAILED', $exception->getMessage());
        }
    }

    /**
     * Thực thi structured output với provider snapshot hoặc fallback deterministic.
     *
     * Input: import và component caption/image_prompt.
     * Output: JSON object đã provider/schema validator kiểm tra.
     * Side effect: gọi provider HTTP khi connection không deterministic.
     *
     * @return array<string, mixed>
     */
    private function execute(AiImport $import, string $component): array
    {
        $input = (array) $import->input_json;
        $context = (array) ($input['context'] ?? []);
        $schema = (array) ($input['schema'] ?? []);
        if (($input['ai_connection']['driver'] ?? null) === 'deterministic' || $import->provider === 'deterministic') {
            return $component === 'caption'
                ? $this->deterministicCaption($context)
                : $this->deterministicImagePrompt($context);
        }

        $prompt = $this->prompts->get((string) $input['prompt_key'], 'social_post', $input['operation_name'] ?? 'generate');
        $connection = $this->providers->resolveForRun((array) ($input['ai_connection'] ?? []));
        $request = new AiTaskRequest(
            task: (string) $input['prompt_key'],
            systemInstructions: (string) $prompt['instructions'],
            input: $context,
            schema: $schema,
            options: [
                'model' => data_get($input, 'ai_connection.model'),
                'prompt_key' => $input['prompt_key'],
                'prompt_version' => $input['prompt_version'],
                'schema_version' => $input['schema_version'],
            ],
        );

        return $connection->execute($request)->output;
    }

    /**
     * Ghi output ready/error và đồng bộ tracker.
     *
     * Input: AiImport, output đã validate và provider metadata.
     * Output: import fresh ready; không publish.
     * Side effect: update result/status và AiTaskRun projection.
     *
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $provider
     */
    private function complete(AiImport $import, array $output, array $provider): void
    {
        $import->forceFill([
            'status' => 'ready', 'current_step' => 'ready', 'progress' => 100,
            'result_json' => $output,
            'source_meta_json' => ['provider' => array_intersect_key($provider, array_flip(['provider', 'model', 'component', 'generation_no']))],
            'provider' => $provider['provider'] ?? $import->provider,
            'completed_at' => now(), 'error_code' => null, 'error_message' => null,
        ])->save();
    }

    /**
     * Ghi lỗi bounded và cập nhật generation partial/failed.
     *
     * Input: import, error code/message và retry flag.
     * Output: import failed/cancelled; aggregate status được refresh nếu post còn đó.
     * Side effect: ghi database/task tracker; không lưu raw exception stack.
     */
    public function fail(AiImport $import, string $code, string $message, bool $failed = true): void
    {
        $input = (array) $import->input_json;
        $import->forceFill([
            'status' => $failed ? 'failed' : 'cancelled',
            'current_step' => $failed ? 'failed' : 'cancelled',
            'progress' => 100,
            'error_code' => Str::limit($code, 80, ''),
            'error_message' => Str::limit($message, 500),
            'completed_at' => now(),
        ])->save();
        $this->tasks->syncFromImport($import->fresh());
        if ($post = SocialPost::query()->find((int) ($input['social_post_id'] ?? 0))) {
            $this->states->refresh($post, (int) ($input['generation_no'] ?? 0));
        }
    }

    /**
     * Fallback deterministic để môi trường chưa cấu hình text provider vẫn có draft.
     *
     * Input: context campaign/profile.
     * Output: caption JSON hợp lệ; không gọi network.
     */
    private function deterministicCaption(array $context): array
    {
        $message = trim((string) data_get($context, 'campaign.message', ''));
        $caption = $message !== '' ? $message : 'Chia sẻ nội dung hữu ích và phù hợp với cộng đồng của bạn.';

        return [
            'title' => null,
            'body' => Str::limit(trim($caption), 5000, ''),
            'bullet_points' => [],
            'cta' => 'Theo dõi để xem thêm nội dung hữu ích.',
            'contact' => ['address' => null, 'phone' => null, 'website' => null],
            'hashtags' => ['#sanpham', '#marketing'],
            'link_url' => null,
        ];
    }

    /**
     * Fallback deterministic tạo prompt ảnh để test/schema không phụ thuộc provider.
     *
     * Input: context campaign/profile/visual constraints.
     * Output: image prompt JSON; binary image vẫn cần image provider.
     */
    private function deterministicImagePrompt(array $context): array
    {
        $message = (string) data_get($context, 'campaign.message', '');

        return [
            'image_prompt' => 'Ảnh minh họa Facebook có bố cục rõ ràng, ánh sáng tự nhiên mềm, góc chụp dọc 4:5, nền sạch và phù hợp với chủ đề nội dung. '.$message,
            'negative_prompt' => 'watermark, fake logo, unreadable text, extra subjects, distorted hands',
            'aspect_ratio' => '4:5',
            'slot_count' => 3,
        ];
    }

    /**
     * Kiểm post còn đúng generation trước khi worker ghi kết quả.
     *
     * Input: SocialPost và generation number.
     * Output: boolean; chỉ đọc database/model.
     */
    private function isCurrent(SocialPost $post, int $generationNo): bool
    {
        return (int) $post->generation_no === $generationNo
            && ! $post->trashed();
    }
}
