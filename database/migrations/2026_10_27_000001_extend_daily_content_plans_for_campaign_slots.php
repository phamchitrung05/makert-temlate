<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Mở rộng daily_content_plans gắn slot bài vào campaign.
 * =====================================================================
 * Bổ sung trục nội dung (pillar/topic/keywords/angle), ảnh tham chiếu chủ thể,
 * cặp writing/image profile đã ghép và thời điểm đăng dự kiến cho từng slot
 * theo plan SOCIAL_POST_WEEKLY_SCHEDULE_PLAN.
 *
 * CÁC HÀM/METHOD TRONG FILE: up(), down().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : schema daily_content_plans hiện tại.
 * - OUTPUT: thêm 10 cột và 2 FK; rollback gỡ đúng các cột đó.
 * - SIDE EFFECT: DDL ALTER TABLE + foreign keys; không đọc/ghi dữ liệu.
 * =====================================================================
 */
return new class extends Migration
{
    /**
     * Thêm các cột phân bổ nội dung & ghép cặp cho slot.
     *
     * Input: schema builder daily_content_plans.
     * Output: 10 cột + 2 foreign key nullable; không ghi dữ liệu.
     */
    public function up(): void
    {
        Schema::table('daily_content_plans', function (Blueprint $table): void {
            $table->string('pillar_type', 40)->nullable()->after('product_id');
            $table->string('topic_title', 255)->nullable()->after('pillar_type');
            $table->json('keywords_json')->nullable()->after('topic_title');
            $table->text('content_angle')->nullable()->after('keywords_json');
            $table->json('subject_media_ids')->nullable()->after('content_angle');
            $table->text('subject_description')->nullable()->after('subject_media_ids');
            $table->foreignId('writing_profile_id')->nullable()->after('subject_description')
                ->constrained('ai_writing_profiles')->nullOnDelete();
            $table->foreignId('image_profile_id')->nullable()->after('writing_profile_id')
                ->constrained('ai_image_profiles')->nullOnDelete();
            $table->unsignedTinyInteger('image_count')->default(4)->after('image_profile_id');
            $table->timestamp('scheduled_at')->nullable()->after('image_count');

            $table->index(['pillar_type', 'planned_date'], 'daily_content_plans_pillar_date_index');
        });
    }

    /**
     * Gỡ các cột phân bổ nội dung đã thêm.
     *
     * Input: schema builder daily_content_plans.
     * Output: drop foreign keys và cột; không phục hồi dữ liệu.
     */
    public function down(): void
    {
        Schema::table('daily_content_plans', function (Blueprint $table): void {
            $table->dropForeign(['writing_profile_id']);
            $table->dropForeign(['image_profile_id']);
            $table->dropIndex('daily_content_plans_pillar_date_index');
            $table->dropColumn([
                'pillar_type', 'topic_title', 'keywords_json', 'content_angle',
                'subject_media_ids', 'subject_description', 'writing_profile_id',
                'image_profile_id', 'image_count', 'scheduled_at',
            ]);
        });
    }
};
