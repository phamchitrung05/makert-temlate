<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo bảng token API của Laravel Sanctum
     * =====================================================================
     *
     * Bảng lưu token hash của User hoặc Customer qua quan hệ tokenable
     * polymorphic. Plain text token chỉ được trả đúng một lần khi endpoint
     * tạo token chạy; không lưu plain text trong database.
     *
     * OUTPUT:
     * - Bảng personal_access_tokens với unique token hash và expiry index
     * =====================================================================
     */
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Rollback bảng token API của Sanctum
     * =====================================================================
     *
     * SIDE EFFECT:
     * - Xóa personal_access_tokens và toàn bộ token đã cấp trên database hiện tại
     * =====================================================================
     */
    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
