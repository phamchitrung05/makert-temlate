<?php

namespace App\Services\Ai\Images;

use App\Enums\AiCapability;
use App\Jobs\AnalyzeAiImageProfileJob;
use App\Models\AiImageProfile;
use App\Services\Ai\Data\AiTaskRequest;
use App\Services\Ai\Providers\Catalog\ModelResolver;
use App\Services\Ai\Registries\ProviderRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Uploads are private; only their bytes are sent to the selected vision model in a worker. */
final class ImageProfileService
{
    public function queue(array $values, UploadedFile $image, int $actorId): AiImageProfile
    {
        $queue = (string) config('queue.default');
        if (in_array(config('queue.connections.'.$queue.'.driver', $queue), ['sync', 'null'], true)) {
            throw ValidationException::withMessages(['image' => 'Phân tích ảnh cần queue worker bất đồng bộ.']);
        }
        if (AiImageProfile::where('created_by', $actorId)->where('created_at', '>=', now()->subHour())->count() >= (int) config('ai.import.quota_per_hour', 20)) {
            throw ValidationException::withMessages(['image' => 'Đã đạt giới hạn phân tích ảnh trong một giờ.']);
        }
        $snapshot = app(ModelResolver::class)->resolve(AiCapability::Vision, ['model_id' => $values['model_id']]);
        if (! in_array(AiCapability::Text->value, $snapshot['capabilities'] ?? [], true)
            || ! in_array($snapshot['driver'], ['openai', 'openai-compatible', 'gemini'], true)) {
            throw ValidationException::withMessages(['model_id' => 'Chọn model có Text generation và Vision của OpenAI, OpenAI-compatible hoặc Gemini.']);
        }
        $path = $image->store('ai-image-profiles/'.$actorId, 'local');
        try {
            return DB::transaction(function () use ($values, $image, $actorId, $snapshot, $path): AiImageProfile {
                $profile = AiImageProfile::create([
                    'created_by' => $actorId, 'name' => $values['name'], 'status' => 'queued',
                    'reference_path' => $path, 'reference_mime' => $image->getMimeType(),
                    'reference_hash' => hash_file('sha256', $image->getRealPath()),
                    'connection_snapshot_json' => $snapshot,
                    'analysis_instructions' => config('ai.image_profiles.instructions'),
                ]);
                AnalyzeAiImageProfileJob::dispatch($profile->id, (int) ($snapshot['timeout'] ?? 30))->afterCommit();

                return $profile;
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
    }

    public function process(int $id): void
    {
        if (! AiImageProfile::whereKey($id)->where('status', 'queued')->update(['status' => 'analyzing', 'updated_at' => now()])) {
            return;
        }
        $profile = AiImageProfile::findOrFail($id);
        $bytes = Storage::disk('local')->get($profile->reference_path);
        if (! hash_equals($profile->reference_hash, hash('sha256', $bytes))) {
            throw new \RuntimeException('Reference image changed.');
        }
        $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['summary', 'prompt', 'negative_prompt'], 'properties' => [
            'summary' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2000],
            'prompt' => ['type' => 'string', 'minLength' => 20, 'maxLength' => 6000],
            'negative_prompt' => ['type' => 'string', 'maxLength' => 2000],
        ]];
        $response = app(ProviderRegistry::class)->resolveForRun($profile->connection_snapshot_json)->execute(new AiTaskRequest(
            'image-profile.analysis', $profile->analysis_instructions, ['requested_name' => $profile->name], $schema,
            ['images' => [['mime_type' => $profile->reference_mime, 'data' => base64_encode($bytes)]]],
        ));
        AiImageProfile::whereKey($id)->where('status', 'analyzing')->update([
            'status' => 'ready', 'summary' => $response->output['summary'], 'prompt' => $response->output['prompt'],
            'negative_prompt' => $response->output['negative_prompt'], 'error_message' => null, 'updated_at' => now(),
        ]);
    }

    /** Immutable approved style; re-check ownership for every generation request. */
    public function snapshot(?int $id, int $actorId): ?array
    {
        if (! $id) {
            return null;
        }
        $profile = AiImageProfile::whereKey($id)->where('created_by', $actorId)->where('status', 'ready')->where('is_enabled', true)->first();
        if (! $profile) {
            throw ValidationException::withMessages(['image_profile_id' => 'Prompt ảnh không tồn tại hoặc chưa được bật.']);
        }

        return $profile->only(['id', 'name', 'prompt', 'negative_prompt']);
    }
}
