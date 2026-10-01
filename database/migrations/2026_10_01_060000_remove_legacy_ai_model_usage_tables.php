<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Dọn các bảng telemetry AI đã hoãn khỏi database cũ.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: up(), down().
 * INPUT: Schema có thể đã tạo bảng ai_model_usage hoặc ai_model_usage_daily.
 * OUTPUT: Database chỉ còn catalog provider/model và settings cần thiết.
 * SIDE EFFECT: DDL drop các bảng telemetry AI nếu chúng tồn tại.
 * EXCEPTION/TRANSACTION: Dữ liệu telemetry bị xóa và không thể phục hồi tự động.
 * =====================================================================
 */
return new class extends Migration
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Xóa schema telemetry AI không còn dùng trong Phase 16.
     * =====================================================================
     * INPUT: Schema hiện tại.
     * OUTPUT: Không còn ai_model_usage_daily và ai_model_usage.
     * SIDE EFFECT: Drop bảng con trước bảng gốc để tôn trọng foreign key.
     * EXCEPTION/TRANSACTION: Migration fail nếu database driver từ chối DDL.
     * =====================================================================
     */
    public function up(): void
    {
        Schema::dropIfExists('ai_model_usage_daily');
        Schema::dropIfExists('ai_model_usage');
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Ghi nhận giới hạn rollback của migration dọn telemetry.
     * =====================================================================
     * INPUT: Không có schema telemetry cũ để suy ra đầy đủ cấu trúc và dữ liệu.
     * OUTPUT: Không tạo lại bảng đã xóa.
     * SIDE EFFECT: Không thực hiện DDL; rollback không phục hồi dữ liệu telemetry.
     * EXCEPTION/TRANSACTION: Không phát sinh exception.
     * =====================================================================
     */
    public function down(): void
    {
        // Telemetry schema đã hoãn; không thể khôi phục an toàn từ migration này.
    }
};
