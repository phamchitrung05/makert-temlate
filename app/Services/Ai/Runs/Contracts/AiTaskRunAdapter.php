<?php

namespace App\Services\Ai\Runs\Contracts;

use App\Models\AiTaskRun;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use App\Models\User;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Hợp đồng adapter cho nguồn task AI chạy bất đồng bộ.
 * =====================================================================
 * Mỗi model nghiệp vụ có worker riêng có thể đăng ký một adapter để mô tả
 * identity, projection, generation và thao tác hủy. Tracker không cần biết
 * schema riêng của model đó hoặc phải thêm một nhánh match mới.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - modelClass(): khai báo model nguồn adapter nhận xử lý.
 * - project(): tạo projection allowlist cho ai_task_runs.
 * - matchesSource(): chặn worker cũ ghi sai generation/identity.
 * - isCurrent(): xác nhận tracker còn trỏ đúng source.
 * - applyVisibility(): đưa quyền module vào query task center.
 * - canUse(): cho biết actor có thể dùng adapter.
 * - cancel(): mutation source dưới transaction/lock của service.
 * - afterCancel(): cleanup domain sau transaction.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Model nguồn đã được đọc dưới lock và tracker hiện tại.
 * - OUTPUT: Projection an toàn cho AiTaskRun và lifecycle hook.
 * - SIDE EFFECT: cancel()/afterCancel() có thể cập nhật nguồn và cleanup.
 * - EXCEPTION/TRANSACTION: caller quản lý transaction; adapter không gọi provider.
 * =====================================================================
 */
interface AiTaskRunAdapter
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Khai báo model class mà adapter nhận xử lý.
     * =====================================================================
     * INPUT: không có. OUTPUT: FQCN của model nguồn.
     * SIDE EFFECT: không ghi DB. EXCEPTION/TRANSACTION: không có.
     * =====================================================================
     */
    public function modelClass(): string;

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo projection allowlist cho tracker dùng chung.
     * =====================================================================
     * INPUT: nguồn nghiệp vụ đã được lock. OUTPUT: field AiTaskRun hợp lệ.
     * SIDE EFFECT: chỉ đọc model. EXCEPTION/TRANSACTION: không mở transaction.
     * =====================================================================
     */
    public function project(Model $source): array;

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra object worker đang giữ có còn đúng generation hiện tại.
     * =====================================================================
     * INPUT: source snapshot từ worker và source mới đọc dưới lock. OUTPUT: true nếu cùng lượt chạy.
     * SIDE EFFECT: không ghi database. EXCEPTION/TRANSACTION: không mở transaction.
     * =====================================================================
     */
    public function matchesSource(Model $requested, Model $current): bool;

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra source có đúng generation/identity của tracker.
     * =====================================================================
     * INPUT: source và tracker. OUTPUT: true nếu worker cũ vẫn còn hợp lệ.
     * SIDE EFFECT: không ghi DB. EXCEPTION/TRANSACTION: không mở transaction.
     * =====================================================================
     */
    public function isCurrent(Model $source, AiTaskRun $task): bool;

    /**
     * =====================================================================
     * CHỨC NĂNG: Thêm điều kiện quyền vào query task center.
     * =====================================================================
     * INPUT: query đang scope owner và actor. OUTPUT: query đã thêm OR branch.
     * SIDE EFFECT: chỉ sửa query builder. EXCEPTION/TRANSACTION: không mở transaction.
     * =====================================================================
     */
    public function applyVisibility(Builder $query, User $actor): void;

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra actor có thể nhìn task type của adapter.
     * =====================================================================
     * INPUT: actor hiện tại. OUTPUT: boolean. SIDE EFFECT: chỉ đọc quyền/config.
     * EXCEPTION/TRANSACTION: không mở transaction.
     * =====================================================================
     */
    public function canUse(User $actor): bool;

    /**
     * =====================================================================
     * CHỨC NĂNG: Ghi trạng thái hủy vào nguồn nghiệp vụ.
     * =====================================================================
     * INPUT: source đang lock. OUTPUT: không trả giá trị.
     * SIDE EFFECT: cập nhật status/error của source. EXCEPTION/TRANSACTION: caller giữ lock.
     * =====================================================================
     */
    public function cancel(Model $source): void;

    /**
     * =====================================================================
     * CHỨC NĂNG: Dọn tài nguyên sau khi source ảnh hoặc tài nguyên bị hủy.
     * =====================================================================
     * INPUT: source đã persist cancelled. OUTPUT: không trả giá trị.
     * SIDE EFFECT: cleanup domain tùy adapter; không gọi provider.
     * EXCEPTION/TRANSACTION: caller gọi sau transaction nếu cần.
     * =====================================================================
     */
    public function afterCancel(Model $source): void;
}
