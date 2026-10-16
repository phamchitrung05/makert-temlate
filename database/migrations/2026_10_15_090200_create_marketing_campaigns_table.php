<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Tạo campaign và liên kết campaign/product Marketing.
 * =====================================================================
 * Campaign lưu mục tiêu/thông điệp và khoảng thời gian; pivot cho phép một
 * product tham gia nhiều campaign mà không tạo dữ liệu product trùng lặp.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - up(): tạo campaigns và campaign_products.
 * - down(): rollback pivot rồi campaign.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : schema đã có products và users.
 * - OUTPUT: campaign domain có pivot product và index filter.
 * - SIDE EFFECT: tạo/xóa bảng; không gọi scheduler/provider.
 * =====================================================================
 */
return new class extends Migration
{
    /**
     * Tạo campaign và quan hệ nhiều-nhiều với product.
     *
     * Input: schema hiện có products/users.
     * Output: campaigns/campaign_products có FK và unique pivot.
     * Side effect: ghi DDL vào database.
     */
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);
            $table->text('objective')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('timezone', 64)->default('Asia/Ho_Chi_Minh');
            $table->string('status', 24)->default('draft')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'starts_at', 'ends_at'], 'campaigns_schedule_index');
        });

        Schema::create('campaign_products', function (Blueprint $table): void {
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['campaign_id', 'product_id'], 'campaign_products_unique');
            $table->index(['campaign_id', 'sort_order'], 'campaign_products_order_index');
        });
    }

    /**
     * Rollback pivot trước campaign để không vi phạm foreign key.
     *
     * Input: schema có thể đã rollback một phần.
     * Output: campaigns/campaign_products không còn tồn tại.
     * Side effect: xóa dữ liệu campaign domain khi rollback migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('campaign_products');
        Schema::dropIfExists('campaigns');
    }
};
