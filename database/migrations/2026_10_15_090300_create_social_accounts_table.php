<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Tạo bảng kết nối tài khoản/page Social Post.
 * =====================================================================
 * V1 dùng Facebook Page; token được model mã hóa bằng encrypted cast ở phase
 * account flow. Bảng này chỉ lưu metadata bounded và không làm OAuth.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - up(): tạo social_accounts và index nhận diện account.
 * - down(): xóa bảng social_accounts.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : schema hiện có bảng users.
 * - OUTPUT: bảng account/page để target có thể tham chiếu bằng FK.
 * - SIDE EFFECT: tạo/xóa bảng; không gọi Meta API.
 * =====================================================================
 */
return new class extends Migration
{
    /**
     * Tạo metadata account/page và cột token mã hóa server-side.
     *
     * Input: schema hiện có users.
     * Output: social_accounts có unique provider/type/external ID và health fields.
     * Side effect: ghi DDL; token chưa được tạo bởi migration.
     */
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32)->default('facebook');
            $table->string('account_type', 32)->default('page');
            $table->string('external_id', 191);
            $table->string('display_name', 160);
            $table->text('access_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('scopes_json')->nullable();
            $table->string('status', 24)->default('disconnected')->index();
            $table->json('metadata_json')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['provider', 'account_type', 'external_id'], 'social_accounts_identity_unique');
            $table->index(['provider', 'account_type', 'status'], 'social_accounts_provider_type_status_index');
        });
    }

    /**
     * Rollback metadata account/page.
     *
     * Input: schema có thể đã rollback một phần.
     * Output: social_accounts không còn tồn tại.
     * Side effect: xóa metadata/token đã mã hóa khi rollback migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
