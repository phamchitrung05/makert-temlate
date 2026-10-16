<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Tạo Social Post canonical và các publication target.
 * =====================================================================
 * Social Post dùng bigint theo Blog Post/MediaAsset usage, giữ một caption
 * canonical và tách trạng thái generation/review/publication. Không có
 * platform_caption ở bất kỳ bảng nào.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - up(): tạo social_posts, targets, publications và versions.
 * - down(): rollback bảng con trước bảng Social Post.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : schema đã có campaigns/products/social_accounts/users.
 * - OUTPUT: canonical Social Post và lịch sử target/publication/version.
 * - SIDE EFFECT: tạo/xóa bảng; không gọi AI hoặc publisher.
 * =====================================================================
 */
return new class extends Migration
{
    /**
     * Tạo các bảng Social Post và quan hệ target/publication/version.
     *
     * Input: schema product/campaign/account và users.
     * Output: social_posts cùng FK, unique idempotency và các index lifecycle.
     * Side effect: ghi DDL vào database; chưa tạo publication job.
     */
    public function up(): void
    {
        Schema::create('social_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->nullable()->constrained('campaigns')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->longText('caption')->nullable();
            $table->string('generation_status', 24)->default('pending')->index();
            $table->string('review_status', 24)->default('draft')->index();
            $table->string('publication_status', 24)->default('pending')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['campaign_id', 'review_status', 'created_at'], 'social_posts_campaign_review_index');
            $table->index(['product_id', 'review_status', 'created_at'], 'social_posts_product_review_index');
            $table->index(['publication_status', 'scheduled_at'], 'social_posts_publication_schedule_index');
        });

        Schema::create('social_post_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('social_post_id')->constrained('social_posts')->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained('social_accounts')->restrictOnDelete();
            $table->string('platform', 32)->default('facebook');
            $table->boolean('enabled')->default(true);
            $table->string('status', 24)->default('pending')->index();
            $table->timestamp('published_at')->nullable();
            $table->string('last_error_code', 120)->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamps();

            $table->unique(['social_post_id', 'social_account_id'], 'social_post_targets_unique');
            $table->index(['social_post_id', 'enabled', 'status'], 'social_post_targets_post_status_index');
        });

        Schema::create('social_publications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('social_post_target_id')->constrained('social_post_targets')->cascadeOnDelete();
            $table->string('idempotency_key', 191)->unique();
            $table->string('status', 24)->default('pending')->index();
            $table->string('provider_request_id', 191)->nullable()->index();
            $table->string('external_post_id', 191)->nullable()->index();
            $table->text('external_post_url')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->json('response_metadata_json')->nullable();
            $table->string('error_code', 120)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['social_post_target_id', 'status', 'created_at'], 'social_publications_target_status_index');
        });

        Schema::create('social_post_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('social_post_id')->constrained('social_posts')->cascadeOnDelete();
            $table->unsignedInteger('version_no');
            $table->longText('caption')->nullable();
            $table->json('context_json')->nullable();
            $table->json('asset_ids_json')->nullable();
            $table->json('prompt_snapshot_json')->nullable();
            $table->json('writing_profile_snapshot_json')->nullable();
            $table->json('provider_snapshot_json')->nullable();
            $table->char('version_hash', 64)->nullable();
            $table->string('reason', 80)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['social_post_id', 'version_no'], 'social_post_versions_unique');
            $table->index(['social_post_id', 'created_at'], 'social_post_versions_history_index');
        });
    }

    /**
     * Rollback bảng phụ thuộc theo thứ tự publication/target/version trước post.
     *
     * Input: schema có thể đã rollback một phần.
     * Output: toàn bộ Social Post domain trong migration được xóa.
     * Side effect: xóa dữ liệu post/publication/version khi rollback migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_post_versions');
        Schema::dropIfExists('social_publications');
        Schema::dropIfExists('social_post_targets');
        Schema::dropIfExists('social_posts');
    }
};
