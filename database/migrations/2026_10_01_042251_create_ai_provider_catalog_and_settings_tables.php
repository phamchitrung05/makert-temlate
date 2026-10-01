<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Tạo connection/catalog/settings có unique và foreign keys.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: up(), down().
 * INPUT: schema hiện tại.
 * OUTPUT: bảng provider/model/settings và ràng buộc dữ liệu.
 * SIDE EFFECT: DDL; rollback sẽ mất dữ liệu các bảng mới.
 * EXCEPTION/TRANSACTION: Migration transaction thuộc database driver.
 * =====================================================================
 */
return new class extends Migration
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo các bảng catalog/settings cho Phase 16.
     * =====================================================================
     * INPUT: Không có.
     * OUTPUT: Schema đã migrate.
     * SIDE EFFECT: DDL create tables/indexes/foreign keys.
     * EXCEPTION/TRANSACTION: Lỗi schema do database driver; caller quản lý transaction.
     * =====================================================================
     */
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 80)->unique();
            $table->string('name', 120);
            $table->string('kind', 20);
            $table->string('driver', 40);
            $table->string('base_url', 2048);
            /**
             * =====================================================================
             * GHI CHÚ: Ciphertext của encrypted cast cần cột TEXT thay vì VARCHAR.
             * =====================================================================
             */
            $table->text('api_key');
            $table->string('discovery_mode', 30)->default('models_endpoint');
            $table->boolean('is_active')->default(true);
            $table->string('test_status', 20)->default('untested');
            $table->string('test_message', 500)->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
        Schema::create('ai_models', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_provider_id')->constrained()->restrictOnDelete();
            $table->string('remote_model_id', 190);
            $table->string('label', 190);
            $table->json('capabilities');
            $table->string('capability_source', 20)->default('unknown');
            $table->string('discovery_source', 20)->default('remote');
            $table->json('metadata')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_available')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['ai_provider_id', 'remote_model_id']);
        });
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('group', 80);
            $table->string('key', 120);
            $table->string('type', 30);
            $table->json('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['group', 'key']);
        });
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Rollback catalog provider/model và bảng settings.
     * =====================================================================
     * INPUT: Schema Phase 16 đã được tạo.
     * OUTPUT: Các bảng mới được xóa theo thứ tự foreign key an toàn.
     * SIDE EFFECT: DDL drop tables; dữ liệu trong các bảng mới không được giữ lại.
     * EXCEPTION/TRANSACTION: Lỗi schema do database driver; caller quản lý transaction.
     * =====================================================================
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('ai_models');
        Schema::dropIfExists('ai_providers');
    }
};
