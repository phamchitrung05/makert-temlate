<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Cho phép AI import nhận nội dung text inline an toàn.
 * =====================================================================
 *
 * URL vẫn là nguồn tương thích ngược; text được lưu riêng để không nhồi dữ
 * liệu lớn vào input_json và để worker không phải gọi HTTP khi nguồn inline.
 * source_url giữ NOT NULL vì SQLite production/test có thể không hỗ trợ đổi
 * nullability ổn định; run text dùng chuỗi rỗng và source_type để phân biệt.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - up(): thêm source_text nullable; source_url giữ NOT NULL tương thích.
 * - down(): xóa source_text khi rollback migration.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : bảng ai_imports đã có lifecycle và lineage.
 * - OUTPUT: schema hỗ trợ source URL hoặc text.
 * - SIDE EFFECT: thay đổi schema; không gọi provider hay ghi candidate.
 * =====================================================================
 */
return new class extends Migration
{
    /**
     * Cho phép nguồn inline và lưu text ngoài JSON lifecycle.
     *
     * Input: schema ai_imports hiện tại.
     * Output: source_text nullable; source_url giữ contract NOT NULL.
     * Side effect: rebuild/alter bảng tùy database driver.
     */
    public function up(): void
    {
        Schema::table('ai_imports', function (Blueprint $table): void {
            $table->longText('source_text')->nullable()->after('source_url');
        });
    }

    /**
     * Xóa cột source text khi rollback.
     *
     * Input: schema đã chạy up().
     * Output: source_text bị xóa; source_url không thay đổi.
     * Side effect: dữ liệu text inline bị loại khi rollback migration.
     */
    public function down(): void
    {
        Schema::table('ai_imports', function (Blueprint $table): void {
            $table->dropColumn('source_text');
        });
    }
};
