<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Tạo product và product facts cho Marketing Social Post.
 * =====================================================================
 * Product là nguồn context nghiệp vụ; facts được tách bảng để lưu nguồn,
 * trạng thái xác minh và phiên bản thay vì để AI đọc một JSON không truy vết.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - up(): tạo bảng products và product_facts cùng index/foreign key.
 * - down(): rollback hai bảng theo đúng thứ tự phụ thuộc.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : schema hiện có bảng users.
 * - OUTPUT: schema product/fact sẵn sàng cho Social Post domain.
 * - SIDE EFFECT: tạo/xóa bảng; không gọi AI hoặc provider.
 * =====================================================================
 */
return new class extends Migration
{
    /**
     * Tạo product và các fact có nguồn/xác minh để làm AI context.
     *
     * Input: schema hiện có bảng users.
     * Output: products/product_facts với FK, index và unique version key.
     * Side effect: ghi DDL vào database; không ghi dữ liệu nghiệp vụ.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);
            $table->text('short_description')->nullable();
            $table->text('default_cta')->nullable();
            $table->json('prohibited_claims_json')->nullable();
            $table->string('status', 24)->default('draft')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'created_at'], 'products_status_created_index');
        });

        Schema::create('product_facts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('fact_key', 80);
            $table->text('fact_value');
            $table->string('source', 255);
            $table->boolean('verified')->default(false);
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_allowed_for_ai')->default(true);
            $table->timestamps();

            $table->unique(['product_id', 'fact_key', 'version'], 'product_facts_version_unique');
            $table->index(['product_id', 'is_allowed_for_ai'], 'product_facts_ai_index');
            $table->index(['product_id', 'verified'], 'product_facts_verified_index');
        });
    }

    /**
     * Xóa product facts trước product để tôn trọng foreign key.
     *
     * Input: schema có thể đã rollback một phần.
     * Output: products/product_facts không còn tồn tại.
     * Side effect: xóa dữ liệu product domain khi rollback migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_facts');
        Schema::dropIfExists('products');
    }
};
