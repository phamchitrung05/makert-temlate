<?php

namespace App\Services\Ai\Runs;

use App\Services\Ai\Runs\Contracts\AiTaskRunAdapter;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Registry ánh xạ model nghiệp vụ vào adapter task AI.
 * =====================================================================
 * Registry là composition point cho task type mới. Một worker mới chỉ cần
 * tạo adapter, thêm class vào config/ai-task-runs.php và gọi syncFrom().
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - register(): thêm adapter theo model class.
 * - forSource(): tìm adapter theo model instance.
 * - forTaskableType(): tìm adapter theo taskable class đã lưu.
 * - all(): trả danh sách adapter cho scope quyền và health check.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : adapter đã bind trong service container, model hoặc FQCN.
 * - OUTPUT: adapter đúng identity hoặc lỗi cấu hình rõ ràng.
 * - SIDE EFFECT: giữ danh sách adapter trong memory của request/job.
 * - EXCEPTION/TRANSACTION: adapter trùng class hoặc chưa đăng ký ném LogicException.
 * =====================================================================
 */
final class AiTaskRunRegistry
{
    /** @var array<string, AiTaskRunAdapter> */
    private array $adapters = [];

    /**
     * =====================================================================
     * CHỨC NĂNG: Đăng ký adapter cho một model nguồn.
     * =====================================================================
     * INPUT: adapter có modelClass duy nhất. OUTPUT: registry hiện tại.
     * SIDE EFFECT: ghi map trong memory. EXCEPTION/TRANSACTION: trùng class ném LogicException.
     * =====================================================================
     */
    public function register(AiTaskRunAdapter $adapter): self
    {
        $modelClass = $adapter->modelClass();
        if (isset($this->adapters[$modelClass])) {
            throw new LogicException("Ai task adapter đã đăng ký: {$modelClass}");
        }
        $this->adapters[$modelClass] = $adapter;

        return $this;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy adapter theo instance model hoặc class kế thừa.
     * =====================================================================
     * INPUT: source Eloquent. OUTPUT: adapter tương ứng.
     * SIDE EFFECT: chỉ đọc registry. EXCEPTION/TRANSACTION: chưa đăng ký ném LogicException.
     * =====================================================================
     */
    public function forSource(Model $source): AiTaskRunAdapter
    {
        return $this->forTaskableType($source::class);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy adapter từ taskable_type đã lưu trong tracker.
     * =====================================================================
     * INPUT: FQCN model trong AiTaskRun. OUTPUT: adapter đã đăng ký.
     * SIDE EFFECT: chỉ đọc registry. EXCEPTION/TRANSACTION: type lạ ném LogicException.
     * =====================================================================
     */
    public function forTaskableType(string $modelClass): AiTaskRunAdapter
    {
        foreach ($this->adapters as $registeredClass => $adapter) {
            if ($registeredClass === $modelClass || is_a($modelClass, $registeredClass, true)) {
                return $adapter;
            }
        }

        throw new LogicException("Chưa đăng ký ai task adapter cho {$modelClass}");
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Cung cấp toàn bộ adapter để scope quyền và health check.
     * =====================================================================
     * INPUT: không có. OUTPUT: danh sách adapter đã đăng ký.
     * SIDE EFFECT: trả mảng mới, không cho caller sửa registry nội bộ.
     * EXCEPTION/TRANSACTION: không có.
     * =====================================================================
     */
    public function all(): array
    {
        return array_values($this->adapters);
    }
}
