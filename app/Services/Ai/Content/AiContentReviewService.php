<?php

namespace App\Services\Ai\Content;

use App\Actions\Posts\CreatePostAction;
use App\Actions\Posts\UpdatePostAction;
use App\Http\Resources\MediaAssetResource;
use App\Models\AiImport;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\User;
use App\Services\Ai\Content\Archives\AiArticleArchiveService;
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
 *
 * Duyệt/từ chối trên candidate ai_imports hiện có. Quyết định nằm ở editorial metadata, retention không đổi; bản được duyệt lưu Post/provenance/quyết định/audit/archive đồng bộ.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct().
 * - state().
 * - version().
 * - authorize().
 * - view().
 * - history().
 * - approve().
 * - reject().
 * - assertPending().
 * - recordDecision().
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Authenticated actor posts.manage, candidate, phiên bản đã xem và quyết định.
 * - OUTPUT: Nguồn/draft/review/history allowlist hoặc Post draft; chưa Publish.
 * - SIDE EFFECT: Duyệt ghi kho bản AI gốc trước edit; từ chối chỉ ghi quyết định/audit.
 * - EXCEPTION/TRANSACTION: Kiểm owner/target/quyền; mutation khóa row trong transaction, lỗi Post/archive/audit rollback toàn bộ.
 * =====================================================================
 */
final class AiContentReviewService
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Nhận domain actions và service phục vụ duyệt candidate
     * =====================================================================
     *
     * INPUT:
     * - CreatePostAction, UpdatePostAction, TargetRegistry, AiProvenanceService và AiContentSanitizer.
     *
     * OUTPUT:
     * - Service sẵn sàng xử lý quyết định biên tập.
     *
     * SIDE EFFECT:
     * - Chỉ gán dependency; không gọi DB/provider.
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction hoặc lock khi khởi tạo.
     *
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
     * CHỨC NĂNG: Đọc trạng thái biên tập độc lập với trạng thái job
     * =====================================================================
     *
     * INPUT:
     * - Candidate có applied_target_id/source_meta_json/status đã load.
     *
     * OUTPUT:
     * - Array review metadata; Apply cũ suy approved, không dựng người duyệt hoặc lịch sử giả.
     *
     * SIDE EFFECT:
     * - Chỉ đọc model; không query quan hệ hoặc ghi dữ liệu.
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction, không kiểm quyền thay caller.
     *
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
     * CHỨC NĂNG: Tạo phiên bản quyết định từ draft và review hiện tại
     * =====================================================================
     *
     * INPUT:
     * - Candidate đã load result_json và metadata biên tập.
     *
     * OUTPUT:
     * - SHA-256 của draft và state dùng chặn quyết định stale.
     *
     * SIDE EFFECT:
     * - Chỉ serialize/tính hash trong memory; không ghi DB.
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction; JSON không encode được ném JsonException.
     *
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
     * CHỨC NĂNG: Kiểm quyền posts.manage, owner và target Post của candidate
     * =====================================================================
     *
     * INPUT:
     * - User đã xác thực và candidate đã load.
     *
     * OUTPUT:
     * - void: vượt qua khi cùng owner, có posts.manage và Post non-image.
     *
     * SIDE EFFECT:
     * - Đọc permission; không tự ghi dữ liệu hoặc mở transaction.
     *
     * EXCEPTION/TRANSACTION:
     * - Ném HTTP 404 khi khác owner, 403 thiếu quyền hoặc 422 sai target; caller cung cấp authenticated actor.
     *
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
     * CHỨC NĂNG: Trả nguồn và candidate để đối chiếu trước khi quyết định
     * =====================================================================
     *
     * INPUT:
     * - Authenticated actor có posts.manage và candidate thuộc owner.
     *
     * OUTPUT:
     * - Array nguồn sanitize, draft/review/version allowlist, thumbnail/trạng thái ảnh và cờ khả năng duyệt/sửa.
     *
     * SIDE EFFECT:
     * - Đọc thumbnail với media eager load; nguồn fallback source_text nếu thiếu HTML snapshot. Không fetch nguồn/gọi AI/ghi DB hoặc trả key riêng.
     *
     * EXCEPTION/TRANSACTION:
     * - authorize() có thể ném HTTP 404/403/422; không mở transaction.
     *
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
     * CHỨC NĂNG: Đọc lịch sử biên tập của candidate từ Activitylog
     * =====================================================================
     *
     * INPUT:
     * - Authenticated actor, run và page/perPage đã validate.
     *
     * OUTPUT:
     * - Items allowlist cùng pagination của các event edited/approved/rejected.
     *
     * SIDE EFFECT:
     * - Filter log_name ai-content và UUID candidate; eager load causer, sort id giảm dần và paginate. Không ghi DB.
     *
     * EXCEPTION/TRANSACTION:
     * - authorize() có thể ném HTTP 404/403/422; không mở transaction hoặc lock.
     *
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
     * CHỨC NĂNG: Duyệt candidate và lưu Post nháp cùng kho bản AI được chọn
     * =====================================================================
     *
     * INPUT:
     * - Authenticated actor posts.manage, candidate, fields/hash/lý do đã validate; target_id chỉ cho Apply cũ.
     *
     * OUTPUT:
     * - Post draft ID/provenance/review/version; không Publish.
     *
     * SIDE EFFECT:
     * - Ghi Post/provenance/quyết định/Activitylog/archive cùng nhau; Post dùng bản biên tập, kho dùng checkpoint AI trước edit. API Apply cũ dùng cùng method.
     *
     * EXCEPTION/TRANSACTION:
     * - Transaction khóa run/target; 404/403/422 do authorization/validation, 409 khi stale/đã quyết định/hết hạn/thumbnail pending. Lỗi action/media/archive/audit rollback toàn bộ; không HTTP/AI trong transaction.
     *
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
     * CHỨC NĂNG: Từ chối candidate và lưu lý do mà không tạo Post hoặc archive
     * =====================================================================
     *
     * INPUT:
     * - Authenticated actor posts.manage, candidate, hai hash và lý do đã validate.
     *
     * OUTPUT:
     * - Review rejected và review_version mới.
     *
     * SIDE EFFECT:
     * - Ghi editorial JSON/Activitylog, giữ draft và retention.
     *
     * EXCEPTION/TRANSACTION:
     * - Transaction khóa run; authorize() có thể ném 404/403/422, assertPending() ném 409. Lỗi lưu rollback quyết định.
     *
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
     * CHỨC NĂNG: Chặn candidate không còn đủ điều kiện quyết định
     * =====================================================================
     *
     * INPUT:
     * - Run đã lockForUpdate trong transaction caller và hash/version người dùng đã xem.
     *
     * OUTPUT:
     * - void: candidate ready, còn hạn, pending_review và version khớp.
     *
     * SIDE EFFECT:
     * - Chỉ kiểm model/hash; không query/ghi DB hoặc tự mở transaction.
     *
     * EXCEPTION/TRANSACTION:
     * - Ném HTTP 409 khi chưa ready, hết hạn, đã duyệt/từ chối hoặc stale; caller chịu trách nhiệm authorization/lock.
     *
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
     * CHỨC NĂNG: Ghi quyết định và audit bằng actor/thời điểm từ server
     * =====================================================================
     *
     * INPUT:
     * - Run đã khóa, authenticated actor, approved/rejected và lý do.
     *
     * OUTPUT:
     * - void: metadata/audit được lưu, giữ nguồn/draft/retention.
     *
     * SIDE EFFECT:
     * - Ghi editorial JSON và Activitylog; approved tạo/cập nhật archive, rejected không tạo kho.
     *
     * EXCEPTION/TRANSACTION:
     * - Yêu cầu transaction của approve()/reject(); lỗi lưu/archive/audit truyền lên để rollback Post/quyết định.
     *
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
        if ($status === 'approved') {
            app(AiArticleArchiveService::class)->archiveApproved($run->id, (int) $run->generation_no, legacy: true);
        }
        activity('ai-content')->causedBy($actor)->withProperties([
            'candidate_id' => $run->id, ...$review, 'post_id' => $run->applied_target_id,
        ])->log('candidate.'.$status);
    }
}
