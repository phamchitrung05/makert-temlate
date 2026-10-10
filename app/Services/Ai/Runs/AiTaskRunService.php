<?php

namespace App\Services\Ai\Runs;

use App\Models\AiImport;
use App\Models\AiTaskRun;
use App\Models\AiWritingProfileAnalysis;
use App\Models\User;
use App\Services\Ai\Errors\AiPublicErrorMessage;
use App\Services\Ai\Runs\Contracts\AiTaskRunAdapter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Theo dõi và hủy task AI dùng chung, giữ model nghiệp vụ làm nguồn chuẩn.
 * =====================================================================
 * AiRunService, analysis service và worker dùng tracker để đăng ký/cập nhật.
 * Registry/adapter chịu schema riêng từng model, còn API đối chiếu nguồn để
 * bắt cả mutation bằng query builder và cleanup. Worker mới dùng syncFrom(Model).
 * Unique dedupe_key phân biệt UUID nguồn và generation; không lưu prompt/key/payload.
 * CÁC HÀM/METHOD TRONG FILE:
 * - __construct(): nhận registry adapter từ service container.
 * - registerAnalysis(): đăng ký analysis văn phong theo adapter.
 * - registerImport(): đăng ký content/image import theo generation.
 * - register(): đăng ký model bất kỳ có adapter.
 * - syncFromAnalysis(): đồng bộ analysis dưới source lock.
 * - syncFromImport(): đồng bộ import dưới generation guard.
 * - syncFrom(): API generic cho worker/model mới.
 * - syncSource(): khóa source và tạo projection tracker.
 * - attributes(): chuẩn hóa projection field bounded.
 * - persist(): createOrFirst theo dedupe key rồi update tracker.
 * - start(): chuyển tracker active sang processing/analyzing.
 * - sync(): cập nhật lifecycle với terminal guard atomic.
 * - visibleTo(): scope owner và quyền module qua registry.
 * - canUse(): kiểm actor có ít nhất một module queue.
 * - reconcile(): đối chiếu một tracker với source authoritative.
 * - reconcileActive(): reconcile toàn bộ tracker active visible.
 * - cancel(): hủy đúng source/generation rồi chạy cleanup adapter.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : nguồn có adapter, actor và ID queue tùy chọn.
 * - OUTPUT: AiTaskRun chỉ có metadata/status/progress cùng liên kết kết quả.
 * - SIDE EFFECT: transaction ngắn ghi tracker; cancel ghi đúng nguồn và sync thumbnail.
 * - EXCEPTION/TRANSACTION: khóa nguồn trước tracker; không giữ khóa qua provider HTTP.
 * =====================================================================
 */
final class AiTaskRunService
{
    public const ACTIVE_STATUSES = ['queued', 'running', 'processing', 'analyzing'];

    public const TERMINAL_STATUSES = ['ready', 'failed', 'cancelled', 'expired'];

    /**
     * =====================================================================
     * CHỨC NĂNG: Nhận registry adapter để service không phụ thuộc model cụ thể.
     * =====================================================================
     * INPUT: registry đã được AppServiceProvider đăng ký. OUTPUT: service dùng chung.
     * SIDE EFFECT: chỉ giữ dependency trong memory. EXCEPTION/TRANSACTION: không mở transaction.
     * =====================================================================
     */
    public function __construct(private readonly AiTaskRunRegistry $registry)
    {
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đăng ký analysis bằng khóa UUID, đồng bộ lần gọi lặp.
     * =====================================================================
     * INPUT: Analysis đã được lưu.
     * OUTPUT: Tracker hiện tại, không reset queued khi đăng ký lại.
     * SIDE EFFECT: Đọc nguồn mới và ghi tracker.
     * EXCEPTION/TRANSACTION: Transaction ngắn; nguồn thiếu trả ModelNotFoundException.
     * =====================================================================
     */

    public function registerAnalysis(AiWritingProfileAnalysis $analysis): AiTaskRun
    {
        return $this->register($analysis);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đăng ký lượt tạo nội dung/ảnh theo UUID và generation.
     * =====================================================================
     * INPUT: AiImport đã lưu, generation_no hiện hành.
     * OUTPUT: Tracker của đúng generation.
     * SIDE EFFECT: Đồng bộ metadata, không dispatch job.
     * EXCEPTION/TRANSACTION: Transaction ngắn; stale generation không tạo tracker mới.
     * =====================================================================
     */

    public function registerImport(AiImport $import): AiTaskRun
    {
        return $this->register($import);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đăng ký bất kỳ model nguồn có adapter trong registry.
     * =====================================================================
     * INPUT: model đã lưu. OUTPUT: tracker ổn định theo dedupe key.
     * SIDE EFFECT: ghi projection trong transaction ngắn. EXCEPTION/TRANSACTION: nguồn thiếu ném RuntimeException.
     * =====================================================================
     */
    public function register(Model $source): AiTaskRun
    {
        return $this->syncFrom($source) ?? throw new \RuntimeException('Nguồn task AI không còn tồn tại.');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đồng bộ analysis từ bản ghi đã đọc lại dưới lock.
     * =====================================================================
     * INPUT: Analysis identity và jobId của queue nếu worker đã nhận job.
     * OUTPUT: Tracker hoặc null khi nguồn đã bị xóa.
     * SIDE EFFECT: Ghi trạng thái/model/nguồn/timestamps và ID draft hiện tại.
     * EXCEPTION/TRANSACTION: Khóa analysis trước tracker; không dùng snapshot lifecycle cũ.
     * =====================================================================
     */

    public function syncFromAnalysis(AiWritingProfileAnalysis $analysis, ?string $jobId = null): ?AiTaskRun
    {
        return $this->syncSource($analysis, $jobId);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đồng bộ đúng generation của content/image run.
     * =====================================================================
     * INPUT: AiImport identity/generation và jobId tùy chọn.
     * OUTPUT: Tracker hoặc null khi nguồn thiếu/stale generation.
     * SIDE EFFECT: Ghi projection lifecycle an toàn.
     * EXCEPTION/TRANSACTION: Khóa nguồn; worker generation cũ không ghi vào lượt mới.
     * =====================================================================
     */

    public function syncFromImport(AiImport $import, ?string $jobId = null): ?AiTaskRun
    {
        return $this->syncFrom($import, $jobId);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đồng bộ model worker bất kỳ qua adapter đã đăng ký.
     * =====================================================================
     * INPUT: source và jobId tùy chọn. OUTPUT: tracker hoặc null khi source stale/missing.
     * SIDE EFFECT: khóa source và ghi projection. EXCEPTION/TRANSACTION: adapter kiểm tra identity.
     * =====================================================================
     */
    public function syncFrom(Model $source, ?string $jobId = null): ?AiTaskRun
    {
        return $this->syncSource($source, $jobId);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đọc nguồn authoritative và ghi tracker trong cùng transaction.
     * =====================================================================
     * INPUT: Nguồn có adapter trong registry; ID queue tùy chọn.
     * OUTPUT: AiTaskRun mới/cập nhật, null nếu identity không còn phù hợp.
     * SIDE EFFECT: SELECT FOR UPDATE nguồn rồi tracker, UPDATE projection.
     * EXCEPTION/TRANSACTION: Không khóa qua HTTP; gọi lồng trong transaction nghiệp vụ được hỗ trợ.
     * =====================================================================
     */

    private function syncSource(Model $source, ?string $jobId): ?AiTaskRun
    {
        return DB::transaction(function () use ($source, $jobId): ?AiTaskRun {
            $adapter = $this->registry->forSource($source);
            $current = $source->newQuery()->lockForUpdate()->find($source->getKey());
            if (! $current) {
                return null;
            }
            if (! $adapter->matchesSource($source, $current)) {
                return null;
            }

            $existing = AiTaskRun::query()->where('dedupe_key', $adapter->project($current)['dedupe_key'])->first();
            if ($existing && ! $adapter->isCurrent($current, $existing)) {
                return null;
            }

            $attributes = $this->attributes($adapter, $current);
            if ($jobId !== null) {
                $attributes['job_id'] = mb_substr($jobId, 0, 191);
            }

            return $this->persist($attributes);
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo projection allowlist từ nguồn nội bộ.
     * =====================================================================
     * INPUT: Model nguồn đã được khóa và đọc mới.
     * OUTPUT: Mảng field tracker; không gồm bài mẫu, prompt, URL hoặc connection snapshot.
     * SIDE EFFECT: Hàm thuần, chỉ đọc model.
     * EXCEPTION/TRANSACTION: Không mở transaction; caller đang giữ source lock.
     * =====================================================================
     */

    private function attributes(AiTaskRunAdapter $adapter, Model $source): array
    {
        $attributes = $adapter->project($source);
        $attributes['model'] = isset($attributes['model']) ? mb_substr((string) $attributes['model'], 0, 191) : null;
        $attributes['provider'] = isset($attributes['provider']) ? mb_substr((string) $attributes['provider'], 0, 100) : null;
        $attributes['progress'] = max(0, min(100, (int) ($attributes['progress'] ?? 0)));
        $attributes['metadata_json'] = (array) ($attributes['metadata_json'] ?? []);

        return $attributes;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Ghi tracker idempotent theo unique dedupe_key.
     * =====================================================================
     * INPUT: Projection allowlist từ nguồn đang khóa.
     * OUTPUT: Tracker persisted với UUID ổn định.
     * SIDE EFFECT: createOrFirst xử lý unique race, sau đó lock/update dòng tracker.
     * EXCEPTION/TRANSACTION: Caller giữ transaction và source lock; không reset trạng thái từ default.
     * =====================================================================
     */

    private function persist(array $attributes): AiTaskRun
    {
        $key = ['dedupe_key' => $attributes['dedupe_key']];
        $task = AiTaskRun::query()->createOrFirst($key, $attributes);
        $task = AiTaskRun::query()->lockForUpdate()->findOrFail($task->id);
        $task->fill($attributes)->save();

        return $task;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Bắt đầu tracker nếu task vẫn active.
     * =====================================================================
     * INPUT: Tracker và progress 0–99.
     * OUTPUT: Tracker mới đọc lại; terminal không thay đổi.
     * SIDE EFFECT: Conditional UPDATE status/progress/started_at.
     * EXCEPTION/TRANSACTION: Điều kiện trên DB chặn stale writer; không đổi nguồn nghiệp vụ.
     * =====================================================================
     */

    public function start(AiTaskRun $task, int $progress = 1, string $status = 'processing'): AiTaskRun
    {
        abort_unless(in_array($status, self::ACTIVE_STATUSES, true), 422);

        return $this->sync($task, $status, min(99, $progress));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Cập nhật tracker trực tiếp, giữ terminal thắng response đến trễ.
     * =====================================================================
     * INPUT: Tracker, status allowlist, progress và lỗi an toàn tùy chọn.
     * OUTPUT: Tracker fresh, terminal đã có không bị ghi đè.
     * SIDE EFFECT: Conditional UPDATE chỉ các dòng active; không ghi nguồn.
     * EXCEPTION/TRANSACTION: Không mở transaction; SQL điều kiện bảo vệ cạnh tranh atomic.
     * =====================================================================
     */

    public function sync(AiTaskRun $task, string $status, int $progress, ?string $errorCode = null, ?string $errorMessage = null): AiTaskRun
    {
        abort_unless(in_array($status, [...self::ACTIVE_STATUSES, ...self::TERMINAL_STATUSES], true), 422);
        AiTaskRun::query()->whereKey($task->id)->whereIn('status', self::ACTIVE_STATUSES)->update([
            'status' => $status, 'progress' => max(0, min(100, $progress)),
            'started_at' => $task->started_at ?? ($status === 'queued' ? null : now()),
            'completed_at' => in_array($status, self::TERMINAL_STATUSES, true) ? now() : null,
            'error_code' => $status === 'failed' && $errorCode ? mb_substr($errorCode, 0, 120) : null,
            'error_message' => $status === 'failed'
                ? AiPublicErrorMessage::message($errorCode, $errorMessage)
                : null,
            'updated_at' => now(),
        ]);

        return $task->refresh();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Scope tracker theo owner và quyền module hiện tại.
     * =====================================================================
     * INPUT: User admin đã xác thực.
     * OUTPUT: Builder lọc analysis/manage, content/target permission và image/media.upload.
     * SIDE EFFECT: Chỉ dựng query; không load source quan hệ.
     * EXCEPTION/TRANSACTION: Permission hiện tại quyết định cả list/detail/cancel; không chỉ dựa token.
     * =====================================================================
     */

    public function visibleTo(User $actor): Builder
    {
        return AiTaskRun::query()->where('user_id', $actor->getKey())
            ->where(function (Builder $query) use ($actor): void {
                $query->whereRaw('1 = 0');
                foreach ($this->registry->all() as $adapter) {
                    $adapter->applyVisibility($query, $actor);
                }
            });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm actor có ít nhất một module dùng queue.
     * =====================================================================
     * INPUT: User đã xác thực.
     * OUTPUT: Boolean theo ai_settings.manage/media.upload/quyền target registry.
     * SIDE EFFECT: Chỉ đọc quyền/config.
     * EXCEPTION/TRANSACTION: Không mở transaction hoặc truy cập dữ liệu task.
     * =====================================================================
     */

    public function canUse(User $actor): bool
    {
        return collect($this->registry->all())->contains(fn (AiTaskRunAdapter $adapter) => $adapter->canUse($actor));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đối chiếu tracker với nguồn sau bulk update, hết hạn hoặc cleanup.
     * =====================================================================
     * INPUT: Tracker đã scope owner/quyền.
     * OUTPUT: Tracker fresh; nguồn thiếu/stale không mở được và active chuyển expired.
     * SIDE EFFECT: Đọc/lock nguồn, cập nhật tracker nếu cần.
     * EXCEPTION/TRANSACTION: Khóa nguồn trước tracker; old generation không đồng bộ từ generation mới.
     * =====================================================================
     */

    public function reconcile(AiTaskRun $task): AiTaskRun
    {
        return DB::transaction(function () use ($task): AiTaskRun {
            try {
                $adapter = $this->registry->forTaskableType($task->taskable_type);
            } catch (\LogicException) {
                $adapter = null;
            }
            $source = $adapter ? $task->taskable_type::query()->lockForUpdate()->find($task->taskable_id) : null;
            if ($source && $adapter->isCurrent($source, $task)) {
                return $this->persist($this->attributes($adapter, $source));
            }

            $current = AiTaskRun::query()->lockForUpdate()->findOrFail($task->id);
            $current->forceFill([
                'expires_at' => now(),
                'status' => in_array($current->status, self::ACTIVE_STATUSES, true) ? 'expired' : $current->status,
                'completed_at' => $current->completed_at ?? now(),
            ])->save();

            return $current;
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đối chiếu mọi task active của actor, kể cả ngoài trang lịch sử đầu tiên.
     * =====================================================================
     * INPUT: Actor hiện tại.
     * OUTPUT: Void; active_count phản ánh source mới nhất.
     * SIDE EFFECT: Đọc tracker chunk 100 và reconcile nguồn; không gọi provider.
     * EXCEPTION/TRANSACTION: Mỗi nguồn một transaction ngắn; scope permission trước khi ghi.
     * =====================================================================
     */

    public function reconcileActive(User $actor): void
    {
        $this->visibleTo($actor)->whereIn('status', self::ACTIVE_STATUSES)
            ->chunkById(100, function ($tasks): void {
                foreach ($tasks as $task) {
                    $this->reconcile($task);
                }
            });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Hủy đúng nguồn/generation sau kiểm quyền dưới lock.
     * =====================================================================
     * INPUT: Tracker identity và actor đã xác thực.
     * OUTPUT: Tracker cancelled hoặc trạng thái terminal thực của nguồn.
     * SIDE EFFECT: Transaction ghi source/tracker; ảnh thumbnail được sync sau commit.
     * EXCEPTION/TRANSACTION: 404 sai owner, 403 mất quyền, 409 stale generation; completion thắng trả ready.
     * =====================================================================
     */

    public function cancel(AiTaskRun $task, User $actor): AiTaskRun
    {
        abort_unless((int) $task->user_id === (int) $actor->getKey(), 404);
        abort_unless($this->visibleTo($actor)->whereKey($task->id)->exists(), 403);
        [$cancelled, $source] = DB::transaction(function () use ($task): array {
            try {
                $adapter = $this->registry->forTaskableType($task->taskable_type);
            } catch (\LogicException) {
                abort(409, 'Task type chưa được hỗ trợ.');
            }
            $source = $task->taskable_type::query()->lockForUpdate()->find($task->taskable_id);
            abort_unless($source, 410, 'Nguồn của tác vụ không còn tồn tại.');
            abort_unless($adapter->isCurrent($source, $task), 409, 'Tác vụ đã có lượt chạy mới.');
            $adapter->cancel($source);

            return [$this->persist($this->attributes($adapter, $source)), $source];
        });
        $adapter = $this->registry->forSource($source);
        if ($cancelled->status === 'cancelled') {
            $adapter->afterCancel($source);
        }

        return $cancelled;
    }
}
