<?php

namespace App\Services\Ai\Content\Archives;

use App\Exceptions\AiArticleArchiveException;
use App\Models\AiArticleArchive;
use App\Models\AiImport;
use App\Services\Ai\Content\Data\ArticleInputHasher;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Giữ checkpoint tạm và lưu bền vững bản AI đã được duyệt.
 * =====================================================================
 *
 * Worker giữ checkpoint gốc tạm trong ai_imports để phục hồi candidate. Chỉ approve/Apply mới chuyển bản được chọn vào kho ai_article_archives; candidate khác hết hạn cùng danh sách Content AI.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct().
 * - supports().
 * - tracked().
 * - stageCompletion().
 * - resumeReady().
 * - archiveApproved().
 * - preserve().
 * - store().
 * - isApproved().
 * - assertPending().
 * - assertSnapshot().
 * - assertStored().
 * - syncLifecycle().
 * - transaction().
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Run/generation/result canonical, checkpoint và quyết định approved.
 * - OUTPUT: Checkpoint tạm hoặc kho immutable có source/draft/context/hash; lifecycle được cập nhật riêng.
 * - SIDE EFFECT: Ghi checkpoint/lifecycle/archive trong DB; không HTTP/AI hoặc giữ candidate chưa chọn lâu dài.
 * - EXCEPTION/TRANSACTION: Row lock/unique key trong transaction ngắn; lỗi an toàn truyền ra để rollback duyệt/cleanup và giữ dữ liệu phục hồi.
 * =====================================================================
 */
class AiArticleArchiveService
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Nhận builder snapshot dùng cho checkpoint và kho đã duyệt
     * =====================================================================
     *
     * INPUT:
     * - AiArticleSnapshot được container resolve.
     *
     * OUTPUT:
     * - Service sẵn sàng sử dụng.
     *
     * SIDE EFFECT:
     * - Chỉ gán dependency; không truy cập DB hoặc HTTP.
     *
     * EXCEPTION/TRANSACTION:
     * - Không tự mở transaction hoặc phát sinh I/O.
     *
     * =====================================================================
     */
    public function __construct(private readonly AiArticleSnapshot $snapshots) {}

    /**
     * =====================================================================
     * CHỨC NĂNG: Xác định run thuộc phạm vi kho bài Post
     * =====================================================================
     *
     * INPUT:
     * - AiImport đã load operation và input_json.
     *
     * OUTPUT:
     * - bool: true cho target Post không phải image run.
     *
     * SIDE EFFECT:
     * - Chỉ đọc thuộc tính model; không truy vấn hoặc ghi dữ liệu.
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction; không kiểm quyền thay caller.
     *
     * =====================================================================
     */
    public function supports(AiImport $run): bool
    {
        return $run->operation !== 'image' && data_get($run->input_json, 'target_type', 'post') === 'post';
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm contract checkpoint v1 của run Post
     * =====================================================================
     *
     * INPUT:
     * - AiImport đã load archive_version và thông tin target.
     *
     * OUTPUT:
     * - bool: true khi supports() và archive_version bằng 1.
     *
     * SIDE EFFECT:
     * - Chỉ đọc model; không I/O.
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction hoặc khóa row.
     *
     * =====================================================================
     */
    public function tracked(AiImport $run): bool
    {
        return $this->supports($run) && (int) $run->archive_version === 1;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Giữ checkpoint AI gốc tạm thời trước khi candidate ready
     * =====================================================================
     *
     * INPUT:
     * - UUID run, generation và kết quả canonical đã validate của worker.
     *
     * OUTPUT:
     * - bool: checkpoint được giữ hoặc run ngoài contract; false khi run mất, stale, terminal hoặc hết hạn.
     *
     * SIDE EFFECT:
     * - Redact output, tạo snapshot/hash và ghi archive_pending_json; run hết hạn chuyển expired. Chưa tạo archive bền vững.
     *
     * EXCEPTION/TRANSACTION:
     * - Transaction khóa run, kiểm generation/expiry và hash của checkpoint cũ; lỗi/conflict ném AiArticleArchiveException. Không giữ transaction qua HTTP.
     *
     * =====================================================================
     */
    public function stageCompletion(string $runId, int $generationNo, array $result): bool
    {
        return $this->transaction(function () use ($runId, $generationNo, $result): bool {
            $run = AiImport::query()->lockForUpdate()->find($runId);
            if (! $run || (int) $run->generation_no !== $generationNo || in_array($run->status, AiImport::TERMINAL_STATUSES, true)) {
                return false;
            }
            if ($run->expires_at?->isPast()) {
                $run->forceFill(['status' => 'expired', 'current_step' => 'expired', 'error_code' => 'EXPIRED', 'completed_at' => now()])->save();

                return false;
            }
            if (! $this->tracked($run)) {
                return true;
            }
            $result = $this->snapshots->recoveryResult($run, $result);
            $snapshot = $this->snapshots->make($run, $result);
            $pending = ['snapshot' => $snapshot, 'result' => $result];
            $pending['hash'] = ArticleInputHasher::hash($pending);
            if ($run->archive_pending_json !== null) {
                // Lần ghi lặp dùng timestamp/hash của checkpoint đầu, không dựng bản mới.
                $existing = (array) $run->archive_pending_json;
                $this->assertPending($run, $existing);
                $comparisonFields = array_diff(AiArticleArchive::SNAPSHOT_FIELDS, ['generation_started_at', 'generation_completed_at']);
                if (ArticleInputHasher::hash($existing['result'] ?? null) !== ArticleInputHasher::hash($result)
                    || ArticleInputHasher::hash(Arr::only($existing['snapshot'], $comparisonFields)) !== ArticleInputHasher::hash(Arr::only($snapshot, $comparisonFields))) {
                    throw new AiArticleArchiveException('AI_ARCHIVE_CONFLICT');
                }

                return true;
            }
            $run->forceFill(['archive_pending_json' => $pending])->save();

            return true;
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Phục hồi candidate ready từ checkpoint tạm mà không gọi lại AI
     * =====================================================================
     *
     * INPUT:
     * - UUID/generation của run v1 có checkpoint hoàn tất.
     *
     * OUTPUT:
     * - bool: true khi chuyển sang ready; false khi thiếu checkpoint, stale, đã terminal hoặc hết hạn.
     *
     * SIDE EFFECT:
     * - Ghi result/lifecycle và thời hạn run, giữ checkpoint đến khi duyệt; ready đã sửa tay không bị ghi đè.
     *
     * EXCEPTION/TRANSACTION:
     * - Transaction khóa run và kiểm hash; chỉ phục hồi failed có AI_ARCHIVE_WRITE_FAILED. Lỗi checkpoint truyền ra; không gọi provider.
     *
     * =====================================================================
     */
    public function resumeReady(string $runId, int $generationNo): bool
    {
        return $this->transaction(function () use ($runId, $generationNo): bool {
            $run = AiImport::query()->lockForUpdate()->find($runId);
            if (! $run || (int) $run->generation_no !== $generationNo || ! $this->tracked($run)) {
                return false;
            }
            $recoveringCheckpoint = $run->status === 'failed'
                && $run->error_code === 'AI_ARCHIVE_WRITE_FAILED';
            if (in_array($run->status, AiImport::TERMINAL_STATUSES, true) && ! $recoveringCheckpoint) {
                // Ready có thể đã được biên tập; redelivery không thay bằng checkpoint gốc.
                return false;
            }
            $pending = (array) $run->archive_pending_json;
            if ($pending === []) {
                return false;
            }
            $this->assertPending($run, $pending);
            if ($run->expires_at?->isPast()) {
                $run->forceFill(['status' => 'expired', 'current_step' => 'expired', 'error_code' => 'EXPIRED', 'completed_at' => now()])->save();

                return false;
            }
            $result = $pending['result'];
            $run->forceFill([
                'status' => 'ready', 'current_step' => 'ready', 'progress' => 100,
                'result_json' => $result, 'provider' => $result['provider'] ?? $run->provider,
                'prompt_version' => $result['prompt_version'] ?? $run->prompt_version,
                'completed_at' => $pending['snapshot']['generation_completed_at'],
                'expires_at' => now()->addDays((int) config('ai-import.retention_days', 2)),
                'error_code' => null, 'error_message' => null,
            ])->save();

            return true;
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đưa riêng bản Post AI đã duyệt vào kho bền vững
     * =====================================================================
     *
     * INPUT:
     * - UUID/generation đã Apply hoặc editorial approved; cờ legacy opt-in và lý do dọn tùy chọn.
     *
     * OUTPUT:
     * - AiArticleArchive hợp lệ hoặc null khi run mất, stale, ngoài phạm vi, chưa duyệt hoặc legacy chưa opt-in.
     *
     * SIDE EFFECT:
     * - Ghi snapshot bất biến, cập nhật lifecycle/liên kết Post và xóa checkpoint sau khi lưu thành công. Legacy chỉ giữ metadata/content null.
     *
     * EXCEPTION/TRANSACTION:
     * - Khóa run/archive trong transaction; caller duyệt giữ transaction chung với Post/provenance/quyết định. Thiếu original, hash sai hoặc conflict ném AiArticleArchiveException để rollback.
     *
     * =====================================================================
     */
    public function archiveApproved(string $runId, int $generationNo, bool $legacy = false, ?string $removalReason = null): ?AiArticleArchive
    {
        return $this->transaction(function () use ($runId, $generationNo, $legacy, $removalReason): ?AiArticleArchive {
            $run = AiImport::query()->lockForUpdate()->find($runId);
            if (! $run || (int) $run->generation_no !== $generationNo || ! $this->supports($run) || ! $this->isApproved($run)) {
                return null;
            }
            $archive = AiArticleArchive::query()->where('run_id', $run->id)
                ->where('generation_no', $run->generation_no)->lockForUpdate()->first();
            $pending = (array) $run->archive_pending_json;
            if ($archive) {
                $this->assertStored($archive);
                if ($pending !== []) {
                    $this->assertPending($run, $pending);
                    if (! hash_equals($archive->payload_hash, $pending['snapshot']['payload_hash'])) {
                        throw new AiArticleArchiveException('AI_ARCHIVE_CONFLICT');
                    }
                }
            }
            if (! $archive) {
                if ($this->tracked($run)) {
                    if ($pending === []) {
                        throw new AiArticleArchiveException('AI_ARCHIVE_ORIGINAL_MISSING');
                    }
                    $this->assertPending($run, $pending);
                    $archive = $this->store($run, $pending['snapshot']);
                } elseif ($legacy) {
                    $archive = $this->store($run, $this->snapshots->make($run, legacy: true));
                } else {
                    return null;
                }
            }
            $this->syncLifecycle($archive, $run, $removalReason);
            if ($run->archive_pending_json !== null) {
                $run->forceFill(['archive_pending_json' => null])->save();
            }

            return $archive;
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đối chiếu và bảo toàn kho của bản đã duyệt trước khi dọn run
     * =====================================================================
     *
     * INPUT:
     * - AiImport, cờ legacy và removalReason tùy chọn từ cleanup/delete/command.
     *
     * OUTPUT:
     * - Archive của generation đã duyệt hoặc null ngoài phạm vi.
     *
     * SIDE EFFECT:
     * - Ủy quyền archiveApproved() đối chiếu/cập nhật lifecycle hoặc phục hồi checkpoint approved còn sót; không lưu candidate chưa chọn.
     *
     * EXCEPTION/TRANSACTION:
     * - archiveApproved() khóa row trong transaction; lỗi giữ run/checkpoint để caller rollback việc dọn.
     *
     * =====================================================================
     */
    public function preserve(AiImport $run, bool $legacy = false, ?string $removalReason = null): ?AiArticleArchive
    {
        return $this->archiveApproved($run->id, (int) $run->generation_no, $legacy, $removalReason);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Ghi snapshot bất biến theo khóa run và generation
     * =====================================================================
     *
     * INPUT:
     * - Run đã khóa và snapshot có đủ trường/hash canonical.
     *
     * OUTPUT:
     * - Archive mới hoặc archive cùng khóa/cùng payload_hash.
     *
     * SIDE EFFECT:
     * - Đọc khóa unique dưới lock và insert ai_article_archives khi chưa có.
     *
     * EXCEPTION/TRANSACTION:
     * - Dùng transaction/lock của caller; sai identity/hash hoặc khác payload ném AiArticleArchiveException. Không ghi đè bản cũ.
     *
     * =====================================================================
     */
    private function store(AiImport $run, array $snapshot): AiArticleArchive
    {
        $this->assertSnapshot($snapshot);
        if ($snapshot['run_id'] !== (string) $run->id || $snapshot['generation_no'] !== (int) $run->generation_no) {
            throw new AiArticleArchiveException('AI_ARCHIVE_CONFLICT');
        }
        $archive = AiArticleArchive::query()->where('run_id', $run->id)
            ->where('generation_no', $run->generation_no)->lockForUpdate()->first();
        if ($archive) {
            if (! hash_equals($archive->payload_hash, $snapshot['payload_hash'])) {
                throw new AiArticleArchiveException('AI_ARCHIVE_CONFLICT');
            }

            return $archive;
        }

        return AiArticleArchive::query()->create($snapshot);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm bằng chứng Apply hoặc quyết định approved trên run
     * =====================================================================
     *
     * INPUT:
     * - AiImport có applied_target_id và source_meta_json.
     *
     * OUTPUT:
     * - bool: đã Apply vào Post hoặc editorial.status là approved.
     *
     * SIDE EFFECT:
     * - Chỉ đọc thuộc tính; không truy vấn hoặc ghi dữ liệu.
     *
     * EXCEPTION/TRANSACTION:
     * - Không mở transaction; caller chịu trách nhiệm authorization và khóa run khi mutation.
     *
     * =====================================================================
     */
    private function isApproved(AiImport $run): bool
    {
        return $run->applied_target_id !== null || data_get($run->source_meta_json, 'editorial.status') === 'approved';
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Xác minh checkpoint tạm còn nguyên bản AI gốc
     * =====================================================================
     *
     * INPUT:
     * - Run đã khóa và checkpoint chứa snapshot/result/hash.
     *
     * OUTPUT:
     * - void: vượt qua khi hash, identity/generation và content_hash khớp.
     *
     * SIDE EFFECT:
     * - Chỉ tính hash trong memory; không sửa payload hoặc gọi DB/HTTP.
     *
     * EXCEPTION/TRANSACTION:
     * - Ném AiArticleArchiveException khi checkpoint thiếu/sai hash/identity; dùng transaction của caller.
     *
     * =====================================================================
     */
    private function assertPending(AiImport $run, array $pending): void
    {
        if (! is_array($pending['snapshot'] ?? null) || ! is_array($pending['result'] ?? null)
            || ! is_string($pending['hash'] ?? null)
            || ! hash_equals($pending['hash'], ArticleInputHasher::hash(Arr::only($pending, ['snapshot', 'result'])))) {
            throw new AiArticleArchiveException('AI_ARCHIVE_CHECKPOINT_INVALID');
        }
        $snapshot = $pending['snapshot'];
        $this->assertSnapshot($snapshot);
        if ($snapshot['run_id'] !== (string) $run->id || $snapshot['generation_no'] !== (int) $run->generation_no
            || ArticleInputHasher::hash(Arr::only((array) ($pending['result']['draft'] ?? []), AiArticleSnapshot::DRAFT_FIELDS)) !== $snapshot['content_hash']) {
            throw new AiArticleArchiveException('AI_ARCHIVE_CHECKPOINT_INVALID');
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Xác minh hash canonical của snapshot bài và nguồn
     * =====================================================================
     *
     * INPUT:
     * - Snapshot đủ trường bất biến, payload_hash/source_hash/content_hash.
     *
     * OUTPUT:
     * - void: vượt qua khi cấu trúc và ba hash khớp.
     *
     * SIDE EFFECT:
     * - Chỉ kiểm cấu trúc và tính hash; không I/O.
     *
     * EXCEPTION/TRANSACTION:
     * - Ném AiArticleArchiveException với AI_ARCHIVE_CHECKPOINT_INVALID khi dữ liệu không khớp; không tự mở transaction.
     *
     * =====================================================================
     */
    private function assertSnapshot(array $snapshot): void
    {
        if (array_diff(AiArticleArchive::SNAPSHOT_FIELDS, array_keys($snapshot)) !== []
            || ! is_string($snapshot['payload_hash'] ?? null)
            || ! hash_equals($snapshot['payload_hash'], ArticleInputHasher::hash(Arr::only($snapshot, AiArticleArchive::SNAPSHOT_FIELDS)))
            || ($snapshot['source_snapshot_json'] === null ? null : ArticleInputHasher::hash($snapshot['source_snapshot_json'])) !== $snapshot['source_hash']
            || ($snapshot['draft_snapshot_json'] === null ? null : ArticleInputHasher::hash($snapshot['draft_snapshot_json'])) !== $snapshot['content_hash']) {
            throw new AiArticleArchiveException('AI_ARCHIVE_CHECKPOINT_INVALID');
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tính toàn vẹn kho đã lưu trước khi bỏ run tạm
     * =====================================================================
     *
     * INPUT:
     * - AiArticleArchive đã load JSON và các mốc thời gian.
     *
     * OUTPUT:
     * - void: snapshot lưu trong DB qua kiểm hash canonical.
     *
     * SIDE EFFECT:
     * - Đọc thuộc tính private trực tiếp và chuẩn hóa date trong memory; không truy vấn mới hoặc ghi DB.
     *
     * EXCEPTION/TRANSACTION:
     * - assertSnapshot() truyền lỗi checkpoint sai; caller giữ transaction/lock và không dọn run nếu kho hỏng.
     *
     * =====================================================================
     */
    private function assertStored(AiArticleArchive $archive): void
    {
        $snapshot = [];
        foreach (AiArticleArchive::SNAPSHOT_FIELDS as $field) {
            $snapshot[$field] = $archive->getAttribute($field);
        }
        foreach (['generation_started_at', 'generation_completed_at'] as $field) {
            $snapshot[$field] = $archive->getAttribute($field)?->format('Y-m-d H:i:s');
        }
        $this->assertSnapshot($snapshot + ['payload_hash' => $archive->payload_hash]);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Cập nhật metadata duyệt và dọn run mà giữ nguyên payload gốc
     * =====================================================================
     *
     * INPUT:
     * - Archive/run đã khóa và lý do dọn tùy chọn.
     *
     * OUTPUT:
     * - void: lifecycle_json và applied_target_id được lưu.
     *
     * SIDE EFFECT:
     * - Redact lý do duyệt, ghi reviewer/thời điểm/fields/removed metadata; không đổi nội dung hoặc hash snapshot.
     *
     * EXCEPTION/TRANSACTION:
     * - Dùng transaction của caller; lỗi Eloquent/DB truyền lên để rollback duyệt hoặc cleanup.
     *
     * =====================================================================
     */
    private function syncLifecycle(AiArticleArchive $archive, AiImport $run, ?string $removalReason = null): void
    {
        $review = (array) data_get($run->source_meta_json, 'editorial', []);
        $lifecycle = array_replace((array) $archive->lifecycle_json, [
            'run_status' => $run->status,
            'review_status' => $run->applied_target_id ? 'approved' : ($review['status'] ?? 'pending_review'),
            'revision' => (int) ($review['revision'] ?? 0), 'reviewed_by' => data_get($review, 'reviewed_by.id'),
            'reviewed_at' => $review['reviewed_at'] ?? null,
            'reason' => $this->snapshots->redactForRun($review['reason'] ?? null, $run),
            'applied_fields' => (array) $run->applied_fields,
        ]);
        if ($run->applied_target_id) {
            $lifecycle['applied_at'] ??= now()->toIso8601String();
        }
        if ($removalReason !== null) {
            $lifecycle['removed_at'] = now()->toIso8601String();
            $lifecycle['removed_reason'] = $removalReason;
        }
        $archive->forceFill(['lifecycle_json' => $lifecycle, 'applied_target_id' => $run->applied_target_id])->save();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Bọc thao tác kho bằng transaction và lỗi an toàn
     * =====================================================================
     *
     * INPUT:
     * - Callback chỉ thao tác DB, không HTTP/provider.
     *
     * OUTPUT:
     * - Kết quả callback sau commit hoặc AiArticleArchiveException an toàn.
     *
     * SIDE EFFECT:
     * - Mở DB transaction với tối đa ba lần retry theo cơ chế DB; không giữ SQL/payload trong exception trả ra.
     *
     * EXCEPTION/TRANSACTION:
     * - Giữ nguyên AiArticleArchiveException; Throwable khác được đổi sang AI_ARCHIVE_WRITE_FAILED. Caller duyệt có thể bao transaction ngoài.
     *
     * =====================================================================
     */
    private function transaction(callable $callback): mixed
    {
        try {
            return DB::transaction($callback, 3);
        } catch (AiArticleArchiveException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new AiArticleArchiveException;
        }
    }
}
