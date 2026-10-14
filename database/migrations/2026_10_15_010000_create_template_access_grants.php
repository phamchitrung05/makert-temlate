<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Lưu capability share/grant ghim ResourceVersion, token hash
 * CÁC HÀM/METHOD TRONG FILE: up(), down(): tạo/gỡ schema access.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): schema version/download -> bảng token hash.
 * =====================================================================
 */
return new class extends Migration
{
    /** Input: schema hiện có. Output: share/grant và download idempotency key. */
    public function up(): void
    {
        Schema::create('template_share_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->char('token_hash', 64)->unique();
            $table->foreignId('resource_version_id')->constrained()->restrictOnDelete();
            $table->string('capability', 16);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('template_access_grants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->char('token_hash', 64)->unique();
            $table->foreignId('resource_version_id')->constrained()->restrictOnDelete();
            $table->uuid('processing_token');
            $table->string('capability', 16);
            $table->string('audience', 16);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('share_link_id')->nullable()->constrained('template_share_links')->restrictOnDelete();
            $table->foreignId('download_id')->nullable()->constrained('downloads')->restrictOnDelete();
            $table->char('ip_hash', 64)->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
        Schema::table('downloads', function (Blueprint $table): void {
            $table->uuid('request_key')->nullable()->unique();
        });
    }

    /** Input: schema access. Output: rollback bỏ grant trước share, giữ downloads. */
    public function down(): void
    {
        Schema::dropIfExists('template_access_grants');
        Schema::dropIfExists('template_share_links');
        Schema::table('downloads', function (Blueprint $table): void {
            $table->dropUnique(['request_key']);
            $table->dropColumn('request_key');
        });
    }
};
