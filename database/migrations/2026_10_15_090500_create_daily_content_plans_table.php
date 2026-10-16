<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Tạo slot lập kế hoạch draft Social Post hằng ngày.
 * =====================================================================
 * Mỗi ngày/slot chỉ có một plan trong single workspace để scheduler retry an
 * toàn. Plan chỉ tạo draft; không tự chuyển bài sang publish.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - up(): tạo daily_content_plans và unique planned_date/slot.
 * - down(): xóa bảng daily_content_plans.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : schema đã có users/campaigns/products/social_posts.
 * - OUTPUT: bảng slot có trạng thái scheduler và liên kết draft tùy chọn.
 * - SIDE EFFECT: tạo/xóa bảng; không dispatch job.
 * =====================================================================
 */
return new class extends Migration
{
    /**
     * Tạo bảng slot idempotent cho daily planner.
     *
     * Input: schema có users/campaigns/products/social_posts.
     * Output: daily_content_plans với unique ngày/slot và lifecycle fields.
     * Side effect: ghi DDL vào database.
     */
    public function up(): void
    {
        Schema::create('daily_content_plans', function (Blueprint $table): void {
            $table->id();
            $table->date('planned_date');
            $table->unsignedTinyInteger('slot');
            $table->string('timezone', 64)->default('Asia/Ho_Chi_Minh');
            $table->foreignId('campaign_id')->nullable()->constrained('campaigns')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('social_post_id')->nullable()->constrained('social_posts')->nullOnDelete();
            $table->unsignedTinyInteger('target_draft_count')->default(1);
            $table->string('status', 24)->default('pending')->index();
            $table->string('error_code', 120)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['planned_date', 'slot'], 'daily_content_plans_slot_unique');
            $table->index(['planned_date', 'status'], 'daily_content_plans_date_status_index');
            $table->index(['campaign_id', 'planned_date'], 'daily_content_plans_campaign_index');
        });
    }

    /**
     * Rollback bảng daily plan.
     *
     * Input: schema có thể đã rollback một phần.
     * Output: daily_content_plans không còn tồn tại.
     * Side effect: xóa lịch sử slot scheduler khi rollback migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_content_plans');
    }
};
