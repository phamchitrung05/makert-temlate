<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Tạo bảng owner nghiệp vụ cho Media Library
 * =====================================================================
 *
 * Bảng này lưu metadata nghiệp vụ của asset; file vật lý và metadata xử lý
 * của Spatie vẫn nằm trong bảng `media`. Bảng usage polymorphic sẽ được thêm
 * ở Task 3 nên migration này chỉ phụ thuộc vào `users`.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - up(): tạo bảng media_assets với enum contract, FK và index
 * - down(): xóa bảng media_assets khi rollback
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : schema hiện có bảng `users`
 * - OUTPUT: bảng `media_assets` sẵn sàng cho model MediaAsset
 * =====================================================================
 */
return new class extends Migration
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo schema metadata nghiệp vụ của MediaAsset
     * =====================================================================
     *
     * OUTPUT:
     * - Bảng media_assets với FK created_by, index filter và soft deletes
     *
     * SIDE EFFECT:
     * - Tạo bảng và index trong database hiện tại
     * =====================================================================
     */
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 32)->index();
            $table->string('title');
            $table->text('alt_text')->nullable();
            $table->string('visibility', 16)->default('private')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Rollback schema metadata của MediaAsset
     * =====================================================================
     *
     * OUTPUT:
     * - Bảng media_assets không còn tồn tại
     *
     * SIDE EFFECT:
     * - Xóa dữ liệu nghiệp vụ MediaAsset; không xóa bảng `media`
     * =====================================================================
     */
    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
