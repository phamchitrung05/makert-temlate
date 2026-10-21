<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Mở rộng ai_image_profiles thành Visual Style có DNA có cấu trúc.
 * =====================================================================
 * Bảng ai_image_profiles được tái dùng làm bảng Visual Style Engine (VSE-1):
 * giữ các cột prompt/negative_prompt hiện có làm rendered cache, thêm dna_json
 * chứa Style DNA đã validate, schema_version để biết format DNA, version đếm
 * số lần chỉnh sửa DNA và category phân nhóm phong cách.
 * CÁC HÀM/METHOD TRONG FILE:
 * - up(): thêm dna_json, schema_version, version, category vào ai_image_profiles.
 * - down(): gỡ đúng các cột đã thêm, không đụng cột gốc của bảng.
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : schema builder của Laravel.
 * - OUTPUT: cột mới trên ai_image_profiles; rollback sạch khi down().
 * - SIDE EFFECT: ALTER TABLE; không di chuyển hay xóa dữ liệu hiện có.
 * =====================================================================
 */
return new class extends Migration
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Thêm cột Visual Style DNA vào ai_image_profiles.
     * =====================================================================
     * Input: không có. Output: schema có thêm dna_json/schema_version/version/category.
     * Side effect: ALTER TABLE ai_image_profiles; mọi cột mới nullable/default an toàn.
     * =====================================================================
     */
    public function up(): void
    {
        Schema::table('ai_image_profiles', function (Blueprint $table): void {
            $table->json('dna_json')->nullable()->after('negative_prompt');
            $table->string('schema_version', 8)->nullable()->after('dna_json');
            $table->unsignedInteger('version')->default(1)->after('schema_version');
            $table->string('category', 80)->nullable()->after('version');
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Gỡ đúng các cột Visual Style đã thêm ở up().
     * =====================================================================
     * Input: không có. Output: schema về trạng thái trước migration.
     * Side effect: ALTER TABLE ai_image_profiles; dữ liệu DNA/version bị mất.
     * =====================================================================
     */
    public function down(): void
    {
        Schema::table('ai_image_profiles', function (Blueprint $table): void {
            $table->dropColumn(['dna_json', 'schema_version', 'version', 'category']);
        });
    }
};
