<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Tạo bảng visual_style_shots chứa Shot Recipe của từng style.
 * =====================================================================
 * Một Visual Style (ai_image_profiles) có nhiều Shot Recipe: mỗi recipe mô tả
 * một góc chụp/bố cục cụ thể và chỉ override các field composition/camera cần
 * đổi so với DNA gốc; field trống nghĩa là giữ nguyên DNA. Recipes cho phép
 * một batch ảnh giữ DNA đồng nhất nhưng khác góc chụp.
 * CÁC HÀM/METHOD TRONG FILE:
 * - up(): tạo bảng với FK cascade, unique (visual_style_id, code) và index sort.
 * - down(): drop bảng visual_style_shots.
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : schema builder của Laravel.
 * - OUTPUT: bảng visual_style_shots với ràng buộc khóa ngoại và index.
 * - SIDE EFFECT: CREATE/DROP TABLE; xóa style cascade xóa luôn shots.
 * =====================================================================
 */
return new class extends Migration
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo bảng visual_style_shots với đầy đủ ràng buộc.
     * =====================================================================
     * Input: không có. Output: bảng mới gồm identity, override JSON, thứ tự, cờ active.
     * Side effect: CREATE TABLE + FK cascadeOnDelete + unique/index; không seed dữ liệu.
     * =====================================================================
     */
    public function up(): void
    {
        Schema::create('visual_style_shots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visual_style_id')->constrained('ai_image_profiles')->cascadeOnDelete();
            $table->string('code', 60);
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->json('composition_override_json')->nullable();
            $table->json('camera_override_json')->nullable();
            $table->text('prompt_suffix')->nullable();
            $table->string('suggested_aspect_ratio', 16)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['visual_style_id', 'code']);
            $table->index(['visual_style_id', 'sort_order']);
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Drop bảng visual_style_shots khi rollback.
     * =====================================================================
     * Input: không có. Output: schema về trạng thái trước migration.
     * Side effect: DROP TABLE; mọi shot recipe của các style bị xóa theo.
     * =====================================================================
     */
    public function down(): void
    {
        Schema::dropIfExists('visual_style_shots');
    }
};
