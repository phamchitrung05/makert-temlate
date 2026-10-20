<?php

namespace App\Jobs;

use App\Enums\AiCapability;
use App\Enums\MediaAssetField;
use App\Exceptions\AiImportException;
use App\Models\AiImport;
use App\Models\MediaAsset;
use App\Models\SocialPost;
use App\Models\SocialPostVariant;
use App\Models\User;
use App\Services\Ai\Images\AiImageGenerationService;
use App\Services\Ai\Registries\ProviderRegistry;
use App\Services\Ai\Runs\AiTaskRunService;
use App\Services\Marketing\SocialPostAiWorkerService;
use App\Services\Marketing\SocialPostGenerationStateService;
use App\Services\Marketing\SocialPostVersionService;
use App\Services\MediaAssetUsageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Worker tạo binary image và attach vào Social Post.
 * =====================================================================
 * Job dùng image provider/MediaAsset pipeline hiện có, chỉ attach sau khi
 * provider trả bytes hợp lệ và giữ orphan cleanup khi run stale/thất bại.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nhận image AiImport UUID và generation.
 * - uniqueId(): tạo khóa queue ổn định.
 * - handle(): chạy image provider, upload và attach usage.
 * - failed(): ghi lỗi terminal qua worker service.
 * - cleanupOrphan(): dọn asset chưa có usage sau stale/failure.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : AiImport operation social_image với prompt/connection snapshot.
 * - OUTPUT: MediaAsset attached ở social_post.image hoặc failed.
 * - SIDE EFFECT: gọi provider/upload/DB; không tự publish.
 * =====================================================================
 */
final class ProcessSocialPostImageJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 360;

    public array $backoff = [30, 120, 300];

    /**
     * Khởi tạo job binary image.
     *
     * Input: AiImport UUID và generation number.
     * Output: job payload không chứa API key.
     */
    public function __construct(public readonly string $importId, public readonly int $generationNo) {}

    /**
     * Tạo unique key cho binary image component.
     *
     * Input: UUID/generation.
     * Output: key social-image:<import>:<generation>; không side effect.
     */
    public function uniqueId(): string
    {
        return 'social-image:'.$this->importId.':'.$this->generationNo;
    }

    /**
     * Gọi image provider, upload MediaAsset và attach usage transaction-safe.
     *
     * Input: AiImageGenerationService, provider registry và usage service.
     * Output: image import ready hoặc failed; post giữ draft.
     * Side effect: HTTP provider, file storage, media usage và version snapshot.
     */
    public function handle(
        AiImageGenerationService $images,
        ProviderRegistry $providers,
        MediaAssetUsageService $usages,
        SocialPostVersionService $versions,
        SocialPostGenerationStateService $states,
        SocialPostAiWorkerService $worker,
    ): void {
        $import = AiImport::query()->find($this->importId);
        if (! $import || (int) $import->generation_no !== $this->generationNo
            || in_array($import->status, AiImport::TERMINAL_STATUSES, true)) {
            return;
        }
        $input = (array) $import->input_json;
        $post = SocialPost::query()->find((int) ($input['social_post_id'] ?? 0));
        if (! $post || (int) $post->generation_no !== (int) ($input['generation_no'] ?? 0)) {
            $worker->fail($import, 'AI_STALE_GENERATION', 'Lượt tạo đã cũ.', false);

            return;
        }

        $asset = null;
        try {
            $connectionSnapshot = (array) ($input['ai_connection'] ?? []);
            if ($connectionSnapshot === []) {
                throw new AiImportException('Chưa cấu hình image model cho Social Post.', 'AI_IMAGE_MODEL_MISSING');
            }
            $connection = $providers->connectionForRun($connectionSnapshot, AiCapability::Image);
            $import->advance('generating', 10);
            $task = app(AiTaskRunService::class)->syncFromImport($import->fresh());
            if ($task) {
                app(AiTaskRunService::class)->start($task, 10, 'processing');
            }
            $productName = (string) data_get($input, 'context.product.name', 'Social Post image');
            $prompt = trim((string) ($input['image_prompt'] ?? ''));
            if (filled($input['negative_prompt'] ?? null)) {
                $prompt .= "\nAvoid: ".trim((string) $input['negative_prompt']);
            }
            $asset = $images->generate($connection, $prompt, (int) $import->created_by, $productName, $productName);
            $actor = User::query()->findOrFail((int) $import->created_by);

            $attached = DB::transaction(function () use ($post, $import, $asset, $actor, $usages, $input): bool {
                $locked = SocialPost::query()->lockForUpdate()->findOrFail($post->getKey());
                $currentRun = AiImport::query()->lockForUpdate()->findOrFail($import->id);
                if ((int) $locked->generation_no !== (int) $input['generation_no']
                    || (int) $currentRun->generation_no !== $this->generationNo
                    || in_array($currentRun->status, AiImport::TERMINAL_STATUSES, true)) {
                    $this->cleanupOrphan($asset);
                    if ((int) $currentRun->generation_no === $this->generationNo && ! in_array($currentRun->status, AiImport::TERMINAL_STATUSES, true)) {
                        $import->forceFill([
                            'status' => 'cancelled', 'current_step' => 'cancelled', 'progress' => 100,
                            'error_code' => 'AI_STALE_GENERATION', 'error_message' => 'Lượt tạo đã cũ.', 'completed_at' => now(),
                        ])->save();
                    }

                    return false;
                }
                $variant = SocialPostVariant::query()->lockForUpdate()->find(
                    (int) ($input['social_post_variant_id'] ?? 0),
                ) ?? $locked->variants()->where('platform', 'facebook')->lockForUpdate()->first();
                if (! $variant) {
                    $this->cleanupOrphan($asset);

                    return false;
                }
                $slotNo = max(0, (int) ($input['slot_no'] ?? 0));
                $variant->mediaAssetUsagesForField(MediaAssetField::SocialPostFacebookImages)
                    ->where('sort_order', $slotNo)
                    ->delete();
                $usages->attach($actor, $variant, $asset, MediaAssetField::SocialPostFacebookImages, $slotNo);
                $variant->mediaAssetUsagesForField(MediaAssetField::SocialPostFacebookImages)
                    ->where('sort_order', '>=', (int) ($variant->image_count ?? 3))->delete();
                $readyCount = $variant->mediaAssetUsagesForField(MediaAssetField::SocialPostFacebookImages)->count();
                $variant->forceFill([
                    'content_status' => $variant->content_status?->value === 'ready' ? 'ready' : 'partial',
                    'generation_status' => $readyCount >= (int) ($variant->image_count ?? 3) ? 'ready' : 'partial',
                ])->save();
                $import->forceFill([
                    'status' => 'ready', 'current_step' => 'ready', 'progress' => 100,
                    'result_json' => ['image' => ['media_asset_id' => $asset->getKey(), 'slot_no' => $slotNo]],
                    'provider' => data_get($import->input_json, 'ai_connection.provider'),
                    'completed_at' => now(), 'error_code' => null, 'error_message' => null,
                ])->save();
                $locked->forceFill([
                    'review_status' => 'draft', 'reviewed_by' => null, 'reviewed_at' => null,
                    'review_reason' => null, 'updated_by' => $import->created_by,
                ])->save();

                return true;
            });

            if (! $attached) {
                app(AiTaskRunService::class)->syncFromImport($import->fresh());

                return;
            }

            app(AiTaskRunService::class)->syncFromImport($import->fresh());
            $versions->create($post->fresh(), (array) $input['context'], [
                'key' => $input['prompt_key'] ?? 'social_post.facebook_image',
                'version' => $input['prompt_version'] ?? null,
                'schema' => $input['schema_version'] ?? null,
                'component' => 'image',
                'slot_no' => (int) ($input['slot_no'] ?? 0),
                'image_prompt_hash' => hash('sha256', $prompt),
            ], (array) ($input['writing_profile_snapshot'] ?? []), [
                'provider' => data_get($input, 'ai_connection.provider'),
                'model' => data_get($input, 'ai_connection.model'),
                'component' => 'image',
                'generation_no' => $input['generation_no'],
            ], 'image_ready', (int) $import->created_by);
            $states->refresh($post->fresh(), (int) $input['generation_no']);
        } catch (Throwable $exception) {
            if ($asset instanceof MediaAsset) {
                $this->cleanupOrphan($asset);
            }
            if ($exception instanceof AiImportException && $exception->retryable) {
                throw $exception;
            }
            $worker->fail($import, $exception instanceof AiImportException ? $exception->errorCode : 'AI_SOCIAL_IMAGE_FAILED', $exception->getMessage());
        }
    }

    /**
     * Ghi terminal image failure sau khi queue hết retry.
     *
     * Input: exception queue và import hiện tại.
     * Output: import failed; aggregate SocialPost được cập nhật.
     */
    public function failed(Throwable $exception): void
    {
        $import = AiImport::query()->find($this->importId);
        if ($import && (int) $import->generation_no === $this->generationNo) {
            app(SocialPostAiWorkerService::class)->fail($import, 'AI_SOCIAL_IMAGE_FAILED', $exception->getMessage());
        }
    }

    /**
     * Xóa asset provider tạo ra nhưng chưa được attach/giữ bởi run.
     *
     * Input: MediaAsset vừa upload.
     * Output: asset/file bị xóa best-effort nếu không có usage.
     * Side effect: xóa media vật lý/metadata; nuốt lỗi cleanup.
     */
    private function cleanupOrphan(MediaAsset $asset): void
    {
        try {
            if (! $asset->usages()->exists()) {
                $asset->clearMediaCollection('library');
                $asset->delete();
            }
        } catch (Throwable) {
            // Cleanup best-effort; không che lỗi lifecycle chính.
        }
    }
}
