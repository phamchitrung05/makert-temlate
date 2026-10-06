<?php

namespace App\Services\Ai\Images;

use App\Models\AiImport;
use App\Services\Ai\Runs\AiRunService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Điều phối thumbnail ảnh độc lập với nội dung candidate.
 * CÁC HÀM: requested(), state(), pending(), editable(), schedule(),
 * accepts(), prepareRetry(), sync(), metadata().
 * INPUT/OUTPUT: run nội dung/ảnh -> child job, trạng thái public và ref ảnh.
 * SIDE EFFECT: khóa parent, ghi JSON và dispatch sau commit; không gọi AI.
 * EXCEPTION: lỗi dispatch giữ bài ready; retry parent đã quyết định trả 409.
 * =====================================================================
 */
final class AiThumbnailService
{
    /** INPUT: run. OUTPUT: có yêu cầu sinh thumbnail hay không; không query/ghi DB. */
    public static function requested(AiImport $run): bool
    {
        $fields = (array) data_get($run->input_json, 'fields', []);

        return $run->operation !== 'image' && data_get($run->input_json, 'thumbnail_mode') === 'generate'
            && data_get($run->input_json, 'generate_thumbnail', true)
            && ($fields === [] || in_array('thumbnail', $fields, true));
    }

    /** INPUT: parent đã load. OUTPUT: metadata allowlist; không query child hoặc lộ snapshot/key. */
    public static function state(AiImport $run): ?array
    {
        $state = (array) data_get($run->result_json, 'thumbnail_generation', []);
        if ($state === []) {
            return null;
        }
        $state = array_intersect_key($state, array_flip(['job_id', 'status', 'progress', 'error_code', 'error', 'provider', 'model']));
        if (in_array($state['status'] ?? null, ['queued', 'generating'], true)
            && ($run->applied_target_id || data_get($run->source_meta_json, 'editorial.status') === 'rejected')) {
            $state['status'] = 'cancelled';
            $state['error'] = 'Tác vụ ảnh đã dừng vì bài đã được quyết định.';
        }
        if (in_array($state['status'] ?? null, ['queued', 'generating'], true) && $run->expires_at?->isPast()) {
            $state['status'] = 'expired';
            $state['error'] = 'Thumbnail đã hết hạn. Hãy tạo bản mới.';
        }

        return $state;
    }

    /** INPUT: parent. OUTPUT: true khi ảnh vẫn đang chờ/chạy; hàm chỉ đọc JSON. */
    public static function pending(AiImport $run): bool
    {
        return in_array(self::state($run)['status'] ?? null, ['queued', 'generating'], true);
    }

    /** INPUT: parent. OUTPUT: còn được nhận ảnh hay không; không thay đổi quyết định đã lưu. */
    public static function editable(AiImport $parent): bool
    {
        return $parent->status === 'ready' && ! $parent->applied_target_id && ! $parent->expires_at?->isPast()
            && data_get($parent->source_meta_json, 'editorial.status') !== 'rejected';
    }

    /**
     * INPUT: parent vừa ready dưới lock của transaction completion.
     * OUTPUT: child queued và metadata được lưu trước dispatch; idempotent theo parent.
     * SIDE EFFECT: tạo child, đọc lại parent để giữ edit, dispatch sau commit.
     * EXCEPTION: dispatch lỗi chuyển child failed và sync lỗi ảnh; bài vẫn ready.
     */
    public function schedule(AiImport $parent): void
    {
        if (! self::requested($parent) || data_get($parent->result_json, 'image_job_id')) {
            return;
        }
        $snapshot = (array) data_get($parent->input_json, 'image_connection', []);
        if ($snapshot === []) {
            $result = (array) $parent->result_json;
            $result['thumbnail_generation'] = ['status' => 'failed', 'progress' => 0, 'job_id' => null,
                'error_code' => 'AI_IMAGE_CONFIGURATION', 'error' => 'Chưa có model ảnh hợp lệ. Hãy chọn model và tạo lại thumbnail.'];
            $parent->update(['result_json' => $result]);

            return;
        }
        $draft = (array) data_get($parent->result_json, 'draft', []);
        $child = AiImport::query()->create([
            'id' => (string) Str::uuid(), 'created_by' => $parent->created_by, 'source_url' => '',
            'source_hash' => hash('sha256', 'thumbnail|'.$parent->id), 'status' => 'queued', 'current_step' => 'queued', 'progress' => 0,
            'session_id' => $parent->session_id ?: $parent->id, 'parent_id' => $parent->id, 'operation' => 'image',
            'provider' => $snapshot['provider'] ?? null, 'prompt_version' => 'image-v1', 'expires_at' => $parent->expires_at,
            'input_json' => ['purpose' => 'thumbnail', 'target_type' => data_get($parent->input_json, 'target_type', 'post'),
                'prompt' => trim((string) data_get($parent->input_json, 'thumbnail_prompt')) ?: (trim((string) ($draft['thumbnail_prompt'] ?? '')) ?: (string) ($draft['title'] ?? 'Tạo ảnh đại diện')),
                'title' => (string) ($draft['title'] ?? 'AI thumbnail'), 'alt_text' => (string) data_get($draft, 'thumbnail.alt_text', $draft['title'] ?? ''),
                'ai_connection' => $snapshot],
        ]);
        // Event biên tập có thể đã cập nhật parent khi child được tạo.
        $parent->refresh();
        $result = (array) $parent->result_json;
        $result['image_job_id'] = $child->id;
        $result['thumbnail_generation'] = $this->metadata($child);
        $parent->update(['result_json' => $result]);
        DB::afterCommit(function () use ($child): void {
            try {
                app(AiRunService::class)->dispatch($child);
            } catch (Throwable $exception) {
                report($exception);
                AiImport::query()->whereKey($child->id)->whereNotIn('status', AiImport::TERMINAL_STATUSES)->update([
                    'status' => 'failed', 'current_step' => 'failed', 'error_code' => 'AI_IMAGE_QUEUE_FAILED',
                    'error_message' => 'Không thể xếp hàng tạo ảnh. Hãy thử lại thumbnail.', 'completed_at' => now(),
                ]);
                $this->sync($child->fresh());
            }
        });
    }

    /** INPUT: child/parent. OUTPUT: quan hệ thumbnail hiện hành đúng owner; không ghi DB. */
    public function accepts(AiImport $image, ?AiImport $parent): bool
    {
        return $parent && self::editable($parent) && (int) $parent->created_by === (int) $image->created_by
            && data_get($parent->result_json, 'image_job_id') === $image->id;
    }

    /** INPUT: child trước retry trong transaction. OUTPUT: parent khóa hoặc 409; không dispatch/gọi AI. */
    public function prepareRetry(AiImport $image): void
    {
        if (data_get($image->input_json, 'purpose') !== 'thumbnail') {
            return;
        }
        $parent = AiImport::query()->lockForUpdate()->find($image->parent_id);
        abort_unless($this->accepts($image, $parent), 409, 'Bài đã quyết định, hết hạn hoặc thumbnail này không còn hiện hành.');
    }

    /**
     * INPUT: child có trạng thái mới. OUTPUT: metadata và draft.thumbnail canonical.
     * SIDE EFFECT: khóa/đọc parent mới nhất; chỉ đổi thumbnail, giữ nội dung đã sửa.
     * EXCEPTION/TRANSACTION: transaction ngắn; bỏ response trễ/sai owner/child cũ/parent đã quyết định.
     */
    public function sync(?AiImport $image): void
    {
        if (! $image?->parent_id || data_get($image->input_json, 'purpose') !== 'thumbnail') {
            return;
        }
        DB::transaction(function () use ($image): void {
            $parent = AiImport::query()->lockForUpdate()->find($image->parent_id);
            if (! $this->accepts($image, $parent)) {
                return;
            }
            $result = (array) $parent->result_json;
            $result['thumbnail_generation'] = $this->metadata($image);
            $assetId = data_get($image->result_json, 'image.media_asset_id');
            if ($image->status === 'ready' && ! $image->expires_at?->isPast() && $assetId) {
                $result['image'] = ['media_asset_id' => $assetId];
                $result['draft']['thumbnail'] = ['media_asset_id' => $assetId, 'source_url' => null,
                    'alt_text' => data_get($image->input_json, 'alt_text', data_get($result, 'draft.title', '')), 'origin' => 'generated', 'image_run_id' => $image->id];
            }
            $parent->update(['result_json' => $result]);
        });
    }

    /** INPUT: child. OUTPUT: metadata lỗi an toàn, không lộ provider response/key; hàm thuần. */
    private function metadata(AiImport $image): array
    {
        return ['job_id' => $image->id, 'status' => $image->status, 'progress' => (int) $image->progress,
            'error_code' => $image->error_code,
            'error' => in_array($image->status, ['failed', 'cancelled', 'expired'], true) ? 'Không tạo được thumbnail AI; nội dung bài viết vẫn được giữ. Có thể thử lại ảnh hoặc duyệt bài không có ảnh.' : null,
            'provider' => $image->provider, 'model' => data_get($image->input_json, 'ai_connection.model')];
    }
}
