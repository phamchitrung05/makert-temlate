<?php

namespace App\Services\Ai\Targets;

use App\Models\AiImport;
use App\Models\AiTaskRun;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Ai\Contracts\AiSessionStarterContract;
use App\Services\Marketing\SocialPostGenerationService;
use App\Services\Marketing\SocialPostLifecycleService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** AI Content entry point sharing the Social Post lifecycle, context and component queues. */
final class SocialPostAiAdapter implements AiSessionStarterContract
{
    public function __construct(
        private readonly SocialPostLifecycleService $lifecycle,
        private readonly SocialPostGenerationService $generation,
    ) {}

    /** Atomically create a draft and its jobs; repeated submissions return the same generation. */
    public function start(User $actor, array $data): array
    {
        abort_unless($actor->can('social_posts.create'), 403);
        $connection = (string) config('queue.default');
        if (in_array(config('queue.connections.'.$connection.'.driver', $connection), ['sync', 'null'], true)) {
            throw ValidationException::withMessages(['queue' => 'Tạo Social Post cần queue bất đồng bộ.']);
        }
        $outputs = $data['requested_outputs'];
        $component = in_array('social_content', $outputs, true)
            ? (in_array('social_gallery', $outputs, true) ? 'all' : 'caption') : 'image';
        $hash = hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        try {
            $result = Cache::lock('ai-run-create-'.$actor->id, 10)->block(1, function () use ($actor, $data, $component, $hash): array {
                $existing = AiImport::query()->where('created_by', $actor->id)
                    ->where('input_json->manual_request_hash', $hash)
                    ->where('created_at', '>=', now()->subMinutes((int) config('ai.import.idempotency_window_minutes', 30)))
                    ->whereNotIn('status', ['failed', 'cancelled', 'expired'])->latest()->first();
                if ($existing && SocialPost::query()->whereKey(data_get($existing->input_json, 'social_post_id'))->exists()) {
                    return $this->response((int) data_get($existing->input_json, 'social_post_id'), (int) $existing->generation_no);
                }
                $cost = $component === 'all' ? 2 : 1;
                abort_if(AiImport::query()->where('created_by', $actor->id)->where('created_at', '>=', now()->subHour())->count()
                    + $cost > (int) config('ai.import.quota_per_hour', 20), 429, 'Bạn đã đạt giới hạn tác vụ AI trong giờ này.');

                return DB::transaction(function () use ($actor, $data, $component, $hash): array {
                    $post = $this->lifecycle->create([], (int) $actor->id);
                    $queued = $this->generation->queue($post, (int) $actor->id, [
                        'component' => $component,
                        'source_text' => $data['text'],
                        'language' => $data['language'] ?? 'vi',
                        'instructions' => $data['instructions'] ?? '',
                        'writing_brief' => $data['writing_brief'] ?? [],
                        'length' => $data['length'] ?? config('ai.content.default_length'),
                        'writing_profile_id' => $data['writing_profile_id'] ?? null,
                        'image_profile_id' => $data['image_profile_id'] ?? null,
                        'image_count' => $data['image_count'] ?? config('ai.content.social_images.default', 3),
                        'text_provider' => $data['provider'] ?? null,
                        'text_model' => $data['model'] ?? null,
                        'text_model_id' => $data['model_id'] ?? null,
                        'image_provider' => $data['image_provider'] ?? null,
                        'image_model' => $data['image_model'] ?? null,
                        'image_model_id' => $data['image_model_id'] ?? null,
                        'manual_request_hash' => $hash,
                    ]);

                    return $this->response((int) $post->id, $queued['generation_no']);
                });
            });

            return $result + ['can_open' => $actor->can('social_posts.view') || $actor->can('marketing.view')];
        } catch (LockTimeoutException) {
            throw new HttpException(409, 'Một tác vụ AI khác đang được xếp hàng. Hãy thử lại sau.');
        }
    }

    private function response(int $postId, int $generation): array
    {
        $runs = AiImport::query()->where('input_json->social_post_id', $postId)
            ->where('generation_no', $generation)->whereIn('operation', ['social_caption', 'social_image_prompt'])->get();
        $tasks = AiTaskRun::query()->where('taskable_type', AiImport::class)->whereIn('taskable_id', $runs->modelKeys())->get();
        $jobs = $runs->map(fn (AiImport $run): array => [
            'job_id' => (string) $run->id,
            'task_run_id' => $tasks->firstWhere('taskable_id', (string) $run->id)?->id,
            'task_type' => $run->operation, 'status' => $run->status,
        ])->values()->all();

        return [
            'target_type' => 'social_post', 'social_post_id' => $postId,
            'generation_no' => $generation, 'status' => 'queued', 'jobs' => $jobs,
            'job_id' => $jobs[0]['job_id'], 'task_run_id' => $jobs[0]['task_run_id'],
        ];
    }
}
