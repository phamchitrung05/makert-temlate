<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Thêm thời gian chờ HTTP riêng cho từng provider AI.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: up(), down().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): schema hiện tại -> cột request_timeout.
 * SIDE EFFECT: DDL; provider hiện tại nhận mặc định 120 giây, không đổi secret.
 * EXCEPTION/TRANSACTION: Transaction migration do database driver quản lý.
 * =====================================================================
 */
return new class extends Migration
{
    /** Input: schema ai_providers. Output: cột số giây chờ, default 120; chạy DDL. */
    public function up(): void
    {
        Schema::table('ai_providers', function (Blueprint $table): void {
            $table->unsignedSmallInteger('request_timeout')->default(120);
        });
    }

    /** Input: schema đã migrate. Output: bỏ cột thời gian chờ; rollback DDL. */
    public function down(): void
    {
        Schema::table('ai_providers', function (Blueprint $table): void {
            $table->dropColumn('request_timeout');
        });
    }
};
