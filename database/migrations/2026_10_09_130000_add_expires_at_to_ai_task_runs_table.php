<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Bổ sung thời điểm hết hạn cho tracker đã được tạo trước đó.
     * =====================================================================
     * INPUT: bảng ai_task_runs đã tồn tại nhưng có thể thiếu expires_at.
     * OUTPUT: tracker có cột expires_at và index phục vụ reconcile/filter.
     * SIDE EFFECT: thay đổi schema, không thay đổi dữ liệu nguồn nghiệp vụ.
     * EXCEPTION/TRANSACTION: migration runner quản lý DDL; kiểm tra schema giúp chạy an toàn.
     * CÁC HÀM/METHOD TRONG FILE: up(): thêm cột/index nếu thiếu; down(): gỡ index/cột khi rollback.
     * =====================================================================
     */
    public function up(): void
    {
        if (! Schema::hasTable('ai_task_runs') || Schema::hasColumn('ai_task_runs', 'expires_at')) {
            return;
        }

        Schema::table('ai_task_runs', function (Blueprint $table): void {
            $table->timestamp('expires_at')->nullable()->index();
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Rollback phần schema bổ sung cho tracker.
     * =====================================================================
     * INPUT: bảng ai_task_runs có thể đã bị xóa hoặc cột đã có sẵn.
     * OUTPUT: cột/index expires_at được gỡ nếu migration này đã thêm.
     * SIDE EFFECT: thay đổi schema tracker, không xóa taskable source.
     * EXCEPTION/TRANSACTION: migration runner quản lý DDL; bỏ qua khi schema không còn.
     * =====================================================================
     */
    public function down(): void
    {
        if (! Schema::hasTable('ai_task_runs') || ! Schema::hasColumn('ai_task_runs', 'expires_at')) {
            return;
        }

        Schema::table('ai_task_runs', function (Blueprint $table): void {
            $table->dropIndex(['expires_at']);
            $table->dropColumn('expires_at');
        });
    }
};
