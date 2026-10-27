<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Mở rộng bảng campaigns lưu trọn cấu hình wizard 5 bước.
 * =====================================================================
 * Bổ sung các cột JSON cho Pool văn phong, Pool visual style, ảnh tham chiếu
 * chủ thể, từ khóa chiến dịch, nền tảng áp dụng và cấu hình tần suất/nâng cao
 * theo plan SOCIAL_POST_WEEKLY_SCHEDULE_PLAN.
 *
 * CÁC HÀM/METHOD TRONG FILE: up(), down().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : schema campaigns hiện tại.
 * - OUTPUT: thêm 9 cột cấu hình chiến dịch; rollback gỡ đúng các cột đó.
 * - SIDE EFFECT: DDL ALTER TABLE; không đọc/ghi dữ liệu.
 * =====================================================================
 */
return new class extends Migration
{
    /**
     * Thêm các cột cấu hình wizard chiến dịch.
     *
     * Input: schema builder campaigns.
     * Output: 9 cột nullable được thêm; không ghi dữ liệu.
     */
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->decimal('budget', 15, 2)->nullable()->after('message');
            $table->json('keywords_json')->nullable()->after('budget');
            $table->json('writing_profile_ids')->nullable()->after('keywords_json');
            $table->json('image_profile_ids')->nullable()->after('writing_profile_ids');
            $table->json('subject_media_ids')->nullable()->after('image_profile_ids');
            $table->text('subject_description')->nullable()->after('subject_media_ids');
            $table->json('platforms_json')->nullable()->after('subject_description');
            $table->json('schedule_config_json')->nullable()->after('platforms_json');
            $table->json('advanced_config_json')->nullable()->after('schedule_config_json');
        });
    }

    /**
     * Gỡ các cột cấu hình wizard đã thêm.
     *
     * Input: schema builder campaigns.
     * Output: 9 cột bị drop; không phục hồi dữ liệu.
     */
    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropColumn([
                'budget', 'keywords_json', 'writing_profile_ids', 'image_profile_ids',
                'subject_media_ids', 'subject_description', 'platforms_json',
                'schedule_config_json', 'advanced_config_json',
            ]);
        });
    }
};
