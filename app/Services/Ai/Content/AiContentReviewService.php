<?php

namespace App\Services\Ai\Content;

use App\Actions\Posts\CreatePostAction;
use App\Actions\Posts\UpdatePostAction;
use App\Http\Resources\MediaAssetResource;
use App\Models\AiImport;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\User;
use App\Services\Ai\Images\AiThumbnailService;
use App\Services\Ai\Provenance\AiProvenanceService;
use App\Services\Ai\Registries\TargetRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Duyệt/từ chối candidate trong ai_imports hiện có.
 * =====================================================================
 * Trạng thái biên tập nằm trong source_meta_json.editorial; retention không đổi.
 * CÁC HÀM/METHOD TRONG FILE: __construct(), state(), version(), authorize(),
 * view(), history(), approve(), reject(), assertPending(), recordDecision().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : actor, candidate, phiên bản đã xem và quyết định của người dùng.
 * - OUTPUT: thông tin duyệt, nguồn đã sanitize, lịch sử hoặc Post draft.
 * - SIDE EFFECT: transaction ghi Post/provenance/quyết định/Activitylog; không gọi AI.
 * =====================================================================
 */
final class AiContentReviewService
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Nhận domain actions, registry, provenance và sanitizer.
     * INPUT: các service đã resolve từ container.
     * OUTPUT: service sẵn sàng xử lý, không ghi database hoặc mở transaction.
     * =====================================================================
     */
    public function __construct(
        private readonly CreatePostAction $create,
        private readonly UpdatePostAction $update,
        private readonly TargetRegistry $targets,
        private readonly AiProvenanceService $provenance,
        private readonly AiContentSanitizer $sanitizer,
    ) {}

    /**
     * =====================================================================
     * CHỨC NĂNG: Đọc trạng thái biên tập độc lập trạng thái job.
     * INPUT: candidate hiện có và JSON editorial nếu đã ghi.
     * OUTPUT: metadata review, không mutation hoặc query quan hệ.
     * Bản Apply cũ suy ra approved, không dựng người duyệt hoặc lịch sử giả.
     * =====================================================================
     */
    public static function state(AiImport $run): array
    {
        $saved = (array) data_get($run->source_meta_json, 'editorial', []);
        $status = $run->applied_target_id ? 'approved'
            : (($saved['status'] ?? null) === 'rejected' ? 'rejected'
                : ($run->status === 'ready' ? 'pending_review' : 'not_ready'));

        return [
            'status' => $status,
            'revision' => (int) ($saved['revision'] ?? 0),
            'reviewed_by' => $saved['reviewed_by'] ?? null,
            'reviewed_at' => $saved['reviewed_at'] ?? null,
            'reason' => $saved['reason'] ?? null,
            'post_id' => $run->applied_target_id,
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo phiên bản quyết định từ draft và review hiện tại.
     * INPUT: candidate đã load JSON.
     * OUTPUT: SHA-256; không ghi database. JSON lỗi ném JsonException.
     * =====================================================================
     */
    public static function version(AiImport $run): string
    {
        return hash('sha256', json_encode([
            data_get($run->result_json, 'draft', []), self::state($run),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm admin posts.manage, owner và target Post không phải image run.
     * INPUT: authenticated actor và candidate đã load.
     * OUTPUT: không có; ném HTTP 404/403/422 khi vi phạm boundary.
     * SIDE EFFECT: chỉ đọc permission; không tự mở transaction hoặc ghi dữ liệu.
     * =====================================================================
     */
    public function authorize(User $actor, AiImport $run): void
    {
        abort_unless((int) $run->created_by === (int) $actor->getKey(), 404);
        abort_unless($actor->can('posts.manage'), 403);
        abort_unless(data_get($run->input_json, 'target_type', 'post') === 'post' && $run->operation !== 'image', 422, 'Duyệt nội dung chỉ hỗ trợ bài Post.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Trả bản để đối chiếu nguồn/kết quả trước khi quyết định.
     * INPUT: authenticated actor và candidate đã load.
     * OUTPUT: nguồn sanitize, draft/review/version allowlist hoặc lỗi authorize.
     * SIDE EFFECT: không fetch nguồn/gọi AI/ghi DB; không trả snapshot riêng hoặc key.
     * =====================================================================
     */
    public function view(User $actor, AiImport $run): array
    {
        $this->authorize($actor, $run);
        $source = (array) data_get($run->source_meta_json, 'article_source', []);
        $html = (string) ($source['content_html'] ?? '');
        if ($html === '' && filled($run->source_text)) {
            $html = '<pre>'.htmlspecialchars($run->source_text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</pre>';
        }
        $review = self::state($run);
        $canReview = $review['status'] === 'pending_review' && ! $run->expires_at?->isPast();
        $thumbnail = MediaAsset::query()->with('media')->find(data_get($run->result_json, 'draft.thumbnail.media_asset_id'));

        return [
            'job_id' => $run->id,
            'status' => $run->status,
            'target_type' => 'post',
            'draft' => $run->status === 'ready' ? array_intersect_key((array) data_get($run->result_json, 'draft', []), array_flip([
                'title', 'content', 'content_html', 'excerpt', 'seo_title', 'seo_description', 'focus_keyword', 'category_ids', 'tag_ids', 'taxonomy_origin', 'thumbnail',
            ])) : null,
            'draft_version' => hash('sha256', json_encode(data_get($run->result_json, 'draft', []))),
            'review' => $review,
            'review_version' => self::version($run),
            'can_review' => $canReview,
            'can_approve' => $canReview && ! AiThumbnailService::pending($run),
            'can_edit' => $canReview,
            'applied_target_id' => $run->applied_target_id,
            'has_thumbnail' => filled(data_get($run->result_json, 'draft.thumbnail.media_asset_id')),
            'thumbnail' => $thumbnail ? MediaAssetResource::make($thumbnail) : null,
            'thumbnail_generation' => AiThumbnailService::state($run),
            'expires_at' => $run->expires_at?->toIso8601String(),
            'source' => ['title' => (string) ($source['title'] ?? ''), 'content_html' => $this->sanitizer->sanitize($html), 'available' => $html !== ''],
            'provider' => $run->provider,
            'model' => data_get($run->input_json, 'model'),
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đọc sự kiện biên tập từ Spatie Activitylog theo UUID candidate.
     * INPUT: authenticated actor, run, page/perPage đã validate.
     * OUTPUT: items allowlist + pagination hoặc lỗi authorize.
     * SIDE EFFECT: query Activity/causer; không ghi DB hoặc mở transaction.
     * =====================================================================
     */
    public function history(User $actor, AiImport $run, int $page = 1, int $perPage = 20): array
    {
        $this->authorize($actor, $run);
        $events = Activity::query()->where('log_name', 'ai-content')
            ->where('properties->candidate_id', $run->id)
            ->whereIn('description', ['candidate.edited', 'candidate.approved', 'candidate.rejected'])
            ->with('causer')->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);

        return [
            'items' => $events->map(fn (Activity $event): array => [
                'id' => $event->id, 'event' => $event->description,
                'actor' => $event->causer ? ['id' => $event->causer_id, 'name' => $event->causer->name] : $event->properties->get('reviewed_by'),
                'at' => $event->created_at?->toIso8601String(),
                'reason' => $event->properties->get('reason'),
                'post_id' => $event->properties->get('post_id'),
                'fields' => $event->description === 'candidate.edited' ? $event->properties->get('fields', []) : [],
            ])->all(),
            'pagination' => ['current_page' => $events->currentPage(), 'last_page' => $events->lastPage(), 'total' => $events->total(), 'per_page' => $events->perPage()],
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Duyệt candidate, tạo/cập nhật Post nháp và ghi quyết định/audit.
     * INPUT: authenticated actor, candidate, field selection/version đã validate.
     * OUTPUT: Post draft ID/provenance/review; không Publish hoặc gọi AI.
     * SIDE EFFECT: transaction khóa run/target và ghi Post/provenance/JSON/Activitylog.
     * EXCEPTION: 404/403/422 authorize; 409 stale/đã quyết định/hết hạn;
     * lỗi action/media/validation rollback toàn bộ transaction.
     * API Apply cũ dùng cùng method; target_id chỉ phục vụ contract Apply cũ.
     * =====================================================================
     */
    public function approve(User $actor, AiImport $candidate, array $data): array
    {
        $this->authorize($actor, $candidate);
        $data['expected_version'] ??= hash('sha256', json_encode(data_get($candidate->result_json, 'draft', [])));

        return DB::transaction(function () use ($actor, $candidate, $data): array {
            $run = AiImport::query()->lockForUpdate()->findOrFail($candidate->id);
            $this->authorize($actor, $run);
            $this->assertPending($run, $data);
            abort_if(AiThumbnailService::pending($run), 409, 'Thumbnail đang được tạo. Hãy chờ ảnh hoặc hủy tác vụ ảnh trước khi duyệt.');
            $outputs = (array) data_get($run->result_json, 'draft', []);
            if (in_array('taxonomy', $data['fields'], true)) {
                $explicit = array_key_exists('category_ids', $data) || array_key_exists('tag_ids', $data);
                $manual = data_get($run->input_json, 'taxonomy_origin') === 'manual' || ($outputs['taxonomy_origin'] ?? null) === 'manual';
                if (! $explicit && ! $manual) {
                    throw ValidationException::withMessages(['category_ids' => 'Hãy chọn hoặc xác nhận taxonomy thủ công trước khi duyệt.']);
                }
                foreach (['category_ids', 'tag_ids'] as $field) {
                    $outputs[$field] = $data[$field] ?? ($manual ? ($outputs[$field] ?? data_get($run->input_json, $field, [])) : []);
                }
            }
            if (in_array('content', $data['fields'], true)) {
                $outputs['content_html'] = $this->sanitizer->sanitize((string) ($outputs['content_html'] ?? $outputs['content'] ?? ''));
                $outputs['content'] = $outputs['content_html'];
            }
            $payload = $this->targets->adapter('post')->toApplyPayload($outputs, $data['fields']);
            $target = ! empty($data['target_id']) ? Post::query()->lockForUpdate()->findOrFail($data['target_id']) : null;
            if ($target && ! empty($data['expected_updated_at']) && (string) $target->updated_at !== (string) $data['expected_updated_at']) {
                abort(409, 'Post đã thay đổi, hãy tải lại trước khi áp dụng candidate.');
            }
            if (! $target && blank($payload['title'] ?? null)) {
                throw ValidationException::withMessages(['title' => 'Post nháp mới cần tiêu đề hợp lệ từ candidate.']);
            }
            $payload['status'] = 'draft';
            $post = $target ? $this->update->handle($target, $payload, $actor->id) : $this->create->handle($payload, $actor->id);
            $this->provenance->recordPost($actor->id, $post, $run->id, $data['fields'], $payload);
            $run->refresh();
            $this->recordDecision($run, $actor, 'approved', $data['reason'] ?? null);

            return ['post_id' => $post->id, 'fields' => $data['fields'], 'provenance' => data_get($run->result_json, 'provider'), 'review' => self::state($run)];
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Từ chối candidate với lý do, không sửa draft hoặc tạo Post.
     * INPUT: authenticated actor, candidate, hai hash/lý do đã validate.
     * OUTPUT: rejected và review version mới; lỗi authorize/409 truyền lên.
     * SIDE EFFECT: transaction khóa run, ghi JSON và Activitylog cùng nhau.
     * =====================================================================
     */
    public function reject(User $actor, AiImport $candidate, array $data): array
    {
        $this->authorize($actor, $candidate);

        return DB::transaction(function () use ($actor, $candidate, $data): array {
            $run = AiImport::query()->lockForUpdate()->findOrFail($candidate->id);
            $this->authorize($actor, $run);
            $this->assertPending($run, $data);
            $this->recordDecision($run, $actor, 'rejected', $data['reason']);

            return ['job_id' => $run->id, 'review' => self::state($run), 'review_version' => self::version($run)];
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chặn trạng thái và phiên bản không còn đủ điều kiện quyết định.
     * INPUT: run đã lockForUpdate trong transaction của caller và hash đã xem.
     * OUTPUT: không có hoặc HTTP 409 khi chưa ready/hết hạn/đã quyết định/stale.
     * SIDE EFFECT: chỉ đọc model; không mở transaction riêng hoặc ghi DB.
     * =====================================================================
     */
    private function assertPending(AiImport $run, array $data): void
    {
        abort_unless($run->status === 'ready' && ! $run->expires_at?->isPast(), 409, 'Candidate chưa sẵn sàng hoặc đã hết hạn.');
        abort_unless(self::state($run)['status'] === 'pending_review', 409, 'Bài đã được duyệt hoặc từ chối. Hãy tải lại trạng thái.');
        if (isset($data['expected_version'])) {
            abort_unless(hash_equals(hash('sha256', json_encode(data_get($run->result_json, 'draft', []))), $data['expected_version']), 409, 'Nội dung đã thay đổi. Hãy xem lại trước khi quyết định.');
        }
        if (isset($data['expected_review_version'])) {
            abort_unless(hash_equals(self::version($run), $data['expected_review_version']), 409, 'Trạng thái duyệt đã thay đổi. Hãy tải lại.');
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Ghi quyết định và audit lấy actor/thời điểm từ server.
     * INPUT: run đã khóa, authenticated actor, approved/rejected và lý do.
     * OUTPUT: không có; metadata/audit persisted, giữ nguồn/draft/retention.
     * SIDE EFFECT: ghi JSON và Spatie Activitylog trong transaction bắt buộc của caller.
     * EXCEPTION: lỗi lưu truyền lên để rollback toàn bộ quyết định/Post.
     * =====================================================================
     */
    private function recordDecision(AiImport $run, User $actor, string $status, ?string $reason): void
    {
        $review = [
            'status' => $status, 'revision' => self::state($run)['revision'] + 1,
            'reviewed_by' => ['id' => $actor->id, 'name' => $actor->name],
            'reviewed_at' => now()->toIso8601String(), 'reason' => filled($reason) ? trim($reason) : null,
        ];
        $run->forceFill(['source_meta_json' => array_replace((array) $run->source_meta_json, ['editorial' => $review])])->save();
        activity('ai-content')->causedBy($actor)->withProperties([
            'candidate_id' => $run->id, ...$review, 'post_id' => $run->applied_target_id,
        ])->log('candidate.'.$status);
    }
}
